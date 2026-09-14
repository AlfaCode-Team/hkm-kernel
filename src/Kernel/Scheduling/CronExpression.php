<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Scheduling;

/**
 * CronExpression — a standard five-field cron expression, parsed once.
 *
 * WHY NOT A LIBRARY
 * -----------------
 * The two obvious candidates (dragonmantank/cron-expression and its forks) are
 * fine, but this kernel takes dependencies only where the problem is genuinely
 * large — a JSON parser, an HTTP parser, a DB driver. A five-field matcher is
 * ~120 lines and the whole surface is `dueAt(DateTimeImmutable): bool`. Owning
 * it keeps the scheduler usable in a bundle with no vendor tree, which is the
 * distribution model this project actually ships.
 *
 * WHAT IS SUPPORTED
 * -----------------
 *   minute hour day-of-month month day-of-week
 *   *            every value
 *   5            a literal
 *   1,3,5        a list
 *   1-5          a range
 *   *\/15        a step over the whole field
 *   1-30/5       a step over a range
 *
 * Plus the usual aliases: @yearly/@annually, @monthly, @weekly, @daily/@midnight,
 * @hourly. Month and day-of-week accept three-letter names (JAN..DEC, SUN..SAT).
 *
 * SECONDS ARE DELIBERATELY ABSENT. A six-field expression is rejected at BOOT,
 * not ignored: the runner is driven by a once-a-minute tick, so a sub-minute
 * schedule that silently rounds up to a minute is a declaration that reads as a
 * guarantee and is not one — the same failure mode CompileJobManifestStage's
 * docblock describes for dropped retry specs.
 *
 * DAY-OF-MONTH / DAY-OF-WEEK USE CRON'S OR RULE. When BOTH are restricted (i.e.
 * neither is `*`), the expression matches if EITHER matches. This is surprising,
 * it is what every cron implementation does, and matching POSIX matters more
 * than matching intuition — `0 0 1,15 * MON` means "the 1st, the 15th, and every
 * Monday", not "Mondays that fall on the 1st or 15th".
 */
