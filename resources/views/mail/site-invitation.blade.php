<x-mail::message>
# You are invited to {{ $siteName }}

{{ $inviterName }} invited you to {{ $siteName }} as {{ $roleName }}.

<x-mail::button :url="$link">
Accept the invitation
</x-mail::button>

The link works until {{ $expiresAt->toFormattedDayDateString() }} and only once. If you were not expecting this, you can ignore the email and nothing changes.
</x-mail::message>
