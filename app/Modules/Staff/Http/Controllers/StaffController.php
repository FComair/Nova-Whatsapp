<?php

declare(strict_types=1);

namespace App\Modules\Staff\Http\Controllers;

use App\Modules\Staff\Http\Requests\InviteRequest;
use App\Modules\Staff\Http\Requests\PractitionerPhotoRequest;
use App\Modules\Staff\Http\Requests\PractitionerRequest;
use App\Modules\Staff\Http\Requests\ScheduleRequest;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Staff\Services\InvitationService;
use App\Modules\Staff\Services\PlanLimitReached;
use App\Modules\Staff\Services\PractitionerManager;
use App\Modules\Staff\Services\PractitionerPhoto;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;

/**
 * Praticiens de l'organisation (propriétaire, docs/02 §1). Les pages s'appuient sur l'assistant d'onboarding.
 */
final class StaffController
{
    public function __construct(
        private readonly CurrentTenant $current,
        private readonly PractitionerManager $manager,
        private readonly InvitationService $invitations,
        private readonly PractitionerPhoto $photos,
    ) {}

    public function store(PractitionerRequest $request): RedirectResponse
    {
        $userId = null;

        if ($request->boolean('is_me')) {
            $userId = (string) auth()->id();

            if (Practitioner::query()->where('user_id', $userId)->exists()) {
                return back()->withErrors(['is_me' => __('ui.practitioners.errors.already_me')]);
            }
        }

        $result = $this->manager->create($this->organization(), $request->practitioner(), $userId);

        // Photo choisie à la création : si elle ne peut pas être traitée, le praticien est créé quand même (sans photo).
        if ($request->hasFile('photo')) {
            try {
                $this->photos->store($result['practitioner'], $request->file('photo'), (string) auth()->id());
            } catch (InvalidArgumentException) {
                return back()->with('warning', __('ui.practitioner_photo.errors.failed_on_create'));
            }
        }

        // E8 : le praticien est conservé en brouillon, avec un message clair (flowchart 5B).
        if ($result['over_limit']) {
            return back()->with('warning', __('ui.practitioners.errors.limit', ['count' => (int) $this->manager->limit($this->organization())]));
        }

        return back()->with('status', __('ui.onboarding.saved'));
    }

    /** Invite un praticien par e-mail : il renseigne lui-même ses services et disponibilités. */
    public function invite(InviteRequest $request): RedirectResponse
    {
        $result = $this->invitations->invite($this->organization(), $request->string('name')->toString(), $request->string('email')->toString(), (string) auth()->id());

        if ($result['over_limit']) {
            return back()->with('warning', __('ui.practitioners.errors.limit', ['count' => (int) $this->manager->limit($this->organization())]));
        }

        return back()->with('status', __('ui.practitioners.invite_sent'));
    }

    /** Nouvelle invitation : l'ancienne est invalidée (E13). */
    public function resendInvitation(Practitioner $practitioner): RedirectResponse
    {
        try {
            $this->invitations->resend($this->organization(), $practitioner, (string) auth()->id());
        } catch (InvalidArgumentException) {
            return back()->withErrors(['practitioner' => __('ui.practitioners.errors.cannot_reinvite')]);
        }

        return back()->with('status', __('ui.practitioners.invite_sent'));
    }

    public function update(PractitionerRequest $request, Practitioner $practitioner): RedirectResponse
    {
        $this->manager->update($this->organization(), $practitioner, $request->practitioner());

        return back()->with('status', __('ui.onboarding.saved'));
    }

    /** Envoie ou remplace la photo d'un praticien. En cas d'échec, la photo actuelle est conservée. */
    public function photo(PractitionerPhotoRequest $request, Practitioner $practitioner): RedirectResponse
    {
        try {
            $this->photos->store($practitioner, $request->file('photo') ?? abort(422), (string) auth()->id());
        } catch (InvalidArgumentException) {
            return back()->withErrors(['photo' => __('ui.practitioner_photo.errors.failed')]);
        }

        return back()->with('status', __('ui.practitioner_photo.saved'));
    }

    public function removePhoto(Practitioner $practitioner): RedirectResponse
    {
        $this->photos->remove($practitioner, (string) auth()->id());

        return back()->with('status', __('ui.practitioner_photo.removed'));
    }

    public function destroy(Practitioner $practitioner): RedirectResponse
    {
        $this->manager->delete($this->organization(), $practitioner);

        return back()->with('status', __('ui.onboarding.saved'));
    }

    public function activate(Practitioner $practitioner): RedirectResponse
    {
        try {
            $this->manager->activate($this->organization(), $practitioner);
        } catch (PlanLimitReached $e) {
            return back()->withErrors(['limit' => __('ui.practitioners.errors.limit', ['count' => $e->limit])]);
        }

        return back()->with('status', __('ui.onboarding.saved'));
    }

    public function saveSchedule(ScheduleRequest $request, Practitioner $practitioner, Location $location): RedirectResponse
    {
        try {
            $this->manager->replaceSchedule($this->organization(), $practitioner, $location, $request->week());
        } catch (InvalidArgumentException) {
            return back()->withErrors(['hours' => __('ui.practitioners.errors.overlap_other_site')]);
        }

        return back()->with('status', __('ui.onboarding.saved'));
    }

    private function organization(): Organization
    {
        return $this->current->organization() ?? abort(403);
    }
}
