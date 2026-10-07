<?php

declare(strict_types=1);

use App\Modules\Api\Http\Controllers\AvailabilityController;
use App\Modules\Api\Http\Controllers\BookingApiController;
use App\Modules\Api\Http\Controllers\ConfigController;
use App\Modules\Api\Http\Controllers\CustomerApiController;
use App\Modules\Api\Http\Controllers\MessageApiController;
use App\Modules\Whatsapp\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API interne v1 (n8n, passerelle WhatsApp)
|--------------------------------------------------------------------------
| Préfixe /api/v1. Authentification par clé + signature HMAC et périmètres (docs/02 §5) ; jamais de session.
| Les écritures de réservation passent par le cœur de réservation.
*/

// Webhook Meta : pas de clé d'API, authentifié par la signature X-Hub-Signature-256 (secret de l'application Meta).
Route::middleware(['throttle:1200,1'])->prefix('/webhooks/whatsapp')->group(function (): void {
    Route::get('/', [WebhookController::class, 'verify']);
    Route::post('/', [WebhookController::class, 'receive']);
});

Route::middleware(['throttle:api-client'])->group(function (): void {
    Route::get('/config', [ConfigController::class, 'show'])->middleware('api.auth:config:read');
    Route::get('/availability', [AvailabilityController::class, 'index'])->middleware('api.auth:availability:read');

    Route::post('/customers', [CustomerApiController::class, 'store'])->middleware('api.auth:customers:write');

    Route::post('/messages', [MessageApiController::class, 'store'])->middleware('api.auth:conversations:write');

    Route::get('/bookings', [BookingApiController::class, 'index'])->middleware('api.auth:bookings:read');
    Route::post('/bookings', [BookingApiController::class, 'store'])->middleware('api.auth:bookings:write');
    Route::post('/bookings/{booking}/confirm', [BookingApiController::class, 'confirm'])->middleware('api.auth:bookings:write');
    Route::post('/bookings/{booking}/cancel', [BookingApiController::class, 'cancel'])->middleware('api.auth:bookings:write');
    Route::put('/bookings/{booking}/move', [BookingApiController::class, 'move'])->middleware('api.auth:bookings:write');
});
