<x-mail::message>
# Link Enterprise SSO for {{ $tenant->name }}

{{ $inviterName }} invited you to connect your Enterprise identity for **{{ $tenant->name }}**.

You will sign in with your existing Jabal account first, then complete Enterprise SSO enrollment.

<x-mail::button :url="$enrollmentUrl">
Continue enrollment
</x-mail::button>

This invitation expires on {{ $expiresAt->format('F j, Y \a\t g:i A T') }}.

If you did not expect this message, you can ignore it.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
