<?php

declare(strict_types=1);

use App\Modules\Whatsapp\Contracts\WhatsappTransport;
use App\Modules\Whatsapp\Support\FakeTransport;

/**
 * Le pilote WhatsApp factice accepte n'importe quels identifiants et renvoie un numéro d'exemple (+32 470 12 34 56) : il est refusé en
 * production, où le numéro affiché doit venir de Meta.
 */
it('refuse le pilote factice en production', function (): void {
    $this->app['env'] = 'production';
    config(['whatsapp.driver' => 'fake']);
    $this->app->forgetInstance(WhatsappTransport::class);

    expect(fn () => app(WhatsappTransport::class))->toThrow(InvalidArgumentException::class, 'interdit en production');
});

it('garde le pilote factice hors production', function (): void {
    config(['whatsapp.driver' => 'fake']);
    $this->app->forgetInstance(WhatsappTransport::class);

    expect(app(WhatsappTransport::class))->toBeInstanceOf(FakeTransport::class);
});
