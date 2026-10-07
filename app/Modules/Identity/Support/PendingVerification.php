<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;

/**
 * Utilisateur en cours d'activation (e-mail ou téléphone pas encore vérifié) : il n'est PAS connecté,
 * seul son identifiant est gardé en session pour poursuivre les vérifications.
 */
final class PendingVerification
{
    private const KEY = 'pending_user_id';

    public static function remember(Request $request, User $user): void
    {
        $request->session()->put(self::KEY, $user->id);
    }

    public static function user(Request $request): ?User
    {
        $id = $request->session()->get(self::KEY);

        return is_string($id) ? User::query()->find($id) : null;
    }

    public static function forget(Request $request): void
    {
        $request->session()->forget(self::KEY);
    }
}
