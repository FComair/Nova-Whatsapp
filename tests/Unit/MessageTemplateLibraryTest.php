<?php

declare(strict_types=1);

use App\Modules\Whatsapp\Support\MessageTemplateLibrary;
use App\Modules\Whatsapp\Support\MessageTemplateType;
use App\Modules\Whatsapp\Support\MessageVariables;

/**
 * Bibliothèque de départ des messages WhatsApp (docs/39) : chaque texte de chaque secteur, dans chaque langue, respecte les règles de Meta.
 */
describe('variables', function (): void {
    it('repère les variables dans l\'ordre et les numérote pour Meta', function (): void {
        $body = 'Bonjour {{customer_name}}, rendez-vous le {{date}} à {{time}}. À bientôt !';

        expect(MessageVariables::extract($body))->toBe(['customer_name', 'date', 'time'])
            ->and(MessageVariables::positional($body))->toBe(['body' => 'Bonjour {{1}}, rendez-vous le {{2}} à {{3}}. À bientôt !', 'names' => ['customer_name', 'date', 'time']]);
    });

    it('rend le texte avec des valeurs', function (): void {
        expect(MessageVariables::render('Bonjour {{customer_name}} !', ['customer_name' => 'Marie']))->toBe('Bonjour Marie !');
    });

    it('refuse les textes que Meta refuserait', function (string $body, string $problem): void {
        expect(MessageVariables::problems($body))->toContain($problem);
    })->with([
        'vide' => ['   ', 'empty'],
        'variable inconnue' => ['Bonjour {{pointure}} !', 'unknown:pointure'],
        'variable en double' => ['{{date}} puis {{date}} merci', 'duplicate:date'],
        'commence par une variable' => ['{{customer_name}}, bonjour', 'starts_with_variable'],
        'finit par une variable' => ['Bonjour {{customer_name}}', 'ends_with_variable'],
        'finit par une variable et un point' => ['Bonjour, voici {{booking_link}}.', 'ends_with_variable'],
        'accolades mal formées' => ['Bonjour {{customer_name} merci', 'malformed'],
    ]);

    it('accepte un texte valide', function (): void {
        expect(MessageVariables::problems('Bonjour {{customer_name}}, à {{time}}. Merci !'))->toBe([]);
    });
});

describe('bibliothèque', function (): void {
    it('couvre tout le parcours client pour chaque secteur, dans les trois langues, avec des textes valides', function (): void {
        foreach (MessageTemplateLibrary::sectors() as $sector) {
            foreach (MessageTemplateLibrary::TYPES as $type) {
                foreach (['fr', 'nl', 'en'] as $language) {
                    $body = MessageTemplateLibrary::body($sector, $type, $language);

                    expect(MessageVariables::problems($body))->toBe([], "{$sector}/{$type}/{$language}")
                        ->and($body)->not->toContain('<', "{$sector}/{$type}/{$language} : un jeton n'a pas été remplacé");
                }
            }
        }
    });

    it('contient les onze secteurs de départ et les types demandés', function (): void {
        expect(MessageTemplateLibrary::sectors())->toEqualCanonicalizing([
            'hairdressing', 'beauty', 'wellness', 'medical', 'paramedical', 'dental', 'coaching', 'real_estate', 'garage', 'driving_school', 'other',
        ])->and(MessageTemplateLibrary::TYPES)->toEqualCanonicalizing(array_values(array_diff(MessageTemplateType::ALL, [MessageTemplateType::CUSTOM])));
    });

    it('adapte le vocabulaire au secteur', function (): void {
        expect(MessageTemplateLibrary::body('medical', 'confirmation', 'fr'))->toContain('votre consultation')
            ->and(MessageTemplateLibrary::body('real_estate', 'confirmation', 'fr'))->toContain('votre visite')
            ->and(MessageTemplateLibrary::body('driving_school', 'confirmation', 'en'))->toContain('driving lesson')
            ->and(MessageTemplateLibrary::body('garage', 'reminder', 'nl'))->toContain('afspraak in het atelier');
    });

    it('donne des textes différents d\'un secteur à l\'autre pour les messages propres au métier', function (): void {
        foreach ([MessageTemplateType::FOLLOW_UP, MessageTemplateType::PROMOTIONAL, MessageTemplateType::INFORMATIONAL, MessageTemplateType::WELCOME] as $type) {
            $bodies = array_map(fn (string $sector): string => MessageTemplateLibrary::body($sector, $type, 'fr'), MessageTemplateLibrary::sectors());

            expect(count(array_unique($bodies)))->toBe(count($bodies), $type);
        }
    });

    it('utilise le jeu générique pour un secteur inconnu', function (): void {
        expect(MessageTemplateLibrary::body('nouveau_secteur', 'reminder', 'fr'))->toBe(MessageTemplateLibrary::body('other', 'reminder', 'fr'));
    });

    it('classe les offres et relances en marketing, le reste en utilitaire', function (): void {
        expect(MessageTemplateType::category(MessageTemplateType::PROMOTIONAL))->toBe('marketing')
            ->and(MessageTemplateType::category(MessageTemplateType::RE_ENGAGEMENT))->toBe('marketing')
            ->and(MessageTemplateType::category(MessageTemplateType::REMINDER))->toBe('utility');
    });
});
