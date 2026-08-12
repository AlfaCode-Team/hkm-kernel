<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Config;

use AlfacodeTeam\PhpServicePlatform\Kernel\Config\Repository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Repository::class)]
final class RepositoryTest extends TestCase
{
    private function repository(): Repository
    {
        return new Repository([
            'mail' => [
                'from'       => ['address' => 'noreply@example.test', 'name' => 'Example'],
                'transport'  => 'smtp',
                'reply_to'   => null,
                'transports' => ['smtp', 'sendmail'],
            ],
            'validation' => ['rulesets' => []],
        ]);
    }

    public function test_reads_a_dotted_path(): void
    {
        self::assertSame('noreply@example.test', $this->repository()->get('mail.from.address'));
    }

    public function test_reads_a_top_level_group(): void
    {
        self::assertSame(['rulesets' => []], $this->repository()->get('validation'));
    }

    public function test_missing_key_returns_the_default(): void
    {
        self::assertSame('fallback', $this->repository()->get('mail.from.title', 'fallback'));
        self::assertSame('fallback', $this->repository()->get('nope.at.all', 'fallback'));
    }

    public function test_descending_into_a_scalar_returns_the_default(): void
    {
        // 'mail.transport' is a string; asking for a child of it is a miss, not a crash.
        self::assertSame('d', $this->repository()->get('mail.transport.host', 'd'));
    }

    public function test_a_stored_null_is_returned_not_the_default(): void
    {
        // The distinction matters: "configured to nothing" is not "not configured".
        self::assertNull($this->repository()->get('mail.reply_to', 'DEFAULT'));
    }

    public function test_has_distinguishes_absent_from_null(): void
    {
        $config = $this->repository();

        self::assertTrue($config->has('mail.reply_to'), 'a stored null still exists');
        self::assertFalse($config->has('mail.missing'));
    }

    public function test_group_always_returns_an_array(): void
    {
        $config = $this->repository();

        self::assertSame(['smtp', 'sendmail'], $config->group('mail')['transports']);
        self::assertSame([], $config->group('does-not-exist'));
    }

    public function test_memoisation_does_not_leak_a_default_into_later_reads(): void
    {
        $config = $this->repository();

        // A miss must NOT be cached — otherwise the first caller's default would
        // be served to every later caller asking for the same key.
        self::assertSame('first', $config->get('mail.absent', 'first'));
        self::assertSame('second', $config->get('mail.absent', 'second'));
    }

    public function test_all_exposes_the_whole_manifest(): void
    {
        self::assertArrayHasKey('mail', $this->repository()->all());
    }

    public function test_an_empty_repository_is_safe_to_read(): void
    {
        $config = new Repository();

        self::assertNull($config->get('anything'));
        self::assertFalse($config->has('anything'));
        self::assertSame([], $config->all());
    }
}
