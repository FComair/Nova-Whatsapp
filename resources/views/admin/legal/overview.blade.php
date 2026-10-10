<x-filament-panels::page>
    @include('admin.legal.styles')
    @php $links = $this->links(); @endphp

    <div class="nl">
        <a class="nl-back" href="{{ $links['back'] }}">← {{ __('admin.legal_files.back.dashboard') }}</a>

        <div class="nl-grid">
            @foreach ($this->documents() as $doc)
                @php
                    $current = $doc['current'];
                    $params = ['document' => $doc['code']];
                @endphp
                <section class="nl-card" aria-labelledby="doc-{{ $doc['code'] }}">
                    <h2 id="doc-{{ $doc['code'] }}">{{ $doc['name'] }}</h2>
                    @if ($current === null)
                        <p class="nl-empty">{{ __('admin.legal_files.none') }}</p>
                    @else
                        <span class="nl-badge nl-badge--current">{{ __('admin.legal_files.states.current') }}</span>
                        @include('admin.legal.version-meta', ['row' => $current])
                    @endif
                    <div class="nl-actions">
                        <a class="nl-btn nl-btn--primary" href="{{ \App\Modules\Admin\Filament\Pages\Legal\LegalDocumentUpload::getUrl($params) }}">{{ __('admin.legal_files.actions.upload') }}</a>
                        <a class="nl-btn" href="{{ \App\Modules\Admin\Filament\Pages\Legal\LegalDocumentDetail::getUrl($params) }}">{{ __('admin.legal_files.actions.open') }}</a>
                        <a class="nl-btn" href="{{ \App\Modules\Admin\Filament\Pages\Legal\LegalDocumentHistory::getUrl($params) }}">{{ __('admin.legal_files.actions.history', ['count' => $doc['versions']]) }}</a>
                    </div>
                </section>
            @endforeach
        </div>

        <p class="nl-note">
            {{ __('admin.legal_files.texts_note') }}
            <a href="{{ $links['texts'] }}" style="text-decoration: underline">{{ __('admin.legal_files.actions.texts') }}</a>
        </p>
    </div>
</x-filament-panels::page>
