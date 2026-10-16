{{-- Aperçu d'un modèle WhatsApp avec des données d'exemple (docs/39) : rendu, version envoyée à Meta, problèmes éventuels. --}}
<div class="space-y-5">
    <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('message_templates.preview.sample') }}</p>

    @forelse ($rows as $row)
        <section class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
            <h3 class="text-sm font-semibold uppercase tracking-wide">{{ __('message_templates.languages.'.$row['language']) }}</h3>

            <div class="mt-3 max-w-md whitespace-pre-line rounded-2xl rounded-tl-sm bg-emerald-50 px-4 py-3 text-sm text-emerald-950 dark:bg-emerald-950/40 dark:text-emerald-50">{{ $row['preview'] }}</div>

            <details class="mt-3 text-xs text-gray-600 dark:text-gray-300">
                <summary class="cursor-pointer font-medium">{{ __('message_templates.preview.meta_text') }}</summary>
                <p class="mt-2 whitespace-pre-line font-mono">{{ $row['meta'] }}</p>
            </details>

            @if ($row['problems'] !== [])
                <ul class="mt-3 list-disc ps-5 text-sm text-danger-600 dark:text-danger-400">
                    @foreach ($row['problems'] as $problem)
                        <li>{{ $problem }}</li>
                    @endforeach
                </ul>
            @else
                <p class="mt-3 text-xs text-success-600 dark:text-success-400">{{ __('message_templates.preview.valid') }}</p>
            @endif
        </section>
    @empty
        <p class="text-sm">{{ __('message_templates.errors.no_translation') }}</p>
    @endforelse
</div>
