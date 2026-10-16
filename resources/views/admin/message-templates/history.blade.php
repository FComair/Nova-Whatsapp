{{-- Historique d'un modèle WhatsApp (docs/39) : journal en ajout seul. Heures en UTC, explicitement. --}}
@if ($events === [])
    <p class="text-sm">{{ __('message_templates.history.empty') }}</p>
@else
    <ol class="space-y-3">
        @foreach ($events as $event)
            <li class="rounded-xl border border-gray-200 p-3 text-sm dark:border-white/10">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <strong>{{ $event['action'] }}</strong>
                    <time class="text-xs text-gray-500 dark:text-gray-400">{{ $event['when'] }}</time>
                </div>
                @if ($event['from'] || $event['to'])
                    <p class="mt-1 text-gray-600 dark:text-gray-300">{{ $event['from'] ?? '—' }} → {{ $event['to'] ?? '—' }}</p>
                @endif
                @if ($event['reason'])
                    <p class="mt-1">{{ __('message_templates.history.reason') }} : {{ $event['reason'] }}</p>
                @endif
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('message_templates.history.by', ['actor' => $event['actor']]) }}</p>
            </li>
        @endforeach
    </ol>
@endif
