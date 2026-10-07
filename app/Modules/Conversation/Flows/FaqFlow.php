<?php

declare(strict_types=1);

namespace App\Modules\Conversation\Flows;

use App\Modules\Booking\Services\PolicyService;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Services\BusinessHoursService;
use App\Modules\Conversation\Contracts\LanguageModel;
use App\Modules\Conversation\Services\BookingGateway;
use App\Modules\Conversation\Support\Turn;

/**
 * Questions des clients (horaires, prix, adresse…). La réponse est rédigée par le modèle de langage à partir des SEULES données
 * de l'établissement (consigne du flux n8n : ne rien inventer, dire qu'on ne sait pas). Sans modèle ou en cas d'échec :
 * un message qui renvoie vers l'établissement. Ne crée, ne modifie et n'annule jamais de rendez-vous.
 */
final class FaqFlow extends Flow
{
    public const QUESTION = 'waiting_faq_question';

    public function __construct(
        BookingGateway $gateway,
        private readonly LanguageModel $model,
        private readonly BusinessHoursService $hours,
        private readonly PolicyService $policies,
    ) {
        parent::__construct($gateway);
    }

    /** « Quelle est votre question ? » (le client a choisi « Poser une question »). */
    public function ask(Turn $turn): void
    {
        $turn->conv->reset();
        $turn->say('faq.ask');
        $turn->conv->go(self::QUESTION, 'faq');
    }

    public function handle(Turn $turn): void
    {
        $this->answer($turn, $turn->text);
    }

    /** Répond, puis revient au menu principal (comme le flux n8n). */
    public function answer(Turn $turn, string $question): void
    {
        $turn->conv->reset();
        $turn->sayRaw($this->respond($turn, $question));
        $turn->say('faq.menu');
        $turn->conv->go(MenuFlow::MAIN, 'faq');
    }

    /** La réponse seule, sans toucher à l'état de la conversation. */
    public function respond(Turn $turn, string $question): string
    {
        $fallback = $turn->t('faq.unavailable', ['business' => $turn->business()]);

        if (! $this->model->available()) {
            return $fallback;
        }

        return $this->model->complete(
            (string) trans('conversation.prompts.faq', $this->context($turn), 'fr'),
            (string) trans('conversation.prompts.faq_user', ['question' => $question], 'fr'),
        ) ?? $fallback;
    }

    /**
     * Les données de l'établissement placées dans la consigne.
     *
     * @return array<string, string>
     */
    private function context(Turn $turn): array
    {
        $none = (string) trans('conversation.prompts.none', [], 'fr');
        $policy = $this->policies->for($turn->org);
        $address = $turn->address();

        $services = array_map(
            fn (Service $service): string => '- '.$service->name.' : '.$service->duration_minutes.' min, '.$turn->price($service->price_cents),
            $this->gateway->services(),
        );

        return [
            'business' => $turn->business(),
            'address' => $address === '' ? $none : $address,
            'country' => $turn->org->country,
            'hours' => $this->openingHours($turn) ?? $none,
            'cancellation' => (string) trans('conversation.prompts.cancellation', ['hours' => $policy->cancellation_window_hours], 'fr'),
            'services' => $services === [] ? $none : implode("\n", $services),
        ];
    }

    private function openingHours(Turn $turn): ?string
    {
        $days = (array) trans('conversation.names.weekdays', [], 'fr');
        $closed = (string) trans('conversation.prompts.closed', [], 'fr');
        $lines = [];

        foreach ($this->hours->weekly($turn->location) as $isoDay => $ranges) {
            $name = (string) ($days[$isoDay % 7] ?? $isoDay);
            $lines[] = $name.' : '.($ranges === [] ? $closed : implode(', ', array_map(static fn (array $range): string => $range['start'].'-'.$range['end'], $ranges)));
        }

        return $lines === [] ? null : implode("\n", $lines);
    }
}
