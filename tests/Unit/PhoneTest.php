<?php

declare(strict_types=1);

use App\Support\Phone;

/**
 * Lecture des numéros : jamais d'indicatif deviné. Un numéro national valide dans plusieurs pays (le 06 français et néerlandais)
 * est lu dans le pays de l'établissement, ou refusé s'il est ambigu.
 */
it('lit un numéro international tel quel, quel que soit le pays de l\'établissement', function (string $input, string $preferred, string $expected): void {
    expect(Phone::toE164($input, $preferred))->toBe($expected);
})->with([
    'belge' => ['+32 470 12 34 56', 'NL', '+32470123456'],
    'néerlandais' => ['+31 6 12345678', 'BE', '+31612345678'],
    'français' => ['+33 6 12 34 56 78', 'NL', '+33612345678'],
    'préfixe 00' => ['0031 6 12345678', 'BE', '+31612345678'],
    'séparateurs' => ['+32 (0) 470-12.34.56', 'BE', '+32470123456'],
]);

it('lit un numéro national dans le pays de l\'établissement, même quand un autre pays le trouverait valide', function (): void {
    expect(Phone::toE164('0612345678', 'NL'))->toBe('+31612345678');
    expect(Phone::toE164('06 12 34 56 78', 'FR'))->toBe('+33612345678');
    expect(Phone::toE164('0470 12 34 56', 'BE'))->toBe('+32470123456');
});

it('ne devine pas : un numéro national ambigu sans pays connu est refusé avec le bon motif', function (): void {
    $result = Phone::resolve('0612345678');

    expect($result['e164'])->toBeNull()->and($result['problem'])->toBe(Phone::AMBIGUOUS);
});

it('accepte un numéro national sans pays connu quand un seul pays le reconnaît', function (): void {
    expect(Phone::toE164('020 7946 0958'))->toBe('+442079460958');
});

it('refuse un numéro invalide ou d\'un pays non accepté', function (string $input): void {
    expect(Phone::resolve($input, 'BE'))->toBe(['e164' => null, 'problem' => Phone::INVALID]);
})->with(['lettres' => 'abc', 'vide' => '', 'trop court' => '+32 12', 'pays non accepté' => '+1 415 555 2671', 'invalide dans le pays' => '0612345678']);

it('reconnaît un numéro international', function (): void {
    expect(Phone::isInternational('+32470123456'))->toBeTrue()
        ->and(Phone::isInternational(' 0032 470 12 34 56'))->toBeTrue()
        ->and(Phone::isInternational('0470 12 34 56'))->toBeFalse();
});
