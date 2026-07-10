<?php

declare(strict_types=1);

namespace Plugins\Validation\Rules;

/**
 * FinancialRules — a themed rule-set for money/payment fields (mirrors
 * CodeIgniter's separate CreditCardRules class). Register with
 * `Validator::extendWith(FinancialRules::class)` or via config/validation.php.
 *
 * Rule signature: (mixed $value, ?string $param, array<string,mixed> $data): bool
 */
final class FinancialRules
{
    /** Raw Luhn (mod-10) checksum over the digits in the value. */
    public function luhn(mixed $v, ?string $p, array $d): bool
    {
        $digits = \preg_replace('/\D/', '', (string) $v);

        return $digits !== '' && self::passesLuhn($digits);
    }

    /** Credit-card number: 12–19 digits AND a valid Luhn checksum. */
    public function credit_card(mixed $v, ?string $p, array $d): bool
    {
        $digits = \preg_replace('/\D/', '', (string) $v);
        $len = \strlen((string) $digits);

        return $len >= 12 && $len <= 19 && self::passesLuhn($digits);
    }

    /** CVV / CVC — 3 or 4 digits. */
    public function cvv(mixed $v, ?string $p, array $d): bool
    {
        return \preg_match('/^[0-9]{3,4}$/', (string) $v) === 1;
    }

    /** IBAN — format + ISO 7064 mod-97 checksum. */
    public function iban(mixed $v, ?string $p, array $d): bool
    {
        $iban = \strtoupper(\preg_replace('/\s+/', '', (string) $v));
        if (\preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban) !== 1) {
            return false;
        }

        // Move the first 4 chars to the end, then replace letters with 10–35.
        $rearranged = \substr($iban, 4) . \substr($iban, 0, 4);
        $numeric = '';
        foreach (\str_split($rearranged) as $char) {
            $numeric .= \ctype_alpha($char) ? (string) (\ord($char) - 55) : $char;
        }

        // mod 97 over a long numeric string, chunked to stay in int range.
        $remainder = 0;
        foreach (\str_split($numeric, 7) as $chunk) {
            $remainder = (int) (($remainder . $chunk) % 97);
        }

        return $remainder === 1;
    }

    /** SWIFT/BIC — 8 or 11 alphanumerics (AAAA BB CC [DDD]). */
    public function bic(mixed $v, ?string $p, array $d): bool
    {
        return \preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}(?:[A-Z0-9]{3})?$/', \strtoupper((string) $v)) === 1;
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return [
            'luhn'        => 'The :field field failed its checksum.',
            'credit_card' => 'The :field field must be a valid card number.',
            'cvv'         => 'The :field field must be a 3 or 4 digit security code.',
            'iban'        => 'The :field field must be a valid IBAN.',
            'bic'         => 'The :field field must be a valid BIC/SWIFT code.',
        ];
    }

    /** Luhn (mod-10) over a pure-digit string. */
    private static function passesLuhn(string $digits): bool
    {
        $sum = 0;
        $alt = false;
        for ($i = \strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $alt = !$alt;
        }

        return $sum % 10 === 0;
    }
}
