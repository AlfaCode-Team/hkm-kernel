<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Security\Waf;

/**
 * Why a rule flagged a request. Logged, never sent to the client: telling a
 * scanner which signature it tripped tells it what to change.
 */
final readonly class WafMatch
{
    public function __construct(
        public string $rule,       // the rule id, e.g. 'probe'
        public string $signature,  // which check inside the rule, e.g. 'dotfile'
        public string $location,   // 'path' | 'query' | 'body' | 'header:<name>' | 'request'
        public string $sample = '', // the offending value, truncated
    ) {}

    public static function of(string $rule, string $signature, string $location, string $sample = ''): self
    {
        return new self($rule, $signature, $location, self::clip($sample));
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'rule'      => $this->rule,
            'signature' => $this->signature,
            'location'  => $this->location,
            'sample'    => $this->sample,
        ];
    }

    /**
     * Bound an attacker-controlled value for a log line, and strip control
     * bytes so it cannot forge one. Truncates BEFORE replacing: the regex then
     * runs over at most $max bytes, whatever was sent.
     */
    public static function clip(string $value, int $max = 200): string
    {
        $long  = \strlen($value) > $max;
        $value = $long ? \substr($value, 0, $max) : $value;
        $value = (string) \preg_replace('/[\x00-\x1F\x7F]/', '?', $value);

        return $long ? $value . '…' : $value;
    }
}
