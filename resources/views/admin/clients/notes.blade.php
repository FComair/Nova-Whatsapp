@php
    $notes = $profile->notes($org);
    $tags = $profile->tags($org);
@endphp

@include('admin.clients.styles')

<div class="cp">
    <section aria-labelledby="cn-tags">
        <h3 id="cn-tags">{{ __('clients.notes.tags') }}</h3>
        @if ($tags === [])
            <p style="color: var(--cp-muted); margin: 0">{{ __('clients.notes.no_tags') }}</p>
        @else
            <p style="display: flex; flex-wrap: wrap; gap: .4rem; margin: 0">
                @foreach ($tags as $tag)<span class="cp-chip">{{ $tag }}</span>@endforeach
            </p>
        @endif
        <p style="color: var(--cp-muted); margin: .4rem 0 0">{{ __('clients.notes.tags_help') }}</p>
    </section>

    <section aria-labelledby="cn-notes">
        <h3 id="cn-notes">{{ __('clients.notes.title') }}</h3>
        <p style="color: var(--cp-muted); margin: 0 0 .5rem">{{ __('clients.notes.internal') }}</p>
        @if ($notes === [])
            <p style="margin: 0">{{ __('clients.notes.empty') }}</p>
        @else
            <ul class="cp-list">
                @foreach ($notes as $note)
                    <li class="cp-note">
                        <header>{{ $note['author'] }} · <time datetime="{{ $note['at']->toIso8601String() }}">{{ $note['at']->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</time></header>
                        <p>{{ $note['body'] }}</p>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
