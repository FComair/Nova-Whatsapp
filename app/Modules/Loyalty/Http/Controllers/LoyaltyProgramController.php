<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Http\Controllers;

use App\Modules\Loyalty\Http\Requests\ProgramRequest;
use App\Modules\Loyalty\Models\LoyaltyProgram;
use App\Modules\Loyalty\Services\LoyaltyDirectory;
use App\Modules\Loyalty\Services\LoyaltyPrograms;
use App\Modules\Loyalty\Support\LoyaltyMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Configuration du programme et de ses messages WhatsApp : propriétaire uniquement (route). Voir docs/27.
 */
final class LoyaltyProgramController
{
    public function __construct(private readonly LoyaltyPrograms $programs, private readonly LoyaltyDirectory $directory) {}

    public function show(Request $request): Response
    {
        $program = LoyaltyProgram::current();
        $latest = $program ?? $this->directory->program();

        return Inertia::render('Loyalty/Setup', [
            'program' => $latest === null ? null : ['status' => $latest->status, 'activated_at' => $latest->activated_at?->toIso8601String()],
            'values' => $program === null ? LoyaltyPrograms::defaults() : $this->programs->values($program),
            'problems' => $program === null ? [] : $this->programs->problems($program),
            'incomplete_languages' => $program === null ? [] : $this->programs->incompleteLanguages($program),
            'has_progress' => $program !== null && $this->programs->hasProgress($program),
            'impact' => $request->session()->get('loyalty_impact'),
            'services' => DB::table('services')->whereNull('deleted_at')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'price_cents'])
                ->map(fn (object $row): array => ['id' => (string) $row->id, 'name' => (string) $row->name, 'price_cents' => (int) $row->price_cents])->all(),
            'locales' => (array) config('rendivoo.locales'),
            // Textes fixes de la carte client dans chaque langue : l'aperçu montre ce que verra un client qui parle cette langue.
            'preview_i18n' => collect((array) config('rendivoo.locales'))->mapWithKeys(fn (string $locale): array => [$locale => [
                'of' => __('ui.loyalty.progress.of', [], $locale),
                'remaining' => __('ui.loyalty.progress.remaining', [], $locale),
                'ready' => __('ui.loyalty.progress.ready', [], $locale),
                'title' => __('ui.loyalty.public.title', [], $locale),
                'terms' => __('ui.loyalty.public.terms', [], $locale),
                'data_title' => __('ui.loyalty.public.data_title', [], $locale),
                'data' => __('ui.loyalty.public.data', [], $locale),
                'marketing' => __('ui.loyalty.public.marketing', [], $locale),
                'expires' => __('ui.loyalty.public.expires', [], $locale),
                'reward_label' => __('ui.loyalty.public.reward_label', [], $locale),
            ]])->all(),
        ]);
    }

    public function save(ProgramRequest $request): RedirectResponse
    {
        $result = $this->programs->save($request->programInput(), (string) $request->user()?->id, $request->boolean('confirmed'));

        if ($result['result'] === 'confirm') {
            return to_route('loyalty.program')->with('loyalty_impact', $result['impact']);
        }

        return to_route('loyalty.program')->with('status', __('ui.loyalty.setup.saved'));
    }

    public function activate(Request $request): RedirectResponse
    {
        return $this->transition(fn () => $this->programs->activate((string) $request->user()?->id), 'ui.loyalty.setup.activated');
    }

    public function pause(Request $request): RedirectResponse
    {
        return $this->transition(fn () => $this->programs->pause((string) $request->user()?->id), 'ui.loyalty.setup.paused');
    }

    public function archive(Request $request): RedirectResponse
    {
        return $this->transition(fn () => $this->programs->archive((string) $request->user()?->id), 'ui.loyalty.setup.archived');
    }

    public function messages(): Response
    {
        $program = LoyaltyProgram::current() ?? $this->directory->program();
        $locales = (array) config('rendivoo.locales');
        $saved = $program === null ? [] : (array) $program->messages;
        $settings = [];

        foreach (LoyaltyMessages::KINDS as $kind) {
            $settings[$kind] = [
                'enabled' => (bool) ($saved[$kind]['enabled'] ?? false),
                'texts' => collect($locales)->mapWithKeys(fn (string $locale): array => [$locale => (string) ($saved[$kind]['texts'][$locale] ?? '')])->all(),
            ];
        }

        return Inertia::render('Loyalty/Messages', [
            'program' => $program === null ? null : $this->directory->summary($program, app()->getLocale()),
            'settings' => $settings,
            'kinds' => LoyaltyMessages::KINDS,
            'variables' => LoyaltyMessages::VARIABLES,
            'max_length' => LoyaltyMessages::MAX_LENGTH,
            'defaults' => collect(LoyaltyMessages::KINDS)->mapWithKeys(fn (string $kind): array => [$kind => collect($locales)->mapWithKeys(fn (string $locale): array => [$locale => LoyaltyMessages::defaultText($kind, $locale)])->all()])->all(),
            'samples' => collect($locales)->mapWithKeys(fn (string $locale): array => [$locale => LoyaltyMessages::samples($locale)])->all(),
            'locales' => $locales,
            'has_expiry' => $program?->expiry_days !== null,
        ]);
    }

    public function saveMessages(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*.enabled' => ['required', 'boolean'],
            'settings.*.texts' => ['nullable', 'array'],
            'settings.*.texts.*' => ['nullable', 'string', 'max:'.LoyaltyMessages::MAX_LENGTH],
        ], ['settings.*.texts.*.max' => __('ui.loyalty.messages.too_long', ['max' => LoyaltyMessages::MAX_LENGTH])]);

        try {
            $this->programs->saveMessages((array) $data['settings'], (string) $request->user()?->id);
        } catch (InvalidArgumentException $e) {
            if (str_starts_with($e->getMessage(), 'unknown_variable:')) {
                [, $kind, $name] = explode(':', $e->getMessage());

                return back()->withErrors(['settings' => __('ui.loyalty.messages.unknown_variable', ['name' => '{'.$name.'}', 'message' => __("ui.loyalty.messages.kinds.{$kind}.title")])]);
            }

            return back()->withErrors(['settings' => __('ui.loyalty.errors.no_program')]);
        }

        return back()->with('status', __('ui.loyalty.messages.saved'));
    }

    /** @param  callable(): mixed  $action */
    private function transition(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['program' => __("ui.loyalty.errors.{$e->getMessage()}")]);
        }

        return back()->with('status', __($success));
    }
}
