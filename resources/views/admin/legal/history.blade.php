<x-filament-panels::page>
    @include('admin.legal.styles')
    @php $links = $this->links(); @endphp

    <div class="nl">
        <a class="nl-back" href="{{ $links['back'] }}">← {{ __('admin.legal_files.back.document', ['name' => $this->documentName()]) }}</a>

        <section class="nl-card" aria-labelledby="nl-history">
            <h2 id="nl-history">{{ __('admin.legal_files.history_title') }}</h2>
            <p class="nl-note">{{ __('admin.legal_files.archived_note') }}</p>
            @include('admin.legal.versions-table', ['versions' => $this->versions()])
            <div class="nl-actions"><a class="nl-btn nl-btn--primary" href="{{ $links['upload'] }}">{{ __('admin.legal_files.actions.upload') }}</a></div>
        </section>
    </div>
</x-filament-panels::page>
