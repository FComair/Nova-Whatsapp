<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Models\PlatformOrganization;
use App\Modules\Admin\Notifications\AccessRestoredNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * « Déblocage d'accès » (support) : un membre a perdu son appareil de double authentification ET ses codes de secours.
 *
 * Après vérification de l'identité par le support (hors outil : pièce, rappel sur le numéro enregistré), le Super Admin réinitialise
 * la double authentification de la personne et lève son verrouillage. À la prochaine connexion elle devra la reconfigurer (obligatoire
 * pour le propriétaire et les responsables). Option : invalider aussi les sessions ouvertes et imposer un nouveau mot de passe.
 *
 * À appeler APRÈS `SensitiveActionGuard::authorize()` (motif, mot de passe et code du Super Admin).
 */
final class AccessRecovery
{
    /**
     * @return list<object{user_id: string, email: string, locale: string, role: string, has_two_factor: bool}>
     */
    public function members(PlatformOrganization $org): array
    {
        return DB::connection(PlatformAudit::CONNECTION)->select('select * from platform_org_members(?)', [$org->id]);
    }

    /**
     * @throws InvalidArgumentException si la personne n'est pas un membre actif de cette organisation
     */
    public function unblock(PlatformOrganization $org, string $userId, bool $resetPassword, string $adminId, ?string $reason): void
    {
        $member = collect($this->members($org))->firstWhere('user_id', $userId) ?? throw new InvalidArgumentException('Membre inconnu pour cette organisation.');

        // Mot de passe aléatoire inutilisable : invalide toutes les sessions ; la personne choisit le sien via le lien reçu.
        $hash = $resetPassword ? Hash::make(Str::random(64)) : null;

        DB::connection(PlatformAudit::CONNECTION)->select('select platform_unblock_user(?, ?, ?)', [$org->id, $userId, $hash]);

        PlatformAudit::record($adminId, 'user.access_unblocked', $org->id, 'user', $userId, ['two_factor_reset' => true, 'password_reset' => $resetPassword, 'role' => $member->role], $reason);

        Notification::route('mail', $member->email)->notify(new AccessRestoredNotification($resetPassword, $member->locale));

        if ($resetPassword) {
            Password::broker()->sendResetLink(['email' => $member->email]);
        }
    }
}
