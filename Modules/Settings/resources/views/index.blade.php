<x-settings::layouts.master>
    <h1>Platform Settings</h1>
    @if (session('message'))
        <p>{{ session('message') }}</p>
    @endif
    <table>
        <thead>
            <tr><th>Key</th><th>Value</th><th>Action</th></tr>
        </thead>
        <tbody>
            @forelse($settings as $key => $value)
                <tr>
                    <td>{{ $key }}</td>
                    <td>{{ is_scalar($value) ? $value : json_encode($value) }}</td>
                    <td>
                        <form action="{{ route('platform.settings.update', $key) }}" method="POST" style="display:inline">
                            @csrf
                            @method('PUT')
                            <input type="text" name="value" value="{{ is_scalar($value) ? $value : '' }}" placeholder="New value">
                            <button type="submit">Update</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="3">No settings.</td></tr>
            @endforelse
        </tbody>
    </table>
</x-settings::layouts.master>
