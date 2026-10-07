<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Mémoire des derniers mots de passe d'un utilisateur (empreintes), pour interdire leur réutilisation.
 * Le mot de passe actuel compte parmi les derniers : on conserve `KEEP` empreintes au total.
 */
final class PasswordHistory
{
    public const KEEP = 5;

    public static function remember(User $user): void
    {
        DB::table('password_histories')->insert(['user_id' => $user->id, 'password' => $user->password, 'created_at' => now()]);

        $obsolete = DB::table('password_histories')->where('user_id', $user->id)->orderByDesc('id')->skip(self::KEEP)->take(PHP_INT_MAX)->pluck('id');

        if ($obsolete->isNotEmpty()) {
            DB::table('password_histories')->whereIn('id', $obsolete->all())->delete();
        }
    }

    public static function reused(User $user, #[\SensitiveParameter] string $plain): bool
    {
        return DB::table('password_histories')->where('user_id', $user->id)->pluck('password')->contains(fn (string $hash): bool => Hash::check($plain, $hash));
    }
}
