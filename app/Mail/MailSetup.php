<?php

namespace App\Mail;

/**
 * Whether mail leaves this install at all. Many shared hosts have none, so
 * every feature that sends a link also has to work without one.
 */
final class MailSetup
{
    /**
     * The `log` mailer writes to a file and `array` keeps it in memory, so
     * neither reaches a person.
     */
    public static function configured(): bool
    {
        return ! in_array(config('mail.default'), ['log', 'array'], true);
    }
}
