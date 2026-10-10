<x-filament-panels::page>
    @include('admin.legal.styles')
    @php
        $links = $this->links();
        $current = $this->current();
        $versions = $this->versions();
    @endphp

    <div class="nl">
        <a class="nl-back" href="{{ $links['back'] }}">← {{ __('admin.legal_files.back.legal') }}</a>

        <section class="nl-card" aria-labelledby="nl-current">
            <h2 id="nl-current">{{ __('admin.legal_files.current') }}</h2>
            @if ($current === null)
                <p class="nl-empty">{{ __('admin.legal_files.none') }}</p>
            @else
                <span class="nl-badge nl-badge--current">{{ __('admin.legal_files.states.current') }}</span>
                @include('admin.legal.version-meta', ['row' => $current])
                <div class="nl-actions">
                    @if ($current['public_url'])
                        <a class="nl-btn" href="{{ $current['public_url'] }}" target="_blank" rel="noopener">{{ __('admin.legal_files.actions.view') }}<span class="sr-only"> {{ __('admin.legal_files.new_tab') }}</span></a>
                    @endif
                    @if ($current['has_file'])
                        <a class="nl-btn" href="{{ route('filament.admin.legal.file', ['document' => $current['document'], 'version' => $current['id']]) }}">{{ __('admin.legal_files.actions.download') }}</a>
                    @endif
                </div>
            @endif
            <div class="nl-actions">
                <a class="nl-btn nl-btn--primary" href="{{ $links['upload'] }}">{{ __('admin.legal_files.actions.upload') }}</a>
            </div>
        </section>

        <section class="nl-card" aria-labelledby="nl-recent">
            <h2 id="nl-recent">{{ __('admin.legal_files.recent') }}</h2>
            @include('admin.legal.versions-table', ['versions' => $versions])
            @if ($this->total() > count($versions))
                <p class="nl-note">{{ __('admin.legal_files.recent_more', ['count' => $this->total() - count($versions)]) }}</p>
            @endif
            <div class="nl-actions"><a class="nl-btn" href="{{ $links['history'] }}">{{ __('admin.legal_files.actions.history', ['count' => $this->total()]) }}</a></div>
        </section>
    </div>
</x-filament-panels::page>
