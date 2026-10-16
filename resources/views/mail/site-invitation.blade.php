<x-mail::message>
# You are invited to {{ $site }}

{{ $inviter }} invited you to {{ $site }} as {{ $role }}.

<x-mail::button :url="$link">
Accept the invitation
</x-mail::button>

The link works until {{ $expiresAt->toFormattedDayDateString() }} and only once. If you were not expecting this, you can ignore the email and nothing changes.
</x-mail::message>
