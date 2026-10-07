<?php

declare(strict_types=1);

namespace App\Modules\Conversation\Support;

use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * Un tour de dialogue : le message du client, la conversation, l'établissement, et les réponses à envoyer.
 * Les textes viennent des fichiers de traduction (`conversation.*`), dans la langue du client.
 */
final class Turn
{
    /** @var list<string> */
    public array $replies = [];

    public function __construct(
        public readonly Organization $org,
        public readonly Location $location,
        public readonly Customer $customer,
        public readonly ConversationState $conv,
        public readonly string $text,
        public readonly string $locale,
        public readonly CarbonImmutable $now,
    ) {}

    /**
     * @param  array<string, string|int|float>  $replace
     */
    public function t(string $key, array $replace = []): string
    {
        return __('conversation.'.$key, $replace, $this->locale);
    }

    /**
     * @param  array<string, string|int|float>  $replace
     */
    public function say(string $key, array $replace = []): void
    {
        $this->replies[] = $this->t($key, $replace);
    }

    public function sayRaw(string $message): void
    {
        $this->replies[] = $message;
    }

    /** Le message du client, normalisé (minuscules, sans accents). */
    public function message(): string
    {
        return Text::normalize($this->text);
    }

    /** Aujourd'hui, à minuit, dans le fuseau du site. */
    public function today(): CarbonImmutable
    {
        return $this->now->setTimezone($this->location->timezone)->startOfDay();
    }

    /** Un instant (UTC) dans le fuseau du site. */
    public function local(CarbonImmutable $instant): CarbonImmutable
    {
        return $instant->setTimezone($this->location->timezone);
    }

    /** Date et heure locales du site (« 2026-06-02 », « 10:30 ») → instant UTC. */
    public function instant(string $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($date.' '.$time, $this->location->timezone)->utc();
    }

    /** « lundi 01/06/2026 ». */
    public function longDate(CarbonImmutable $date): string
    {
        $days = (array) trans('conversation.names.weekdays', [], $this->locale);

        return ($days[$date->dayOfWeek] ?? '').' '.$date->format('d/m/Y');
    }

    /** « 01/06/2026 » (sans le jour de la semaine). */
    public function shortDate(CarbonImmutable $date): string
    {
        return $date->format('d/m/Y');
    }

    /** « lundi 01/06/2026 » à partir d'une date locale « 2026-06-01 ». */
    public function longDateOf(string $date): string
    {
        return $this->longDate(CarbonImmutable::parse($date, $this->location->timezone));
    }

    /** « 25 € » ou « 25,50 € ». */
    public function price(int $cents): string
    {
        return Text::amount($cents, $this->locale).' €';
    }

    public function business(): string
    {
        return $this->org->name;
    }

    /** Adresse du site sur une ligne (vide si elle n'est pas renseignée). */
    public function address(): string
    {
        $address = (array) $this->location->address;
        $street = trim((string) ($address['street'] ?? ''));
        $city = trim(((string) ($address['postal_code'] ?? '')).' '.((string) ($address['city'] ?? '')));

        return implode(', ', array_filter([$street, $city], static fn (string $part): bool => $part !== ''));
    }

    /** Le nom connu du client, ou une chaîne vide. */
    public function name(): string
    {
        return trim((string) $this->customer->name);
    }

    /** « Marie » précédé d'une espace quand le nom est connu (pour « Bonjour Marie, »), sinon rien. */
    public function spacedName(): string
    {
        return $this->name() === '' ? '' : ' '.$this->name();
    }

    /** « Marie,\n\n » quand le nom est connu, sinon rien (pour ouvrir un message). */
    public function who(): string
    {
        return $this->name() === '' ? '' : $this->name().",\n\n";
    }
}
