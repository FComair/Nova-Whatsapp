@php
    use App\Modules\Admin\Filament\Resources\SupportTicketResource;
    use App\Modules\Support\Support\TicketStatus;

    $summary = $this->summary();
    $ticket = $summary['ticket'];
    $statusStyle = SupportTicketResource::STATUS_STYLE[$ticket->status] ?? ['gray', 'heroicon-m-question-mark-circle'];
    $priority = $ticket->priority();
    $priorityStyle = SupportTicketResource::PRIORITY_STYLE[$priority] ?? ['gray', 'heroicon-m-minus'];
    $fmt = fn ($date) => $date?->timezone(config('app.timezone'))->format('d/m/Y H:i');
@endphp

<x-filament-panels::page>
    <style>
        .nt { --nt-border: #e5e7eb; --nt-muted: #6b7280; --nt-client-bg: #f3f4f6; --nt-staff-bg: #ecfdf5; --nt-staff-border: #a7f3d0; --nt-note-bg: #fffbeb; --nt-note-border: #f59e0b; --nt-text: #111827; display: grid; gap: 1.25rem; }
        .dark .nt { --nt-border: #374151; --nt-muted: #9ca3af; --nt-client-bg: #1f2937; --nt-staff-bg: #064e3b; --nt-staff-border: #047857; --nt-note-bg: #3b2a05; --nt-note-border: #b45309; --nt-text: #f3f4f6; }
        .nt-card { border: 1px solid var(--nt-border); border-radius: .75rem; padding: 1rem 1.25rem; }
        .nt-meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); gap: .75rem 1.5rem; margin: 0; }
        .nt-meta dt { font-size: .75rem; color: var(--nt-muted); text-transform: uppercase; letter-spacing: .03em; }
        .nt-meta dd { margin: .1rem 0 0; font-size: .875rem; overflow-wrap: anywhere; }
        .nt-row { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
        .nt-thread { display: grid; gap: .75rem; list-style: none; margin: 0; padding: 0; }
        .nt-msg { border: 1px solid var(--nt-border); border-radius: .75rem; padding: .75rem 1rem; max-width: 52rem; }
        .nt-msg--client { background: var(--nt-client-bg); }
        .nt-msg--staff { background: var(--nt-staff-bg); border-color: var(--nt-staff-border); margin-inline-start: auto; }
        .nt-msg--internal { background: var(--nt-note-bg); border: 2px dashed var(--nt-note-border); margin-inline-start: auto; }
        .nt-msg header { display: flex; flex-wrap: wrap; gap: .25rem .75rem; justify-content: space-between; font-size: .75rem; color: var(--nt-muted); margin-bottom: .35rem; }
        .nt-msg header strong { color: var(--nt-text); }
        .nt-body { white-space: pre-wrap; overflow-wrap: anywhere; font-size: .9rem; line-height: 1.5; }
        .nt-flag { display: inline-flex; gap: .25rem; align-items: center; font-weight: 600; }
        .nt-event { font-size: .8rem; color: var(--nt-muted); text-align: center; }
        .nt-files { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .5rem; padding: 0; list-style: none; }
        .nt-files a { font-size: .8rem; text-decoration: underline; }
        .nt-composer--internal { border: 2px dashed var(--nt-note-border); background: var(--nt-note-bg); }
        .nt-composer--reply { border: 2px solid var(--nt-staff-border); }
        .nt-chip { display: inline-flex; gap: .25rem; align-items: center; border: 1px solid var(--nt-border); border-radius: 999px; padding: .1rem .6rem; font-size: .8rem; }
        .nt-chip button { background: none; border: 0; cursor: pointer; font-size: 1rem; line-height: 1; color: inherit; }
        .nt h2 { font-size: .95rem; font-weight: 600; margin: 0 0 .75rem; }
        .nt-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
    </style>

    <div class="nt" wire:poll.30s="checkForChanges">
        @if ($stale)
            <x-filament::callout color="warning" icon="heroicon-m-exclamation-triangle" :heading="__('tickets.stale.title')" :description="__('tickets.stale.body')">
                <x-slot name="controls">
                    <x-filament::button size="sm" color="warning" wire:click="refreshTicket">{{ __('tickets.stale.refresh') }}</x-filament::button>
                </x-slot>
            </x-filament::callout>
        @endif

        <section class="nt-card" aria-labelledby="nt-head">
            <h2 id="nt-head" class="nt-sr">{{ __('tickets.detail.header') }}</h2>
            <div class="nt-row" style="margin-bottom: .9rem">
                <x-filament::badge :color="$statusStyle[0]" :icon="$statusStyle[1]">{{ __('tickets.statuses.'.$ticket->status) }}</x-filament::badge>
                <x-filament::badge :color="$priorityStyle[0]" :icon="$priorityStyle[1]">{{ __('tickets.priority_label', ['priority' => __('tickets.priorities.'.$priority)]) }}</x-filament::badge>
                @if ($summary['sla'] === 'overdue')
                    <x-filament::badge color="danger" icon="heroicon-m-exclamation-circle">{{ __('tickets.attention.overdue') }}</x-filament::badge>
                @elseif ($summary['sla'] === 'at_risk')
                    <x-filament::badge color="warning" icon="heroicon-m-clock">{{ __('tickets.attention.at_risk') }}</x-filament::badge>
                @endif
                @if ($ticket->needsResponse())
                    <x-filament::badge color="info" icon="heroicon-m-chat-bubble-left-ellipsis">{{ __('tickets.attention.needs_response') }}</x-filament::badge>
                @endif
                @foreach ($summary['tags'] as $tag)
                    <span class="nt-chip"><x-filament::icon icon="heroicon-m-tag" style="width:.9rem;height:.9rem" />{{ $tag }}
                        <button type="button" wire:click="removeTag(@js($tag))" aria-label="{{ __('tickets.tag_remove', ['tag' => $tag]) }}">×</button></span>
                @endforeach
            </div>

            <dl class="nt-meta">
                <div><dt>{{ __('tickets.columns.client') }}</dt><dd>
                    @if ($summary['organization_url']) <a href="{{ $summary['organization_url'] }}" style="text-decoration: underline">{{ $summary['organization'] }}</a> @else {{ $summary['organization'] }} @endif
                </dd></div>
                <div><dt>{{ __('tickets.columns.requester') }}</dt><dd>{{ $ticket->reply_email }} · {{ strtoupper($ticket->locale) }}</dd></div>
                <div><dt>{{ __('tickets.columns.assignee') }}</dt><dd>{{ $summary['assignee'] ?? __('tickets.unassigned') }}</dd></div>
                <div><dt>{{ __('tickets.columns.category') }}</dt><dd>{{ __('tickets.categories.'.$ticket->category) }}@if ($ticket->related_to) · {{ __('tickets.related.'.$ticket->related_to) }} @endif</dd></div>
                <div><dt>{{ __('tickets.columns.source') }}</dt><dd>{{ __('tickets.sources.'.$ticket->source) }}</dd></div>
                <div><dt>{{ __('tickets.columns.created') }}</dt><dd>{{ $fmt($ticket->created_at) }}</dd></div>
                <div><dt>{{ __('tickets.detail.updated') }}</dt><dd>{{ $fmt($ticket->last_message_at) }}</dd></div>
                <div><dt>{{ __('tickets.detail.first_response') }}</dt><dd>
                    @if ($ticket->first_response_at) {{ $fmt($ticket->first_response_at) }}
                    @elseif ($ticket->first_response_due_at) {{ __('tickets.detail.due', ['date' => $fmt($ticket->first_response_due_at)]) }}
                    @else — @endif
                </dd></div>
                <div><dt>{{ __('tickets.detail.resolved') }}</dt><dd>{{ $fmt($ticket->resolved_at) ?? '—' }}</dd></div>
                <div><dt>{{ __('tickets.detail.closed') }}</dt><dd>{{ $fmt($ticket->closed_at) ?? '—' }}</dd></div>
            </dl>

            @if ($summary['links']->isNotEmpty())
                <div style="margin-top: .9rem">
                    <strong style="font-size:.75rem; color: var(--nt-muted); text-transform: uppercase">{{ __('tickets.detail.related') }}</strong>
                    <div class="nt-row" style="margin-top:.35rem">
                        @foreach ($summary['links'] as $link)
                            <span class="nt-chip">{{ __('tickets.link.kinds.'.$link->kind) }} · {{ $link->label }}
                                <button type="button" wire:click="removeLink(@js($link->id))" aria-label="{{ __('tickets.link.remove', ['label' => $link->label]) }}">×</button></span>
                        @endforeach
                    </div>
                </div>
            @endif
        </section>

        <section aria-labelledby="nt-thread-title">
            <h2 id="nt-thread-title">{{ __('tickets.thread.title') }}</h2>
            <ol class="nt-thread">
                @foreach ($this->thread() as $item)
                    @if ($item['type'] === 'event')
                        <li class="nt-event">
                            <x-filament::icon :icon="$item['internal'] ? 'heroicon-m-lock-closed' : 'heroicon-m-arrow-path'" style="width:.9rem;height:.9rem;display:inline;vertical-align:-2px" />
                            {{ $item['text'] }} · <time datetime="{{ $item['at']->toIso8601String() }}">{{ $fmt($item['at']) }}</time>
                            @if ($item['internal']) <span>({{ __('tickets.thread.internal_event') }})</span> @endif
                        </li>
                    @else
                        <li class="nt-msg nt-msg--{{ $item['kind'] }}">
                            <header>
                                <span>
                                    <strong>{{ $item['author'] }}</strong>
                                    @if ($item['kind'] === 'internal')
                                        <span class="nt-flag"><x-filament::icon icon="heroicon-m-lock-closed" style="width:.9rem;height:.9rem" />{{ __('tickets.thread.internal_label') }}</span>
                                    @elseif ($item['kind'] === 'staff')
                                        <span class="nt-flag"><x-filament::icon icon="heroicon-m-paper-airplane" style="width:.9rem;height:.9rem" />{{ __('tickets.thread.sent_label') }}</span>
                                    @else
                                        <span>· {{ __('tickets.thread.client') }}</span>
                                    @endif
                                </span>
                                <time datetime="{{ $item['at']->toIso8601String() }}">{{ $fmt($item['at']) }}</time>
                            </header>
                            <div class="nt-body">{{ $item['body'] }}</div>
                            @if ($item['attachments'])
                                <ul class="nt-files" aria-label="{{ __('tickets.thread.attachments') }}">
                                    @foreach ($item['attachments'] as $file)
                                        <li><a href="{{ route('filament.admin.support.attachment', $file['id']) }}" download>
                                            <x-filament::icon icon="heroicon-m-paper-clip" style="width:.9rem;height:.9rem;display:inline" /> {{ $file['name'] }} ({{ max(1, round($file['size'] / 1024)) }} KB)</a></li>
                                    @endforeach
                                </ul>
                            @endif
                        </li>
                    @endif
                @endforeach
            </ol>
        </section>

        @if ($summary['closed'])
            <x-filament::callout color="gray" icon="heroicon-m-lock-closed" :heading="__('tickets.composer.closed_title')" :description="__('tickets.composer.closed_body')" />
        @else
            @php($internal = ($data['mode'] ?? 'reply') === 'internal')
            <section class="nt-card nt-composer--{{ $internal ? 'internal' : 'reply' }}" aria-labelledby="nt-composer-title">
                <h2 id="nt-composer-title">{{ $internal ? __('tickets.composer.internal_heading') : __('tickets.composer.reply_heading') }}</h2>
                <x-filament::callout :color="$internal ? 'warning' : 'success'" :icon="$internal ? 'heroicon-m-lock-closed' : 'heroicon-m-envelope'"
                    :heading="$internal ? __('tickets.composer.internal_banner') : __('tickets.composer.reply_banner', ['email' => $ticket->reply_email])" />
                <form wire:submit="submit" style="margin-top: 1rem">
                    {{ $this->form }}
                    <div class="nt-row" style="margin-top: 1rem">
                        <x-filament::button type="submit" :color="$internal ? 'warning' : 'success'" :icon="$internal ? 'heroicon-m-lock-closed' : 'heroicon-m-paper-airplane'" wire:loading.attr="disabled" wire:target="submit">
                            {{ $internal ? __('tickets.composer.save_note') : __('tickets.composer.send_reply') }}
                        </x-filament::button>
                    </div>
                </form>
            </section>
        @endif
    </div>
</x-filament-panels::page>
