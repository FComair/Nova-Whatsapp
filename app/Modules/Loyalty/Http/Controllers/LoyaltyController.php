<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Http\Controllers;

use App\Modules\Customers\Models\Customer;
use App\Modules\Loyalty\Services\LoyaltyDirectory;
use App\Modules\Loyalty\Services\LoyaltyReport;
use App\Modules\Loyalty\Support\LoyaltyAccess;
use App\Modules\Tenancy\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Écrans de lecture de la fidélité (docs/27) : tableau de bord, liste des clients, utilisation d'une récompense, rapport.
 * Propriétaire, responsable de site et réceptionniste (route) ; la configuration est dans LoyaltyProgramController.
 */
final class LoyaltyController
{
    public function __construct(private readonly LoyaltyDirectory $directory, private readonly LoyaltyReport $report, private readonly LoyaltyAccess $access) {}

    public function index(Request $request): Response
    {
        $program = $this->directory->program();
        $locale = app()->getLocale();

        return Inertia::render('Loyalty/Index', [
            'can' => $this->access->abilities(),
            'program' => $program === null ? null : $this->directory->summary($program, $locale),
            'overview' => $program === null ? null : $this->directory->overview($program),
            'recent' => $this->directory->recent($program),
            'report' => $program === null ? null : $this->report->build([]),
        ]);
    }

    public function customers(Request $request): Response
    {
        $program = $this->directory->program();
        $status = $request->query('status');
        $search = trim((string) $request->query('q', ''));
        $customers = $this->directory->customers($program, ['status' => is_string($status) ? $status : null, 'search' => $search], max(1, (int) $request->query('page', 1)));

        return Inertia::render('Loyalty/Customers', [
            'can' => $this->access->abilities(),
            'program' => $program === null ? null : $this->directory->summary($program, app()->getLocale()),
            'customers' => $customers['rows'],
            'pagination' => ['page' => $customers['page'], 'last' => $customers['last'], 'total' => $customers['total']],
            'filters' => ['status' => is_string($status) ? $status : '', 'q' => $search],
            'statuses' => LoyaltyDirectory::STATUSES,
        ]);
    }

    /** Utiliser une récompense : retrouver le client, voir sa récompense et ses conditions, choisir le rendez-vous, confirmer. */
    public function redeem(Request $request): Response
    {
        $program = $this->directory->program();
        $locale = app()->getLocale();
        $term = trim((string) $request->query('q', ''));
        $customer = is_string($request->query('customer')) ? Customer::query()->whereNull('anonymized_at')->find($request->query('customer')) : null;
        $done = $request->session()->get('loyalty_redeemed');

        return Inertia::render('Loyalty/Redeem', [
            'can' => $this->access->abilities(),
            'program' => $program === null ? null : $this->directory->summary($program, $locale),
            'q' => $term,
            'results' => $term === '' ? [] : $this->directory->search($term),
            'customer' => $customer === null ? null : ['id' => $customer->id, 'name' => $customer->name, 'phone' => $customer->phone_e164, 'language' => $customer->language],
            'panel' => $customer === null ? null : $this->directory->panel($customer, $program, $locale, false),
            'bookings' => $customer === null ? [] : $this->directory->redeemableBookings($customer),
            'done' => is_array($done) ? $done : null,
        ]);
    }

    public function report(Request $request): Response
    {
        $program = $this->directory->program();

        return Inertia::render('Loyalty/Report', [
            'can' => $this->access->abilities(),
            'program' => $program === null ? null : $this->directory->summary($program, app()->getLocale()),
            'report' => $this->report->build($request->only(['from', 'to', 'service', 'location', 'program'])),
            'services' => DB::table('services')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name'])->map(fn (object $row): array => ['id' => (string) $row->id, 'name' => (string) $row->name])->all(),
            'locations' => Location::query()->orderBy('created_at')->get(['id', 'name'])->map(fn (Location $location): array => ['id' => $location->id, 'name' => $location->name])->all(),
        ]);
    }
}
