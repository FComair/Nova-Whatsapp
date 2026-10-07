<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Audit\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\OrgMember;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use SensitiveParameter;

/**
 * Authentification à deux facteurs des clients (décision 18) : application d'authentification (TOTP) et codes de secours.
 *
 * - Obligatoire, sans délai de grâce, pour le propriétaire et les responsables de site (`config nova.security.two_factor_required`).
 * - Le secret est chiffré en base ; les codes de secours ne sont conservés que sous forme d'empreintes et s'utilisent une seule fois.
 * - Un code TOTP déjà accepté ne l'est pas une seconde fois (rejeu).
 * - Non fait : clés matérielles FIDO2/WebAuthn, code par SMS.
 */
final class TwoFactor
{
    public const RECOVERY_CODE_COUNT = 8;

    /** Rôles pour lesquels la double authentification est exigée. */
    public const REQUIRED_ROLES = [OrgMember::ROLE_OWNER, OrgMember::ROLE_SITE_MANAGER];

    public function __construct(private readonly Google2FA $totp) {}

    public function isRequiredFor(?string $role): bool
    {
        return (bool) config('nova.security.two_factor_required') && in_array($role, self::REQUIRED_ROLES, true);
    }

    /** Début de l'activation : génère un secret, non actif tant qu'un code n'a pas été saisi. */
    public function beginSetup(User $user): string
    {
        if ($user->hasTwoFactor()) {
            return (string) $user->two_factor_secret;
        }

        if ($user->two_factor_secret === null) {
            $user->forceFill(['two_factor_secret' => $this->totp->generateSecretKey(32)])->save();
        }

        return (string) $user->two_factor_secret;
    }

    public function provisioningUri(User $user): string
    {
        return $this->totp->getQRCodeUrl((string) config('nova.security.two_factor_issuer'), $user->email, (string) $user->two_factor_secret);
    }

    /** Image du code à scanner, en SVG (données intégrées : le secret ne passe par aucun service tiers). */
    public function qrCode(User $user): string
    {
        $options = new QROptions(['outputType' => QROutputInterface::MARKUP_SVG, 'outputBase64' => true, 'addQuietzone' => true]);

        return (new QRCode($options))->render($this->provisioningUri($user));
    }

    /**
     * Confirme l'activation avec un premier code valide.
     *
     * @return list<string>|null les codes de secours en clair (affichés une seule fois), ou null si le code est faux
     */
    public function confirm(User $user, #[SensitiveParameter] string $code): ?array
    {
        if ($user->hasTwoFactor() || $user->two_factor_secret === null || ! $this->checkTotp($user, $code)) {
            return null;
        }

        $codes = $this->newRecoveryCodes();
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => array_map($this->hash(...), $codes)])->save();
        Audit::record('auth.two_factor.enabled', 'user', actorId: $user->id);

        return $codes;
    }

    /** Vérifie un code TOTP ou un code de secours (consommé). */
    public function verify(User $user, #[SensitiveParameter] string $code): bool
    {
        $code = trim($code);

        if (preg_match('/^\d{6}$/', $code) === 1) {
            return $this->checkTotp($user, $code);
        }

        return $this->consumeRecoveryCode($user, $code);
    }

    /**
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->newRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => array_map($this->hash(...), $codes)])->save();
        Audit::record('auth.two_factor.codes_regenerated', 'user', actorId: $user->id);

        return $codes;
    }

    public function disable(User $user): void
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        Audit::record('auth.two_factor.disabled', 'user', actorId: $user->id);
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return count($user->two_factor_recovery_codes ?? []);
    }

    private function checkTotp(User $user, string $code): bool
    {
        $secret = $user->two_factor_secret;

        if ($secret === null || ! $this->totp->verifyKey($secret, $code, 1)) {
            return false;
        }

        // Un code ne sert qu'une fois (rejeu) : le premier qui le pose l'emporte.
        return Cache::add('2fa-used:'.$user->id.':'.$code, true, 120);
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $hash = $this->hash($code);
        $codes = $user->two_factor_recovery_codes ?? [];

        foreach ($codes as $index => $stored) {
            if (hash_equals($stored, $hash)) {
                unset($codes[$index]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();
                Audit::record('auth.two_factor.recovery_code_used', 'user', actorId: $user->id);

                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function newRecoveryCodes(): array
    {
        return array_map(fn (): string => Str::lower(Str::random(5).'-'.Str::random(5)), range(1, self::RECOVERY_CODE_COUNT));
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', Str::lower(trim($code)), (string) config('app.key'));
    }
}
