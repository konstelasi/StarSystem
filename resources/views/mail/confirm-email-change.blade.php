<x-mail::message>
# Confirm your new email

Someone asked to use {{ $email }} for the account on {{ $site }}. If that was you, confirm it with the button below.

<x-mail::button :url="$link">
Confirm this email
</x-mail::button>

The link works for an hour. Until you open it, nothing changes. If you did not ask for this, you can ignore the email.
</x-mail::message>
