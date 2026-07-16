<?php

declare(strict_types=1);

namespace Project\Support\Casting\Casts;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Project\Support\Casting\BaseCast;

/**
 * (PHP) DateTimeImmutable <--> datetime string (DB column)
 *
 * Dependency-free: uses \DateTimeImmutable rather than the legacy Carbon /
 * BaseConnection coupling. An optional first param overrides the format:
 *   "datetime"       => 'Y-m-d H:i:s'
 *   "datetime[ms]"   => 'Y-m-d H:i:s.v'
 *   "datetime[us]"   => 'Y-m-d H:i:s.u'
 *   "datetime[Y-m-d]" => any literal PHP date format
 */
final class DatetimeCast extends BaseCast
{
    private const FORMATS = [
        ''   => 'Y-m-d H:i:s',
        'ms' => 'Y-m-d H:i:s.v',
        'us' => 'Y-m-d H:i:s.u',
    ];

    public static function get(mixed $value, array $params = [], ?object $helper = null): DateTimeImmutable
    {
        if (! is_string($value)) {
            self::invalidTypeValueError($value);
        }

        $format = self::resolveFormat($params);
        $date = DateTimeImmutable::createFromFormat($format, $value);

        if ($date === false) {
            // Fall back to lenient parsing for non-canonical strings.
            try {
                return new DateTimeImmutable(datetime: $value);
            } catch (\Exception $e) {
                throw new InvalidArgumentException("Unparsable datetime value: {$value}", 0, $e);
            }
        }

        return $date;
    }

    public static function set(mixed $value, array $params = [], ?object $helper = null): string
    {
        if (! $value instanceof DateTimeInterface) {
            self::invalidTypeValueError($value);
        }

        return $value->format(self::resolveFormat($params));
    }

    /**
     * @param list<string> $params
     */
    private static function resolveFormat(array $params): string
    {
        $param = $params[0] ?? '';

        return self::FORMATS[$param] ?? $param; // literal format string when not a known alias
    }
}
