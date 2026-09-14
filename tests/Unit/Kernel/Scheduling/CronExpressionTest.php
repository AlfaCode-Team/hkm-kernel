<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Scheduling;

use AlfacodeTeam\PhpServicePlatform\Kernel\Scheduling\CronExpression;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CronExpression::class)]
final class CronExpressionTest extends TestCase
{
    private static function at(string $iso): \DateTimeImmutable
    {
        return new \DateTimeImmutable($iso);
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function expressions(): iterable
    {
        // 2026-01-05 is a Monday.
        yield 'every minute'            => ['* * * * *',      '2026-01-05 13:37', true];
        yield 'exact minute hits'       => ['30 2 * * *',     '2026-01-05 02:30', true];
        yield 'exact minute misses'     => ['30 2 * * *',     '2026-01-05 02:31', false];
        yield 'list member'             => ['0 1,13 * * *',   '2026-01-05 13:00', true];
        yield 'list non-member'         => ['0 1,13 * * *',   '2026-01-05 14:00', false];
        yield 'range inside'            => ['0 9-17 * * *',   '2026-01-05 12:00', true];
        yield 'range outside'           => ['0 9-17 * * *',   '2026-01-05 18:00', false];
        yield 'step over field'         => ['*/15 * * * *',   '2026-01-05 10:45', true];
        yield 'step misses'             => ['*/15 * * * *',   '2026-01-05 10:46', false];
        yield 'step over range'         => ['0-30/10 * * * *', '2026-01-05 10:20', true];
        yield 'step over range misses'  => ['0-30/10 * * * *', '2026-01-05 10:40', false];
        yield 'month name'              => ['0 0 1 JAN *',    '2026-01-01 00:00', true];
        yield 'month name misses'       => ['0 0 1 JAN *',    '2026-02-01 00:00', false];
        yield 'day name'                => ['0 9 * * MON',    '2026-01-05 09:00', true];
        yield 'day name misses'         => ['0 9 * * MON',    '2026-01-06 09:00', false];
        yield 'sunday as 0'             => ['0 0 * * 0',      '2026-01-04 00:00', true];
        yield 'sunday as 7'             => ['0 0 * * 7',      '2026-01-04 00:00', true];

        yield 'alias @daily'            => ['@daily',         '2026-01-05 00:00', true];
        yield 'alias @daily off'        => ['@daily',         '2026-01-05 00:01', false];
        yield 'alias @hourly'           => ['@hourly',        '2026-01-05 07:00', true];
        yield 'alias @monthly'          => ['@monthly',       '2026-01-01 00:00', true];
        yield 'alias @weekly'           => ['@weekly',        '2026-01-04 00:00', true];
        yield 'alias @yearly'           => ['@yearly',        '2026-01-01 00:00', true];
        yield 'alias is case-insensitive' => ['@DAILY',       '2026-01-05 00:00', true];
    }

    #[DataProvider('expressions')]
    public function testMatching(string $expression, string $moment, bool $expected): void
    {
        self::assertSame($expected, CronExpression::parse($expression)->dueAt(self::at($moment)));
    }

    /**
     * POSIX's surprising rule, kept deliberately: with BOTH day fields
     * restricted, either one matching is enough.
     */
    public function testDayOfMonthAndDayOfWeekAreOredWhenBothRestricted(): void
    {
        $cron = CronExpression::parse('0 0 1,15 * MON');

        self::assertTrue($cron->dueAt(self::at('2026-01-15 00:00')), 'the 15th, a Thursday');
        self::assertTrue($cron->dueAt(self::at('2026-01-05 00:00')), 'a Monday, the 5th');
        self::assertFalse($cron->dueAt(self::at('2026-01-06 00:00')), 'neither');
    }

    public function testDayFieldsAreAndedWhenOnlyOneIsRestricted(): void
    {
        $cron = CronExpression::parse('0 0 15 * *');

        self::assertTrue($cron->dueAt(self::at('2026-01-15 00:00')));
        self::assertFalse($cron->dueAt(self::at('2026-01-05 00:00')));
    }

    public function testSecondsAreRejectedRatherThanRoundedAway(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/six fields.*Seconds are not supported/s');

        CronExpression::parse('*/30 * * * * *');
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidExpressions(): iterable
    {
        yield 'hour out of range'   => ['0 25 * * *',  'between 0 and 23'];
        yield 'minute out of range' => ['60 * * * *',  'between 0 and 59'];
        yield 'month out of range'  => ['0 0 1 13 *',  'between 1 and 12'];
        yield 'dom out of range'    => ['0 0 32 * *',  'between 1 and 31'];
        yield 'too few fields'      => ['* * *',       'exactly five fields'];
        yield 'inverted range'      => ['0 17-9 * * *', 'the start is after the end'];
        yield 'zero step'           => ['*/0 * * * *', 'positive integer'];
        yield 'garbage value'       => ['0 0 * * xyz', 'expected an integer or a day name'];
        yield 'empty list entry'    => ['0 0,, * * *', 'empty entry'];
        yield 'unknown alias'       => ['@fortnightly', 'exactly five fields'];
    }

    #[DataProvider('invalidExpressions')]
    public function testInvalidExpressionsThrowWithTheOffendingField(string $expression, string $needle): void
    {
        self::assertFalse(CronExpression::isValid($expression));

        try {
            CronExpression::parse($expression);
            self::fail("[{$expression}] should not parse.");
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($needle, $e->getMessage());
            self::assertStringContainsString($expression, $e->getMessage());
        }
    }

    public function testSecondsAreIgnoredSoTwoRunnersInTheSameMinuteAgree(): void
    {
        $cron = CronExpression::parse('30 2 * * *');

        self::assertTrue($cron->dueAt(self::at('2026-01-05 02:30:00')));
        self::assertTrue($cron->dueAt(self::at('2026-01-05 02:30:59')));
    }

    public function testTheOriginalExpressionIsPreservedForDisplay(): void
    {
        self::assertSame('@daily', CronExpression::parse('  @daily  ')->expression);
    }
}
