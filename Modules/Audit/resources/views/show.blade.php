<x-audit::layouts.master>
    <h1>Audit Log #{{ $log->id }}</h1>
    <p><strong>Event:</strong> {{ $log->event }}</p>
    <p><strong>Created:</strong> {{ $log->created_at->format('Y-m-d H:i:s') }}</p>
    <p><strong>Auditable:</strong> {{ $log->auditable_type }} #{{ $log->auditable_id }}</p>
    <p><strong>Actor:</strong> {{ $log->actor_type }} #{{ $log->actor_id ?? '-' }}</p>
    <p><strong>Tenant:</strong> {{ $log->tenant_id ?? '-' }}</p>
    @if($log->metadata)
        <p><strong>Metadata:</strong></p>
        <pre>{{ json_encode($log->metadata, JSON_PRETTY_PRINT) }}</pre>
    @endif
    @if($log->old_values || $log->new_values)
        <h2>Changes</h2>
        <table>
            <thead>
                <tr><th>Field</th><th>Old</th><th>New</th></tr>
            </thead>
            <tbody>
                @php
                    $keys = array_unique(array_merge(
                        array_keys($log->old_values ?? []),
                        array_keys($log->new_values ?? [])
                    ));
                @endphp
                @foreach($keys as $key)
                    <tr>
                        <td>{{ $key }}</td>
                        <td>{{ json_encode(data_get($log->old_values, $key)) }}</td>
                        <td>{{ json_encode(data_get($log->new_values, $key)) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
    <p><a href="{{ route('platform.audit.index') }}">Back to list</a></p>
</x-audit::layouts.master>
