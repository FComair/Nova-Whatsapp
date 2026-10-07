<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\OrgMember;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * Propriétaire actif de l'organisation courante (destinataire des messages de facturation).
 * À utiliser sous le contexte de l'organisation.
 */
final class OwnerContact
{
    public static function user(): ?User
    {
        $member = OrgMember::query()->where('role', OrgMember::ROLE_OWNER)->where('status', 'active')->first();

        return $member === null ? null : User::query()->find($member->user_id);
    }

    /** Envoie une notification au propriétaire, dans sa langue ; sans effet s'il n'y a pas de propriétaire actif. */
    public static function notify(Notification $notification): void
    {
        $owner = self::user();

        if ($owner !== null) {
            NotificationFacade::route('mail', $owner->email)->notify($notification);
        }
    }

    public static function locale(): string
    {
        $locale = self::user()?->locale;

        return in_array($locale, (array) config('nova.locales'), true) ? (string) $locale : (string) config('app.fallback_locale');
    }
}
