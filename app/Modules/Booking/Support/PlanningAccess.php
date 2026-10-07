<?php

declare(strict_types=1);

namespace App\Modules\Booking\Support;

use App\Modules\Booking\Models\Booking;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Models\OrgMember;
use App\Modules\Tenancy\Support\CurrentTenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ce que l'utilisateur connecté peut voir et modifier dans le planning (docs/02 §1) :
 * propriétaire = tout ; responsable de site et réceptionniste = leurs sites ; praticien = ses propres rendez-vous.
 *
 * Hypothèse : un responsable ou réceptionniste sans site attribué voit tous les sites (l'attribution des sites
 * arrivera avec la gestion de l'équipe).
 */
final class PlanningAccess
{
    private ?string $practitionerId = null;

    private bool $practitionerLoaded = false;

    /** @var list<string>|null */
    private ?array $locationIds = null;

    private bool $locationsLoaded = false;

    public function __construct(private readonly CurrentTenant $current) {}

    public function role(): string
    {
        return $this->current->role() ?? abort(403);
    }

    public function isPractitioner(): bool
    {
        return $this->role() === OrgMember::ROLE_PRACTITIONER;
    }

    /** Les notes de rendez-vous (données sensibles) sont masquées aux réceptionnistes. */
    public function seesNotes(): bool
    {
        return $this->role() !== OrgMember::ROLE_RECEPTIONIST;
    }

    /** Fiche praticien du compte connecté (rôle praticien uniquement). */
    public function practitionerId(): ?string
    {
        if (! $this->practitionerLoaded) {
            $this->practitionerId = Practitioner::query()->where('user_id', auth()->id())->value('id');
            $this->practitionerLoaded = true;
        }

        return $this->practitionerId;
    }

    /**
     * @return Collection<int, Location>
     */
    public function locations(): Collection
    {
        $allowed = $this->allowedLocationIds();

        return Location::query()->orderBy('created_at')->get()
            ->filter(fn (Location $location): bool => $allowed === null || in_array($location->id, $allowed, true))->values();
    }

    public function canAccessLocation(string $locationId): bool
    {
        $allowed = $this->allowedLocationIds();

        return $allowed === null || in_array($locationId, $allowed, true);
    }

    public function canManage(Booking $booking): bool
    {
        if (! $this->canAccessLocation($booking->location_id)) {
            return false;
        }

        return ! $this->isPractitioner() || ($this->practitionerId() !== null && $this->practitionerId() === $booking->practitioner_id);
    }

    public function authorize(Booking $booking): void
    {
        abort_unless($this->canManage($booking), 403);
    }

    /**
     * @return list<string>|null null = tous les sites
     */
    private function allowedLocationIds(): ?array
    {
        if ($this->locationsLoaded) {
            return $this->locationIds;
        }

        $this->locationsLoaded = true;

        if (in_array($this->role(), [OrgMember::ROLE_OWNER, OrgMember::ROLE_PRACTITIONER], true)) {
            return $this->locationIds = null;
        }

        $memberId = OrgMember::query()->where('user_id', auth()->id())->value('id');
        $ids = DB::table('org_member_locations')->where('member_id', $memberId)->pluck('location_id')->map(fn ($id): string => (string) $id)->all();

        return $this->locationIds = $ids === [] ? null : array_values($ids);
    }
}
