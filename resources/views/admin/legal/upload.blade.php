<x-filament-panels::page>
    @include('admin.legal.styles')
    @php $links = $this->links(); @endphp

    {{-- Avertit avant de quitter la page avec un fichier choisi mais non déposé (liens « Retour » et « Annuler », fermeture, bouton Précédent du navigateur). --}}
    <div
        class="nl"
        x-data="{ dirty: false, message: @js(__('admin.legal_files.leave')) }"
        x-init="
            window.rendivooLegalLeft = false;
            $wire.$watch('data.file', (value) => { dirty = !!value && Object.keys(value).length > 0 });
            window.addEventListener('beforeunload', (event) => { if (dirty && !window.rendivooLegalLeft) { event.preventDefault(); event.returnValue = ''; } });
        "
    >
        <a class="nl-back" href="{{ $links['back'] }}" @click="if (dirty && !confirm(message)) { $event.preventDefault() }">← {{ __('admin.legal_files.back.document', ['name' => $this->documentName()]) }}</a>

        <section class="nl-card" aria-labelledby="nl-upload">
            <h2 id="nl-upload">{{ __('admin.legal_files.upload.title', ['name' => $this->documentName()]) }}</h2>
            <p class="nl-note">{{ __('admin.legal_files.upload.intro') }}</p>

            <form wire:submit.prevent data-unsaved-ignore>{{ $this->form }}</form>

            <div class="nl-actions">
                <a class="nl-btn" href="{{ $links['back'] }}" @click="if (dirty && !confirm(message)) { $event.preventDefault() }">{{ __('admin.legal_files.cancel') }}</a>
            </div>
            <p class="nl-note">{{ __('admin.legal_files.upload.replace_hint') }}</p>
        </section>
    </div>
</x-filament-panels::page>
