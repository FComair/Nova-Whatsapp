<?php

declare(strict_types=1);

use App\Modules\Booking\Support\PlanningRange;

/**
 * Plage affichée par la grille du planning : 2 h avant la première ouverture et 2 h après la dernière fermeture.
 */
it('ajoute 2 h avant l\'ouverture et 2 h après la fermeture', function (): void {
    expect(PlanningRange::compute([[540, 1080]]))->toBe(['from' => 420, 'to' => 1200]);
});

it('couvre tous les praticiens : le plus tôt moins 2 h, le plus tard plus 2 h', function (): void {
    expect(PlanningRange::compute([[480, 960], [600, 1200]]))->toBe(['from' => 360, 'to' => 1320]);
});

it('arrondit à l\'heure vers l\'extérieur', function (): void {
    expect(PlanningRange::compute([[570, 1050]]))->toBe(['from' => 420, 'to' => 1200]);
});

it('borne la plage à la journée', function (): void {
    expect(PlanningRange::compute([[60, 1400]]))->toBe(['from' => 0, 'to' => 1440]);
});

it('prend la plage par défaut 06:00–22:00 sans aucun horaire', function (): void {
    expect(PlanningRange::compute([]))->toBe(['from' => 360, 'to' => 1320]);
});

it('s\'élargit pour un rendez-vous hors de la plage sans ajouter de marge autour de lui', function (): void {
    expect(PlanningRange::compute([[540, 1080]], [[300, 330], [1260, 1290]]))->toBe(['from' => 300, 'to' => 1320]);
    expect(PlanningRange::compute([[540, 1080]], [[600, 660]]))->toBe(['from' => 420, 'to' => 1200]);
});

it('garde un rendez-vous qui finit à minuit', function (): void {
    expect(PlanningRange::compute([[540, 1080]], [[1380, 1440]]))->toBe(['from' => 420, 'to' => 1440]);
});
