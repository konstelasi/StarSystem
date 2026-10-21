<x-mail::message>
# Confirm your email

The account on {{ $site }} uses {{ $email }}, and that address has not been confirmed yet. Confirm it with the button below to carry on.

<x-mail::button :url="$link">
Confirm this email
</x-mail::button>

The link works for an hour. If you did not expect this, you can ignore the email.
</x-mail::message>
