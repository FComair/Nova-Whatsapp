<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Services;

use Illuminate\Support\Facades\DB;
use Propaganistas\LaravelPhone\PhoneNumber;
use Throwable;

/**
 * Compte les messages WhatsApp envoyés par établissement, par mois, catégorie et pays du destinataire (base de la refacturation, docs/16).
 * À exécuter sous le contexte de l'organisation (RLS). Un échec de mesure ne doit jamais empêcher l'envoi : il est ignoré.
 */
final class WhatsappUsage
{
    public const UTILITY = 'utility';

    public const SERVICE = 'service';

    public const MARKETING = 'marketing';

    public static function record(string $orgId, string $category, string $toE164): void
    {
        try {
            DB::statement(
                'INSERT INTO whatsapp_usage (org_id, month, category, country, messages, created_at, updated_at) VALUES (?, ?, ?, ?, 1, now(), now())
                 ON CONFLICT (org_id, month, category, country) DO UPDATE SET messages = whatsapp_usage.messages + 1, updated_at = now()',
                [$orgId, now()->utc()->startOfMonth()->toDateString(), $category, self::country($toE164)],
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Pays du numéro (code ISO), ou « XX » s'il n'est pas reconnu. */
    public static function country(string $e164): string
    {
        try {
            return (new PhoneNumber($e164))->getCountry() ?? 'XX';
        } catch (Throwable) {
            return 'XX';
        }
    }
}
