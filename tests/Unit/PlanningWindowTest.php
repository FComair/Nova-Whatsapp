<?php

declare(strict_types=1);

use App\Modules\Booking\Support\PlanningWindow;

/**
 * Jours chargés selon la vue du planning.
 */
it('retombe sur la semaine pour une vue inconnue', function (mixed $value, string $expected): void {
    expect(PlanningWindow::view($value))->toBe($expected);
})->with([
    'jour' => ['day', 'day'],
    '3 jours' => ['3days', '3days'],
    'praticiens' => ['practitioners', 'practitioners'],
    'inconnue' => ['mois', 'week'],
    'absente' => [null, 'week'],
    'pas du texte' => [['day'], 'week'],
]);

it('charge un seul jour en vue jour', function (): void {
    expect(PlanningWindow::days('day', '2026-06-03'))->toBe(['2026-06-03']);
});

it('charge 3 jours glissants, y compris à cheval sur deux semaines et deux mois', function (): void {
    expect(PlanningWindow::days('3days', '2026-06-03'))->toBe(['2026-06-03', '2026-06-04', '2026-06-05']);
    expect(PlanningWindow::days('3days', '2026-06-07'))->toBe(['2026-06-07', '2026-06-08', '2026-06-09']);
    expect(PlanningWindow::days('3days', '2026-06-29'))->toBe(['2026-06-29', '2026-06-30', '2026-07-01']);
});

it('charge la semaine du lundi au dimanche en vue semaine et en vue praticiens', function (string $view): void {
    $week = ['2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05', '2026-06-06', '2026-06-07'];

    expect(PlanningWindow::days($view, '2026-06-03'))->toBe($week);
    expect(PlanningWindow::days($view, '2026-06-07'))->toBe($week);
    expect(PlanningWindow::days($view, '2026-06-01'))->toBe($week);
})->with(['week', 'practitioners']);
