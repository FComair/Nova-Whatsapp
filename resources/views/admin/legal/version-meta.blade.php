{{-- Fiche d'une version : $row (voir LegalDocumentFiles::describe) --}}
@php
    $size = $row['file_size'] === null ? null : number_format($row['file_size'] / 1048576, $row['file_size'] < 1048576 ? 2 : 1, ',', ' ').' '.__('admin.legal_files.units.mb');
    $date = fn (?string $iso) => $iso === null ? '—' : \Illuminate\Support\Carbon::parse($iso)->timezone(config('app.timezone'))->format('d/m/Y H:i');
@endphp
<dl class="nl-meta">
    <div><dt>{{ __('admin.legal_files.columns.file') }}</dt><dd>{{ $row['has_file'] ? $row['file_name'] : __('admin.legal_files.text_version') }}</dd></div>
    <div><dt>{{ __('admin.legal_files.columns.version') }}</dt><dd>{{ $row['version'] }}</dd></div>
    <div><dt>{{ __('admin.legal_files.columns.size') }}</dt><dd>{{ $size ?? '—' }}</dd></div>
    <div><dt>{{ __('admin.legal_files.columns.effective') }}</dt><dd>{{ \Illuminate\Support\Carbon::parse($row['effective_on'])->format('d/m/Y') }}</dd></div>
    <div><dt>{{ __('admin.legal_files.columns.updated') }}</dt><dd>{{ $date($row['updated_at']) }}</dd></div>
    <div><dt>{{ __('admin.legal_files.columns.uploaded_by') }}</dt><dd>{{ $row['uploaded_by'] ?? '—' }}</dd></div>
</dl>
