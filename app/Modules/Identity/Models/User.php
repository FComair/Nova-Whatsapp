<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Modules\Admin\Services\PlatformSettings;
use App\Modules\Identity\Notifications\ResetPasswordNotification;
use App\Modules\Tenancy\Models\OrgMember;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * Compte de connexion au dashboard client. Un utilisateur peut appartenir à plusieurs organisations (via org_members).
 *
 * @property string $id
 * @property string $email
 * @property string $locale fr|nl|en
 * @property string|null $phone_e164
 * @property string $password
 * @property string $status
 * @property int $failed_login_count
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $phone_verified_at
 * @property Carbon|null $locked_until
 * @property Carbon|null $last_login_at
 * @property Carbon|null $password_changed_at
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 */
#[Fillable(['email', 'password', 'phone_e164', 'locale'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    public const LOCALES = ['fr', 'nl', 'en'];

    /** Les e-mails et notifications sont envoyés dans la langue du compte. */
    public function preferredLocale(): string
    {
        return $this->locale;
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    /**
     * L'étape « e-mail » du parcours d'activation est-elle franchie ? Oui si l'e-mail est vérifié, ou si le Super Admin a coupé cette
     * exigence dans les Paramètres globaux (pas de serveur d'e-mail).
     */
    public function emailStepDone(): bool
    {
        return $this->hasVerifiedEmail() || ! app(PlatformSettings::class)->enabled('email_verification_required');
    }

    public function hasVerifiedPhone(): bool
    {
        return $this->phone_verified_at !== null;
    }

    /**
     * Le compte n'est activé qu'après la vérification de l'e-mail ET du téléphone (flowchart 1A).
     */
    public function isActivated(): bool
    {
        // Le propriétaire (inscription) vérifie son téléphone professionnel ; un praticien invité n'en a pas (lien e-mail).
        return $this->emailStepDone() && ($this->phone_e164 === null || $this->hasVerifiedPhone());
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'password' => 'hashed',
        ];
    }

    /**
     * @return HasMany<OrgMember, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrgMember::class);
    }

    protected static function booted(): void
    {
        // Décision 18 : date du dernier changement et mémoire des 5 derniers mots de passe (empreintes seulement).
        static::saving(function (self $user): void {
            if ($user->isDirty('password') && ! $user->passwordRehashOnly) {
                $user->password_changed_at = now();
            }
        });

        static::saved(function (self $user): void {
            if (($user->wasRecentlyCreated || $user->wasChanged('password')) && ! $user->passwordRehashOnly) {
                PasswordHistory::remember($user);
            }
        });
    }

    /** Vrai pendant une réécriture technique de la MÊME empreinte (nouvel algorithme, fermeture des autres sessions) : ni historique ni date de changement. */
    public bool $passwordRehashOnly = false;

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
