<?php

declare(strict_types=1);

use App\Modules\Booking\Support\Intervals;

describe('normalize', function (): void {
    it('trie, fusionne les intervalles qui se chevauchent ou se touchent, ignore les vides', function (): void {
        expect(Intervals::normalize([[50, 60], [0, 10], [10, 20], [15, 25], [70, 70], [80, 75]]))->toBe([[0, 25], [50, 60]]);
    });
});

describe('union', function (): void {
    it('réunit deux listes', function (): void {
        expect(Intervals::union([[0, 10]], [[5, 15], [20, 30]]))->toBe([[0, 15], [20, 30]]);
    });
});

describe('intersect', function (): void {
    it('garde uniquement les parties communes', function (): void {
        expect(Intervals::intersect([[0, 10], [20, 30]], [[5, 25]]))->toBe([[5, 10], [20, 25]]);
    });

    it('est vide quand les intervalles ne font que se toucher', function (): void {
        expect(Intervals::intersect([[0, 10]], [[10, 20]]))->toBe([]);
    });
});

describe('subtract', function (): void {
    it('retire le milieu et coupe en deux', function (): void {
        expect(Intervals::subtract([[0, 100]], [[40, 60]]))->toBe([[0, 40], [60, 100]]);
    });

    it('retire les bords et les intervalles entiers', function (): void {
        expect(Intervals::subtract([[0, 100]], [[0, 10], [90, 120], [200, 300]]))->toBe([[10, 90]]);
        expect(Intervals::subtract([[10, 20]], [[0, 100]]))->toBe([]);
    });

    it('ne change rien quand rien ne se chevauche', function (): void {
        expect(Intervals::subtract([[0, 10]], [[10, 20]]))->toBe([[0, 10]]);
    });
});

describe('overlaps', function (): void {
    it('considère deux rendez-vous bout à bout comme compatibles', function (): void {
        expect(Intervals::overlaps(0, 10, 10, 20))->toBeFalse();
        expect(Intervals::overlaps(0, 11, 10, 20))->toBeTrue();
        expect(Intervals::overlaps(5, 6, 0, 100))->toBeTrue();
    });
});
