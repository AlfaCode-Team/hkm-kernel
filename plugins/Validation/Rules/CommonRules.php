<?php

declare(strict_types=1);

namespace Plugins\Validation\Rules;

/**
 * CommonRules — a robust, universal rule-set (CodeIgniter-style: each public
 * method is a rule named after the method). Register the whole class with
 * `Validator::extendWith(CommonRules::class)` or via config/validation.php
 * `rulesets`.
 *
 * These EXTEND the Validator's built-ins (required, string, integer, numeric,
 * boolean, array, email, url, http_url, timezone, min, max, between, in, regex,
 * same, different, confirmed) — they never duplicate them. Cross-field
 * "required_if / required_with" style rules are intentionally omitted: the
 * engine skips value rules on absent fields, so a presence-forcing rule cannot
 * be expressed as an extension (use `required` + service-level checks instead).
 *
 * Rule signature: (mixed $value, ?string $param, array<string,mixed> $data): bool
 */
final class CommonRules
{
    // ── character classes ────────────────────────────────────────────────────

    /** Unicode letters only. */
    public function alpha(mixed $v, ?string $p, array $d): bool
    {
        return \is_string($v) && \preg_match('/^\p{L}+$/u', $v) === 1;
    }

    /** Unicode letters and numbers. */
    public function alpha_num(mixed $v, ?string $p, array $d): bool
    {
        return \is_string($v) && \preg_match('/^[\p{L}\p{N}]+$/u', $v) === 1;
    }

    /** Letters, numbers, dashes and underscores (slug-safe identifiers). */
    public function alpha_dash(mixed $v, ?string $p, array $d): bool
    {
        return \is_string($v) && \preg_match('/^[\p{L}\p{N}_-]+$/u', $v) === 1;
    }

    /** Letters and spaces (human names). */
    public function alpha_space(mixed $v, ?string $p, array $d): bool
    {
        return \is_string($v) && \preg_match('/^[\p{L} ]+$/u', $v) === 1;
    }

    /** Letters, numbers, spaces and common punctuation (free text, CI parity). */
    public function alpha_numeric_punct(mixed $v, ?string $p, array $d): bool
    {
        return \is_string($v)
            && \preg_match('/^[\p{L}\p{N} ~!#$%&*\-_+=|:.;,?@\'"\/()\[\]{}]+$/u', $v) === 1;
    }

    /** 7-bit ASCII only. */
    public function ascii(mixed $v, ?string $p, array $d): bool
    {
        return \is_string($v) && \preg_match('/^[\x00-\x7F]*$/', $v) === 1;
    }

    /** Already lowercase. */
    public function lowercase(mixed $v, ?string $p, array $d): bool
    {
        return \is_string($v) && \mb_strtolower($v) === $v;
    }

    /** Already uppercase. */
    public function uppercase(mixed $v, ?string $p, array $d): bool
    {
        return \is_string($v) && \mb_strtoupper($v) === $v;
    }

    // ── numbers / sizes ──────────────────────────────────────────────────────

    /** All digits; with `digits:n`, exactly n digits. */
    public function digits(mixed $v, ?string $p, array $d): bool
    {
        $s = (string) $v;
        if (\ctype_digit($s) === false) {
            return false;
        }
        return $p === null || \mb_strlen($s) === (int) $p;
    }

    /** `digits_between:a,b` — all digits, length within [a,b]. */
    public function digits_between(mixed $v, ?string $p, array $d): bool
    {
        $s = (string) $v;
        if (\ctype_digit($s) === false) {
            return false;
        }
        [$a, $b] = \array_pad(\explode(',', (string) $p), 2, '0');
        $len = \mb_strlen($s);
        return $len >= (int) $a && $len <= (int) $b;
    }

    /** `size:n` — string length / array count / number equals n. */
    public function size(mixed $v, ?string $p, array $d): bool
    {
        return $this->measure($v) === (float) $p;
    }

    /** `gt:n` — numeric/size strictly greater than n. */
    public function gt(mixed $v, ?string $p, array $d): bool
    {
        return $this->measure($v) > (float) $p;
    }

    /** `gte:n` — numeric/size greater than or equal to n. */
    public function gte(mixed $v, ?string $p, array $d): bool
    {
        return $this->measure($v) >= (float) $p;
    }

    /** `lt:n` — numeric/size strictly less than n. */
    public function lt(mixed $v, ?string $p, array $d): bool
    {
        return $this->measure($v) < (float) $p;
    }

