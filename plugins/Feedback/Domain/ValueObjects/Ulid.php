<?php

declare(strict_types=1);

namespace Plugins\Feedback\Domain\ValueObjects;

/**
 * Monotonic ULID generator (state holder).
 *
 * Kept separate from the readonly UserId value object because a readonly class
 * cannot hold the mutable counter needed for monotonicity. Generates a 26-char
 * Crockford base32 ULID; within a single millisecond the random component is
 * incremented (not re-randomised) so IDs stay strictly increasing — preserving
 * index locality at high insert rates.
 */
final class Ulid
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private static int $lastTime = 0;
    /** @var list<int> 16 base32 indices forming the random component. */
    private static array $lastRand = [];

    public static function generate(): string
    {
        $alphabet = self::ALPHABET;
        $time     = (int) (microtime(true) * 1000);

        if ($time === self::$lastTime && self::$lastRand !== []) {
            for ($i = 15; $i >= 0; $i--) {
                if (self::$lastRand[$i] < 31) {
                    self::$lastRand[$i]++;
                    break;
                }
                self::$lastRand[$i] = 0;
            }
        } else {
            self::$lastTime = $time;
            self::$lastRand = [];
            for ($i = 0; $i < 16; $i++) {
                self::$lastRand[$i] = random_int(0, 31);
            }
        }

        $t = $time;
        $ulid = '';
        for ($i = 9; $i >= 0; $i--) {
            $ulid = $alphabet[$t % 32] . $ulid;
            $t = intdiv($t, 32);
        }
        foreach (self::$lastRand as $idx) {
            $ulid .= $alphabet[$idx];
        }

        return $ulid;
    }
}
