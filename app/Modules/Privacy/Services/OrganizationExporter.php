<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Services;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceLine;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\PaymentMethod;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\BookingEvent;
use App\Modules\Booking\Models\BookingPolicy;
use App\Modules\Calendars\Models\ExternalCalendar;
use App\Modules\Catalog\Models\BusinessHour;
use App\Modules\Catalog\Models\BusinessHourException;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Models\ServiceCategory;
use App\Modules\Customers\Models\Customer;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Staff\Models\PractitionerSchedule;
use App\Modules\Staff\Models\PractitionerTimeOff;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Whatsapp\Models\WhatsappMessage;
use Generator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Export complet des données d'une organisation (droit à la portabilité, RGPD art. 20) en JSON, produit au fil de l'eau.
 *
 * Contenu : les données de l'établissement, de l'équipe, des clients, des rendez-vous et des conversations, textes déchiffrés.
 * Jamais : mots de passe, jetons, clés d'API, adresses et identifiants de calendriers externes, adresses des flux ICS.
 * À exécuter sous le contexte de l'organisation (RLS) : il est impossible d'exporter les données d'une autre.
 */
final class OrganizationExporter
{
    /**
     * Sections exportées : nom => classe de modèle. Les modèles masquent eux-mêmes leurs secrets (`$hidden`).
     */
    private const MODELS = [
        'locations' => Location::class,
        'business_hours' => BusinessHour::class,
        'business_hours_exceptions' => BusinessHourException::class,
        'service_categories' => ServiceCategory::class,
        'services' => Service::class,
        'booking_policies' => BookingPolicy::class,
        'practitioners' => Practitioner::class,
        'practitioner_schedules' => PractitionerSchedule::class,
        'practitioner_time_off' => PractitionerTimeOff::class,
        'customers' => Customer::class,
        'bookings' => Booking::class,
        'booking_events' => BookingEvent::class,
        'whatsapp_messages' => WhatsappMessage::class,
        'subscriptions' => Subscription::class,
        'payment_methods' => PaymentMethod::class,
        'invoices' => Invoice::class,
        'invoice_lines' => InvoiceLine::class,
        'payments' => Payment::class,
    ];

    /** Colonnes techniques jamais exportées. */
    private const EXCLUDED = ['idempotency_key'];

    /**
     * @return Generator<int, string> morceaux de JSON à écrire dans l'ordre
     */
    public function stream(Organization $organization): Generator
    {
        yield '{"exported_at":'.json_encode(now()->toIso8601String()).',"format_version":1';
        yield ',"organization":'.$this->encode($this->organization($organization));
        yield ',"team":'.$this->encode($this->team());
        yield ',"practitioner_services":'.$this->encode(DB::table('practitioner_services')->get(['practitioner_id', 'service_id', 'custom_duration_minutes', 'custom_price_cents'])->all());

        foreach (self::MODELS as $section => $class) {
            yield ',"'.$section.'":[';
            $first = true;

            foreach (self::rows($class) as $model) {
                yield ($first ? '' : ',').$this->encode(array_diff_key($model->attributesToArray(), array_flip(self::EXCLUDED)));
                $first = false;
            }

            yield ']';
        }

        // Calendriers externes : nom, type et état seulement (l'adresse est un secret).
        yield ',"external_calendars":'.$this->encode(ExternalCalendar::query()->get()->map(fn (ExternalCalendar $calendar): array => [
            'name' => $calendar->name, 'kind' => $calendar->kind, 'status' => $calendar->status, 'practitioner_id' => $calendar->practitioner_id,
        ])->all());

        yield '}';
    }

    /**
     * @return array<string, mixed>
     */
    private function organization(Organization $organization): array
    {
        return $organization->only(['id', 'name', 'sector', 'country', 'timezone', 'locale', 'status', 'billing_name', 'billing_vat_number', 'billing_address', 'trial_ends_at', 'created_at']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function team(): array
    {
        return DB::table('org_members as m')->join('users as u', 'u.id', '=', 'm.user_id')->get(['u.id as user_id', 'u.email', 'u.phone_e164', 'm.role', 'm.status'])
            ->map(fn (object $row): array => (array) $row)->all();
    }

    /**
     * @param  class-string<Model>  $class
     * @return LazyCollection<int, Model>
     */
    private static function rows(string $class): LazyCollection
    {
        return $class::query()->cursor();
    }

    private function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
