<?php

declare(strict_types=1);

use App\Support\Money;

/**
 * Les montants sont des centimes entiers : jamais de flottants.
 */
it('convertit une saisie en euros en centimes', function (string $input, int $cents): void {
    expect(Money::parseToCents($input))->toBe($cents);
})->with([
    ['40', 4000],
    ['40,5', 4050],
    ['40.5', 4050],
    ['40,50', 4050],
    ['40.05', 4005],
    ['0', 0],
    ['0,99', 99],
    [' 12 € ', 1200],
    ['1 234,50', 123450],
    ['1234567', 123456700],
]);

it('refuse les saisies invalides', function (string $input): void {
    expect(Money::parseToCents($input))->toBeNull();
})->with(['', ' ', '-5', '+5', '40,555', 'abc', '4e2', '40,,5', '12345678', '1,2,3', '€']);

it('ne subit pas les erreurs d\'arrondi des flottants', function (): void {
    // 19.99 * 100 vaut 1998.9999999999998 en flottant.
    expect(Money::parseToCents('19,99'))->toBe(1999)->and(Money::parseToCents('0,29'))->toBe(29);
});
