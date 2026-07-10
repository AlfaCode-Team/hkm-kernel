<?php

declare(strict_types=1);

namespace Plugins\Mail\Domain;

/**
 * An email address with an optional display name.
 *
 * SECURITY: the constructor is the header-injection choke point. Any CR/LF (or
 * NUL) in the address or name is rejected — this is what stops an attacker from
 * smuggling extra headers ("BCC: victim") through a user-supplied address. The
 * email is also format-validated. Every recipient/sender in a Message passes
 * through here, so injection cannot enter the MIME stream.
 */
final readonly class Address
{
    public string $email;
    public string $name;

    public function __construct(string $email, string $name = '')
    {
        $email = trim($email);

        if ($email === '' || self::hasControlChars($email) || self::hasControlChars($name)) {
            throw new MailException('Invalid e-mail address (control characters are not allowed).');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new MailException("Invalid e-mail address: {$email}");
        }

        $this->email = $email;
        $this->name  = trim($name);
    }

    /** RFC 5322 header form: `"Name" <email>` (name MIME-encoded when non-ASCII). */
    public function toHeader(string $charset = 'UTF-8'): string
    {
        if ($this->name === '') {
            return $this->email;
        }

        $name = preg_match('/[^\x20-\x7E]/', $this->name) === 1
            ? mb_encode_mimeheader($this->name, $charset, 'B', "\r\n")      // chunked encoded-words
            : '"' . addcslashes($this->name, '"\\') . '"';

        return $name . ' <' . $this->email . '>';
    }

    private static function hasControlChars(string $value): bool
    {
        return preg_match('/[\r\n\t\x00]/', $value) === 1;
    }
}