    /** `lte:n` — numeric/size less than or equal to n. */
    public function lte(mixed $v, ?string $p, array $d): bool
    {
        return $this->measure($v) <= (float) $p;
    }

    /** Non-negative integer (0, 1, 2, …). */
    public function is_natural(mixed $v, ?string $p, array $d): bool
    {
        return \ctype_digit((string) $v);
    }

    /** Positive integer (1, 2, 3, …). */
    public function is_natural_no_zero(mixed $v, ?string $p, array $d): bool
    {
        return \ctype_digit((string) $v) && (int) $v > 0;
    }

    /** Hexadecimal string. */
    public function hex(mixed $v, ?string $p, array $d): bool
    {
        return \is_string($v) && $v !== '' && \ctype_xdigit($v);
    }

    /**
     * Decimal number. `decimal` = any; `decimal:2` = exactly 2 places;
     * `decimal:1,4` = between 1 and 4 places.
     */
    public function decimal(mixed $v, ?string $p, array $d): bool
    {
        if (\preg_match('/^-?\d+(?:\.(\d+))?$/', (string) $v, $m) !== 1) {
            return false;
        }
        if ($p === null) {
            return true;
        }
        $places = isset($m[1]) ? \strlen($m[1]) : 0;
        [$min, $max] = \array_pad(\explode(',', $p), 2, null);
        $max ??= $min;

        return $places >= (int) $min && $places <= (int) $max;
    }

    /** `multiple_of:n` — numeric and evenly divisible by n. */
    public function multiple_of(mixed $v, ?string $p, array $d): bool
    {
        if (!\is_numeric($v) || !\is_numeric($p) || (float) $p === 0.0) {
            return false;
        }
        return \fmod((float) $v, (float) $p) === 0.0;
    }

    /** `min_digits:n` — numeric with at least n digits. */
    public function min_digits(mixed $v, ?string $p, array $d): bool
    {
        $digits = \preg_replace('/\D/', '', (string) $v);
        return $digits !== '' && \strlen($digits) >= (int) $p;
    }

    /** `max_digits:n` — numeric with at most n digits. */
    public function max_digits(mixed $v, ?string $p, array $d): bool
    {
        $digits = \preg_replace('/\D/', '', (string) $v);
        return \strlen((string) $digits) <= (int) $p;
    }

    // ── strings / membership ─────────────────────────────────────────────────

    /** `starts_with:a,b,c` — begins with any of the listed prefixes. */
    public function starts_with(mixed $v, ?string $p, array $d): bool
    {
        $s = (string) $v;
        foreach (\explode(',', (string) $p) as $needle) {
            if ($needle !== '' && \str_starts_with($s, $needle)) {
                return true;
            }
        }
        return false;
    }

    /** `ends_with:a,b,c` — ends with any of the listed suffixes. */
    public function ends_with(mixed $v, ?string $p, array $d): bool
    {
        $s = (string) $v;
        foreach (\explode(',', (string) $p) as $needle) {
            if ($needle !== '' && \str_ends_with($s, $needle)) {
                return true;
            }
        }
        return false;
    }

    /** `doesnt_start_with:a,b` — begins with none of the listed prefixes. */
    public function doesnt_start_with(mixed $v, ?string $p, array $d): bool
    {
        return $this->starts_with($v, $p, $d) === false;
    }

    /** `doesnt_end_with:a,b` — ends with none of the listed suffixes. */
    public function doesnt_end_with(mixed $v, ?string $p, array $d): bool
    {
        return $this->ends_with($v, $p, $d) === false;
    }

    /** `not_in:a,b,c` — value is NOT one of the listed options. */
    public function not_in(mixed $v, ?string $p, array $d): bool
    {
        return \in_array((string) $v, \explode(',', (string) $p), true) === false;
    }

    // ── arrays ───────────────────────────────────────────────────────────────

    /** Array whose values are all unique. */
    public function distinct(mixed $v, ?string $p, array $d): bool
    {
        return \is_array($v) && \count($v) === \count(\array_unique($v, SORT_REGULAR));
    }

    /** Array that is a sequential list (0,1,2… keys), not a map. */
    public function list(mixed $v, ?string $p, array $d): bool
    {
        return \is_array($v) && \array_is_list($v);
    }

    // ── identifiers ──────────────────────────────────────────────────────────

