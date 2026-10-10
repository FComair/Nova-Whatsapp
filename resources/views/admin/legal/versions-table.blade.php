{{-- Tableau de versions : $versions (voir LegalDocumentFiles::history) --}}
@if ($versions === [])
    <p class="nl-empty">{{ __('admin.legal_files.history_empty') }}</p>
@else
    <div class="nl-table-wrap">
        <table>
            <caption class="sr-only">{{ __('admin.legal_files.history_title') }}</caption>
            <thead>
                <tr>
                    <th scope="col">{{ __('admin.legal_files.columns.version') }}</th>
                    <th scope="col">{{ __('admin.legal_files.columns.status') }}</th>
                    <th scope="col">{{ __('admin.legal_files.columns.file') }}</th>
                    <th scope="col">{{ __('admin.legal_files.columns.size') }}</th>
                    <th scope="col">{{ __('admin.legal_files.columns.updated') }}</th>
                    <th scope="col">{{ __('admin.legal_files.columns.uploaded_by') }}</th>
                    <th scope="col">{{ __('admin.legal_files.columns.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($versions as $row)
                    @php
                        $size = $row['file_size'] === null ? '—' : number_format($row['file_size'] / 1048576, $row['file_size'] < 1048576 ? 2 : 1, ',', ' ').' '.__('admin.legal_files.units.mb');
                    @endphp
                    <tr>
                        <th scope="row" style="font-weight: 600; color: inherit; font-size: inherit">{{ $row['version'] }}</th>
                        <td><span class="nl-badge nl-badge--{{ $row['state'] }}">{{ __('admin.legal_files.states.'.$row['state']) }}</span></td>
                        <td>{{ $row['has_file'] ? $row['file_name'] : __('admin.legal_files.text_version') }}</td>
                        <td>{{ $size }}</td>
                        <td>{{ $row['updated_at'] ? \Illuminate\Support\Carbon::parse($row['updated_at'])->timezone(config('app.timezone'))->format('d/m/Y H:i') : '—' }}</td>
                        <td>{{ $row['uploaded_by'] ?? '—' }}</td>
                        <td>
                            @if ($row['has_file'])
                                <a class="nl-btn" style="min-height: 2rem" href="{{ route('filament.admin.legal.file', ['document' => $row['document'], 'version' => $row['id']]) }}">{{ __('admin.legal_files.actions.download') }}<span class="sr-only"> {{ $row['version'] }}</span></a>
                            @elseif ($row['public_url'])
                                <a class="nl-btn" style="min-height: 2rem" href="{{ $row['public_url'] }}" target="_blank" rel="noopener">{{ __('admin.legal_files.actions.view') }}<span class="sr-only"> {{ $row['version'] }} {{ __('admin.legal_files.new_tab') }}</span></a>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