final readonly class CronExpression
{
    private const ALIASES = [
        '@yearly'   => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly'  => '0 0 1 * *',
        '@weekly'   => '0 0 * * 0',
        '@daily'    => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@hourly'   => '0 * * * *',
    ];

    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4,  'may' => 5,  'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    private const DAYS = [
        'sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6,
    ];

    /** Field bounds, in expression order. */
    private const BOUNDS = [
        ['min' => 0, 'max' => 59],  // minute
        ['min' => 0, 'max' => 23],  // hour
        ['min' => 1, 'max' => 31],  // day of month
        ['min' => 1, 'max' => 12],  // month
        ['min' => 0, 'max' => 7],   // day of week (7 == Sunday, normalised to 0)
    ];

    /**
     * Each field as a SET of the values it matches, keyed by value for O(1)
     * lookup. Precomputed at parse so a due-check is five array lookups, which
     * is what makes it cheap enough to evaluate every entry on every tick.
     *
     * @param array{0: array<int,true>, 1: array<int,true>, 2: array<int,true>, 3: array<int,true>, 4: array<int,true>} $fields
     */
    private function __construct(
        public string $expression,
        private array $fields,
        /** Whether day-of-month / day-of-week were written as `*` — see the OR rule. */
        private bool $domRestricted,
        private bool $dowRestricted,
    ) {}

    /**
     * @throws \InvalidArgumentException with a message naming the offending field
     */
    public static function parse(string $expression): self
    {
        $raw        = trim($expression);
        $normalised = self::ALIASES[strtolower($raw)] ?? $raw;

        $parts = preg_split('/\s+/', trim($normalised)) ?: [];

        if (count($parts) === 6) {
            throw new \InvalidArgumentException(
                "Cron expression [{$raw}] has six fields. Seconds are not supported: the runner "
                . 'ticks once a minute, so a sub-minute schedule could not be honoured. Use five fields.'
            );
        }

        if (count($parts) !== 5) {
            throw new \InvalidArgumentException(
                "Cron expression [{$raw}] must have exactly five fields "
                . '(minute hour day-of-month month day-of-week), or be one of: '
                . implode(', ', array_keys(self::ALIASES)) . '.'
            );
        }

        $fields = [];
        foreach ($parts as $i => $part) {
            $fields[$i] = self::field((string) $part, $i, $raw);
        }

        return new self(
            $raw,
            /** @phpstan-ignore-next-line the loop above fills exactly five slots */
            $fields,
            $parts[2] !== '*',
            $parts[4] !== '*',
        );
    }

    /** Whether this expression is valid, without the exception. */
    public static function isValid(string $expression): bool
    {
        try {
            self::parse($expression);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Is this expression due at $moment?
     *
     * Compares to MINUTE precision — seconds in $moment are ignored — so a
     * runner that ticks at :00.4 and one that ticks at :00.9 agree.
     */
    public function dueAt(\DateTimeImmutable $moment): bool
    {
        $minute = (int) $moment->format('i');
        $hour   = (int) $moment->format('G');
        $dom    = (int) $moment->format('j');
        $month  = (int) $moment->format('n');
        $dow    = (int) $moment->format('w');

        if (!isset($this->fields[0][$minute], $this->fields[1][$hour], $this->fields[3][$month])) {
            return false;
        }

        $domMatch = isset($this->fields[2][$dom]);
        $dowMatch = isset($this->fields[4][$dow]);

        // Cron's OR rule: when both day fields are restricted, either satisfies.
        if ($this->domRestricted && $this->dowRestricted) {
            return $domMatch || $dowMatch;
        }

        return $domMatch && $dowMatch;
    }

    /**
     * Expand one field into the set of values it matches.
     *
     * @return array<int, true>
     */
    private static function field(string $field, int $index, string $whole): array
    {
        $bounds = self::BOUNDS[$index];
        $values = [];

        foreach (explode(',', $field) as $item) {
            $item = trim($item);

            if ($item === '') {
                throw new \InvalidArgumentException(
                    "Cron expression [{$whole}] has an empty entry in field " . ($index + 1) . '.'
                );
            }

            // step: <range>/<n>
            $step  = 1;
            $slash = strpos($item, '/');
            if ($slash !== false) {
                $stepPart = substr($item, $slash + 1);
                $item     = substr($item, 0, $slash);

                if (!ctype_digit($stepPart) || (int) $stepPart < 1) {
                    throw new \InvalidArgumentException(
                        "Cron expression [{$whole}] has step [{$stepPart}] in field " . ($index + 1)
                        . ' — a step must be a positive integer.'
                    );
                }
                $step = (int) $stepPart;
            }

            if ($item === '*' || $item === '') {
                $from = $bounds['min'];
                $to   = $bounds['max'];
            } elseif (str_contains($item, '-')) {
                [$a, $b] = explode('-', $item, 2);
                $from = self::value($a, $index, $whole);
                $to   = self::value($b, $index, $whole);

                if ($from > $to) {
                    throw new \InvalidArgumentException(
                        "Cron expression [{$whole}] has range [{$item}] in field " . ($index + 1)
                        . ' — the start is after the end.'
                    );
                }
            } else {
                $from = $to = self::value($item, $index, $whole);
            }

            for ($v = $from; $v <= $to; $v += $step) {
                // Day-of-week 7 and 0 both mean Sunday; normalise so a lookup by
                // date('w') (which only ever yields 0-6) still matches "7".
                $values[$index === 4 && $v === 7 ? 0 : $v] = true;
            }
        }

        return $values;
    }

    /** One literal value — a number, or a month/day name in the fields that allow one. */
    private static function value(string $raw, int $index, string $whole): int
    {
        $token = strtolower(trim($raw));

        $named = match ($index) {
            3       => self::MONTHS[$token] ?? null,
            4       => self::DAYS[$token] ?? null,
            default => null,
        };

        if ($named !== null) {
            return $named;
        }

        if (!ctype_digit($token)) {
            throw new \InvalidArgumentException(
                "Cron expression [{$whole}] has value [{$raw}] in field " . ($index + 1)
                . ' — expected an integer' . ($index === 3 ? ' or a month name' : ($index === 4 ? ' or a day name' : '')) . '.'
            );
        }

        $value  = (int) $token;
        $bounds = self::BOUNDS[$index];

        if ($value < $bounds['min'] || $value > $bounds['max']) {
            throw new \InvalidArgumentException(
                "Cron expression [{$whole}] has value [{$value}] in field " . ($index + 1)
                . " — it must be between {$bounds['min']} and {$bounds['max']}."
            );
        }

        return $value;
    }
}
