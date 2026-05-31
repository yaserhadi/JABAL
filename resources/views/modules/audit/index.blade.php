<x-audit::layouts.master>
    <h1>Audit Logs</h1>
    <form method="GET" action="{{ route('platform.audit.index') }}" style="margin-bottom:1rem">
        <input type="text" name="event" placeholder="Event" value="{{ request('event') }}">
        <input type="text" name="auditable_type" placeholder="Model" value="{{ request('auditable_type') }}">
        <input type="date" name="from" value="{{ request('from') }}">
        <input type="date" name="to" value="{{ request('to') }}">
        <button type="submit">Filter</button>
    </form>
    <table>
        <thead>
            <tr>
                <th>Created</th>
                <th>Event</th>
                <th>Auditable</th>
                <th>Actor</th>
                <th>Tenant</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @foreach($logs as $log)
                <tr>
                    <td>{{ $log->created_at->format('Y-m-d H:i') }}</td>
                    <td>{{ $log->event }}</td>
                    <td>{{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}</td>
                    <td>{{ $log->actor_id }}</td>
                    <td>{{ $log->tenant_id ?? '-' }}</td>
                    <td><a href="{{ route('platform.audit.show', $log->id) }}">View</a></td>
                </tr>
            @endforeach
        </tbody>
    </table>
    {{ $logs->withQueryString()->links() }}
</x-audit::layouts.master>
