<x-mail::message>
# You're invited to join {{ $tenant->name }}

{{ $inviterName }} has invited you to join **{{ $tenant->name }}** as **{{ $role }}**.

<x-mail::button :url="$acceptUrl">
Accept invitation
</x-mail::button>

This invitation expires on {{ $expiresAt->format('F j, Y \a\t g:i A T') }}.

If you did not expect this invitation, you can ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
