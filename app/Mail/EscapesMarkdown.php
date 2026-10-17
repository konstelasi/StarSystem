<?php

namespace App\Mail;

/**
 * For mails whose body is markdown and which print text that people typed,
 * such as a name or an address. Escaping it keeps it plain text. Otherwise a
 * user named "[Sign in](https://evil.example)" would put a link in an email
 * that comes from this server.
 */
trait EscapesMarkdown
{
    private function plain(string $text): string
    {
        return (string) preg_replace('/([\\\\`*_{}\[\]()#+\-.!|<>~=:@&])/', '\\\\$1', $text);
    }
}
