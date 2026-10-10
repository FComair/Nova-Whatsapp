@php
    $v = $this->preview();
    $article = $v['article'];
    $translation = $v['translation'];
    $visible = $v['visibility'] === 'visible';
    $fmt = fn ($date) => $date?->timezone(config('app.timezone'))->format('d/m/Y');
@endphp

<x-filament-panels::page>
    <style>
        .fp { --fp-border: #e5e7eb; --fp-muted: #6b7280; --fp-card: #fff; --fp-mint: #ecfdf5; --fp-text: #111827; display: grid; gap: 1rem; }
        .dark .fp { --fp-border: #374151; --fp-muted: #9ca3af; --fp-card: #111827; --fp-mint: #064e3b; --fp-text: #f3f4f6; }
        .fp-tabs { display: flex; flex-wrap: wrap; gap: .5rem; }
        .fp-page { border: 1px solid var(--fp-border); border-radius: .75rem; padding: 1.25rem 1.5rem; background: var(--fp-card); max-width: 56rem; }
        .fp-crumb { font-size: .8rem; color: var(--fp-muted); margin-bottom: .5rem; }
        .fp-title { font-size: 1.6rem; font-weight: 700; margin: 0 0 .25rem; color: var(--fp-text); }
        .fp-summary { color: var(--fp-muted); margin: 0 0 .25rem; }
        .fp-meta { font-size: .8rem; color: var(--fp-muted); margin-bottom: 1rem; }
        .fp-body { line-height: 1.6; max-width: 72ch; overflow-wrap: anywhere; color: var(--fp-text); }
        .fp-body h2 { font-size: 1.25rem; font-weight: 700; margin: 1.5rem 0 .5rem; }
        .fp-body h3 { font-size: 1.05rem; font-weight: 600; margin: 1.1rem 0 .4rem; }
        .fp-body ul { list-style: disc; padding-inline-start: 1.5rem; margin: .5rem 0; }
        .fp-body ol { list-style: decimal; padding-inline-start: 1.5rem; margin: .5rem 0; }
        .fp-body p { margin: .6rem 0; }
        .fp-body a { text-decoration: underline; }
        .fp-body code { background: var(--fp-mint); border-radius: .25rem; padding: .05rem .3rem; font-size: .9em; }
        .fp-body pre { background: var(--fp-mint); border-radius: .5rem; padding: .75rem; overflow-x: auto; }
        .fp-body img { max-width: 100%; border-radius: .5rem; }
        .fp-body blockquote { border-inline-start: 4px solid var(--fp-border); padding-inline-start: 1rem; color: var(--fp-muted); margin: .75rem 0; }
        .fp-toc { font-size: .85rem; margin: 0 0 1rem; padding-inline-start: 1.5rem; list-style: decimal; }
        .fp-related ul { list-style: disc; padding-inline-start: 1.5rem; margin: .5rem 0; }
        .fp-related { margin-top: 1.5rem; border-top: 1px solid var(--fp-border); padding-top: 1rem; }
        .fp-helpful { margin-top: 1.5rem; border: 1px dashed var(--fp-border); border-radius: .5rem; padding: .75rem 1rem; color: var(--fp-muted); font-size: .9rem; }
    </style>

    <div class="fp">
        <x-filament::callout :color="$visible ? 'success' : 'warning'" :icon="$visible ? 'heroicon-m-globe-alt' : 'heroicon-m-eye-slash'"
            :heading="$visible ? __('faq.preview.visible') : __('faq.preview.hidden')"
            :description="__('faq.preview.reason.'.$v['visibility'], ['language' => __('faq.locales.'.$this->locale)])" />

        <div class="fp-tabs" role="tablist" aria-label="{{ __('faq.preview.languages') }}">
            @foreach (\App\Modules\Help\Models\FaqCategory::LOCALES as $locale)
                @php($state = \App\Modules\Help\Support\FaqPublishing::completeness($article)[$locale])
                <x-filament::button size="sm" :color="$this->locale === $locale ? 'primary' : 'gray'" :outlined="$this->locale !== $locale" wire:click="selectLocale('{{ $locale }}')"
                    role="tab" aria-selected="{{ $this->locale === $locale ? 'true' : 'false' }}">
                    {{ __('faq.locales.'.$locale) }} · {{ __('faq.language_state.'.$state) }}
                </x-filament::button>
            @endforeach
        </div>

        @if ($translation === null)
            <x-filament::callout color="gray" icon="heroicon-m-minus-circle" :heading="__('faq.preview.no_translation', ['language' => __('faq.locales.'.$this->locale)])" />
        @else
            <article class="fp-page" lang="{{ $this->locale }}">
                <nav class="fp-crumb" aria-label="{{ __('faq.preview.breadcrumb') }}">{{ __('faq.preview.help') }} › {{ $v['category'] }} › {{ $translation->question }}</nav>
                <h1 class="fp-title">{{ $translation->question }}</h1>
                <p class="fp-summary">{{ $translation->summary }}</p>
                <p class="fp-meta">{{ __('faq.preview.updated', ['date' => $fmt($translation->updated_at)]) }}</p>

                @if ($v['headings'])
                    <ol class="fp-toc" aria-label="{{ __('faq.preview.in_this_article') }}">
                        @foreach ($v['headings'] as $heading)
                            <li>{{ $heading['text'] }}</li>
                        @endforeach
                    </ol>
                @endif

                <div class="fp-body">{!! $v['html'] !!}</div>

                <div class="fp-helpful" aria-hidden="true">{{ __('faq.preview.helpful') }}</div>

                @if ($v['related'])
                    <div class="fp-related">
                        <strong>{{ __('faq.preview.related') }}</strong>
                        <ul class="fp-body">
                            @foreach ($v['related'] as $related)
                                <li>{{ $related }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </article>
        @endif
    </div>
</x-filament-panels::page>