    /** RFC 4122 UUID (any version). */
    public function uuid(mixed $v, ?string $p, array $d): bool
    {
        return \is_string($v)
            && \preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v) === 1;
    }

    /** ULID — 26 Crockford base32 chars. */
    public function ulid(mixed $v, ?string $p, array $d): bool
    {
        return \is_string($v) && \preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/i', $v) === 1;
    }

    /** lowercase kebab-case slug. */
    public function slug(mixed $v, ?string $p, array $d): bool
    {
        return \is_string($v) && \preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $v) === 1;
    }

    /** Username — 3+ chars of letters, numbers, underscore. */
    public function username(mixed $v, ?string $p, array $d): bool
    {
        return \is_string($v) && \preg_match('/^[A-Za-z0-9_]{3,}$/', $v) === 1;
    }

    // ── network ──────────────────────────────────────────────────────────────

    /** Any IP address (v4 or v6). */
    public function ip(mixed $v, ?string $p, array $d): bool
    {
        return \filter_var($v, FILTER_VALIDATE_IP) !== false;
    }

    public function ipv4(mixed $v, ?string $p, array $d): bool
    {
        return \filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    public function ipv6(mixed $v, ?string $p, array $d): bool
    {
        return \filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    /** MAC address. */
    public function mac_address(mixed $v, ?string $p, array $d): bool
    {
        return \filter_var($v, FILTER_VALIDATE_MAC) !== false;
    }

    /** DNS hostname / domain. */
    public function domain(mixed $v, ?string $p, array $d): bool
    {
        return \is_string($v)
            && \preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $v) === 1;
    }

    // ── formats ──────────────────────────────────────────────────────────────

    /** Valid JSON string. */
    public function json(mixed $v, ?string $p, array $d): bool
    {
        if (\is_string($v) === false || $v === '') {
            return false;
        }
        \json_decode($v);
        return \json_last_error() === JSON_ERROR_NONE;
    }

    /** Base64-encoded string. */
    public function base64(mixed $v, ?string $p, array $d): bool
    {
        return \is_string($v) && \base64_decode($v, true) !== false;
    }

    /** CSS hex colour (#rgb or #rrggbb). */
    public function hex_color(mixed $v, ?string $p, array $d): bool
    {
        return \is_string($v) && \preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $v) === 1;
    }

    // ── dates ────────────────────────────────────────────────────────────────

    /** Any parseable date/time. */
    public function date(mixed $v, ?string $p, array $d): bool
    {
        return \is_string($v) && $v !== '' && \strtotime($v) !== false;
    }

    /** `date_format:Y-m-d` — matches the exact format. */
    public function date_format(mixed $v, ?string $p, array $d): bool
    {
        if (\is_string($v) === false || $p === null) {
            return false;
        }
        $dt = \DateTimeImmutable::createFromFormat('!' . $p, $v);
        return $dt !== false && $dt->format($p) === $v;
    }

    /** `before:2030-01-01` (or `before:today`) — strictly earlier. */
    public function before(mixed $v, ?string $p, array $d): bool
    {
        $a = \strtotime((string) $v);
        $b = \strtotime((string) $p);
        return $a !== false && $b !== false && $a < $b;
    }

    /** `after:2000-01-01` (or `after:today`) — strictly later. */
    public function after(mixed $v, ?string $p, array $d): bool
    {
        $a = \strtotime((string) $v);
        $b = \strtotime((string) $p);
        return $a !== false && $b !== false && $a > $b;
    }

    // ── booleans / acceptance ────────────────────────────────────────────────

    /** Truthy consent: yes / on / 1 / true. */
    public function accepted(mixed $v, ?string $p, array $d): bool
    {
        return \in_array($v, ['yes', 'on', '1', 1, true, 'true'], true);
    }

    /** Falsy: no / off / 0 / false. */
    public function declined(mixed $v, ?string $p, array $d): bool
    {
        return \in_array($v, ['no', 'off', '0', 0, false, 'false'], true);
    }

    // ── locale / money / contact ─────────────────────────────────────────────

    /** ll_CC locale tag, e.g. en_US. */
    public function locale(mixed $v, ?string $p, array $d): bool
    {
        return \is_string($v) && \preg_match('/^[a-z]{2}_[A-Z]{2}$/', $v) === 1;
    }

    /** ISO 4217 currency code, e.g. UGX. */
    public function currency(mixed $v, ?string $p, array $d): bool
    {
        return \is_string($v) && \preg_match('/^[A-Z]{3}$/', $v) === 1;
    }

    /** Loose phone: optional +, 7–15 digits. */
    public function phone(mixed $v, ?string $p, array $d): bool
    {
        return \is_string($v) && \preg_match('/^\+?[0-9]{7,15}$/', $v) === 1;
    }

    /** Strict E.164: leading +, 1–15 digits, no leading zero. */
    public function e164(mixed $v, ?string $p, array $d): bool
    {
        return \is_string($v) && \preg_match('/^\+[1-9]\d{1,14}$/', $v) === 1;
    }

    /** @return array<string,string> per-rule default messages (:field / :param). */
    public function messages(): array
    {
        return [
            'alpha'                => 'The :field field may only contain letters.',
            'alpha_num'            => 'The :field field may only contain letters and numbers.',
            'alpha_dash'           => 'The :field field may only contain letters, numbers, dashes and underscores.',
            'alpha_space'          => 'The :field field may only contain letters and spaces.',
            'alpha_numeric_punct'  => 'The :field field contains an invalid character.',
            'ascii'                => 'The :field field may only contain ASCII characters.',
            'lowercase'            => 'The :field field must be lowercase.',
            'uppercase'            => 'The :field field must be uppercase.',
            'digits'               => 'The :field field must be all digits.',
            'digits_between'       => 'The :field field has an invalid number of digits.',
            'is_natural'           => 'The :field field must be a non-negative whole number.',
            'is_natural_no_zero'   => 'The :field field must be a positive whole number.',
            'hex'                  => 'The :field field must be hexadecimal.',
            'decimal'              => 'The :field field must be a decimal number.',
            'multiple_of'          => 'The :field field must be a multiple of :param.',
            'min_digits'           => 'The :field field must have at least :param digits.',
            'max_digits'           => 'The :field field must not exceed :param digits.',
            'size'                 => 'The :field field must be of size :param.',
            'gt'                   => 'The :field field must be greater than :param.',
            'gte'                  => 'The :field field must be at least :param.',
            'lt'                   => 'The :field field must be less than :param.',
            'lte'                  => 'The :field field must not be greater than :param.',
            'starts_with'          => 'The :field field has an invalid prefix.',
            'ends_with'            => 'The :field field has an invalid suffix.',
            'doesnt_start_with'    => 'The :field field has a forbidden prefix.',
            'doesnt_end_with'      => 'The :field field has a forbidden suffix.',
            'not_in'               => 'The selected :field is invalid.',
            'enum'                 => 'The selected :field is invalid.',
            'distinct'             => 'The :field field has duplicate values.',
            'list'                 => 'The :field field must be a list.',
            'uuid'                 => 'The :field field must be a valid UUID.',
            'ulid'                 => 'The :field field must be a valid ULID.',
            'slug'                 => 'The :field field must be a lowercase kebab-case slug.',
            'username'             => 'The :field field must be 3+ letters, numbers or underscores.',
            'ip'                   => 'The :field field must be a valid IP address.',
            'ipv4'                 => 'The :field field must be a valid IPv4 address.',
            'ipv6'                 => 'The :field field must be a valid IPv6 address.',
            'mac_address'          => 'The :field field must be a valid MAC address.',
            'domain'               => 'The :field field must be a valid domain.',
            'json'                 => 'The :field field must be valid JSON.',
            'base64'               => 'The :field field must be valid base64.',
            'hex_color'            => 'The :field field must be a valid hex colour.',
            'date'                 => 'The :field field must be a valid date.',
            'date_format'    => 'The :field field does not match the required format.',
            'before'         => 'The :field field must be a date before :param.',
            'after'          => 'The :field field must be a date after :param.',
            'accepted'       => 'The :field field must be accepted.',
            'declined'       => 'The :field field must be declined.',
            'locale'         => 'The :field field must be a locale like en_US.',
            'currency'       => 'The :field field must be a 3-letter ISO 4217 code.',
            'phone'          => 'The :field field must be 7–15 digits (optional leading +).',
            'e164'           => 'The :field field must be a valid E.164 phone number.',
        ];
    }

    /** String length / array count / numeric value, used by size/gt/gte/lt/lte. */
    private function measure(mixed $value): float
    {
        if (\is_numeric($value)) {
            return (float) $value;
        }
        if (\is_array($value)) {
            return (float) \count($value);
        }
        return (float) \mb_strlen((string) $value);
    }
}
