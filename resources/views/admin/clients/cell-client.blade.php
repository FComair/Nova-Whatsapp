@php
    $record = $getRecord();
    $initials = collect(preg_split('/\s+/', trim((string) $record->name)))->filter()->take(2)->map(fn (string $word) => mb_strtoupper(mb_substr($word, 0, 1)))->implode('');
@endphp

<div style="display: flex; align-items: center; gap: .6rem; min-width: 12rem">
    <span aria-hidden="true" style="flex: none; display: inline-flex; width: 2.1rem; height: 2.1rem; align-items: center; justify-content: center; border-radius: .6rem; background: #fef3c7; color: #92400e; font-weight: 700; font-size: .8rem">{{ $initials }}</span>
    <span style="display: grid; line-height: 1.25">
        <span style="font-weight: 600">{{ $record->name }}</span>
        <span style="font-size: .75rem; opacity: .7">{{ substr((string) $record->id, 0, 8) }}@if ($record->slug) · {{ $record->slug }} @endif</span>
    </span>
</div>
