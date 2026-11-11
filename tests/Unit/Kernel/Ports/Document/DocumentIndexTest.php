<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Ports\Document;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document\DocumentIndex;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document\DocumentStoreCapability;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document\DocumentStoreException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document\IndexOutcome;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document\IndexResult;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document\SortDirection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Index definitions, and the outcome type that keeps a migration honest.
 *
 * The naming tests matter more than they look. Measured on MongoDB 9.0.4,
 * creating the same key pattern under a second name fails with code 85
 * (`IndexOptionsConflict`, "Index already exists with a different name"). So a
 * derived name that is not a pure function of the definition does not create a
 * second index — it breaks the migration, on the second deploy, in whichever
 * environment ran the first one.
 */
#[CoversClass(DocumentIndex::class)]
#[CoversClass(IndexResult::class)]
#[CoversClass(IndexOutcome::class)]
#[CoversClass(DocumentStoreException::class)]
final class DocumentIndexTest extends TestCase
{
    public function test_a_derived_name_is_stable_across_calls(): void
    {
        self::assertSame(
            DocumentIndex::of('orders', ['status', 'createdAt'])->name,
            DocumentIndex::of('orders', ['status', 'createdAt'])->name,
        );
    }

    public function test_the_derived_name_distinguishes_field_order(): void
    {
        // (a, b) and (b, a) are different indexes serving different queries, so
        // collapsing them to one name would make one permanently unachievable.
        self::assertNotSame(
            DocumentIndex::of('orders', ['status', 'createdAt'])->name,
            DocumentIndex::of('orders', ['createdAt', 'status'])->name,
        );
    }

    public function test_the_derived_name_distinguishes_direction(): void
    {
        self::assertNotSame(
            DocumentIndex::of('orders', ['createdAt' => SortDirection::Asc])->name,
            DocumentIndex::of('orders', ['createdAt' => SortDirection::Desc])->name,
        );
    }

    public function test_a_long_derived_name_is_hashed_rather_than_truncated(): void
    {
        // Truncation would let two different definitions derive ONE name, which
        // is exactly the code-85 conflict reached by accident.
        $a = DocumentIndex::of('orders', [str_repeat('a', 60) . '_one', str_repeat('a', 60) . '_two']);
        $b = DocumentIndex::of('orders', [str_repeat('a', 60) . '_one', str_repeat('a', 60) . '_three']);

        self::assertStringStartsWith('idx_', $a->name);
        self::assertNotSame($a->name, $b->name);
        self::assertLessThanOrEqual(100, \strlen($a->name));
    }

    public function test_an_explicit_name_is_kept(): void
    {
        self::assertSame('uniq_token', DocumentIndex::unique('devices', ['token'], 'uniq_token')->name);
    }

    public function test_an_index_name_with_a_space_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DocumentIndex::of('orders', ['status'], 'my index');
    }

    public function test_an_empty_explicit_name_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DocumentIndex::of('orders', ['status'], '');
    }

    public function test_a_list_of_fields_means_all_ascending(): void
    {
        $index = DocumentIndex::of('orders', ['status', 'createdAt']);

        self::assertSame(['status', 'createdAt'], $index->fieldNames());
        self::assertSame(SortDirection::Asc, $index->fields['createdAt']);
    }

    public function test_thirty_two_fields_are_allowed_and_thirty_three_are_not(): void
    {
        // Measured on MongoDB 9.0.4: 32 creates, 33 fails with "too many
        // compound keys" (code 13103).
        $fields = array_map(static fn (int $i): string => 'f' . $i, range(1, 32));

        self::assertCount(32, DocumentIndex::of('wide', $fields)->fields);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at most 32 fields/');
        DocumentIndex::of('wide', [...$fields, 'f33']);
    }

    public function test_an_index_must_cover_a_field(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DocumentIndex::of('orders', []);
    }

    public function test_a_repeated_field_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/twice/');

        DocumentIndex::of('orders', ['status', 'status']);
    }

    // ── Capabilities ────────────────────────────────────────────────────────

    public function test_a_plain_index_requires_no_capability(): void
    {
        self::assertSame([], DocumentIndex::of('orders', ['status'])->requiredCapabilities());
    }

    public function test_a_unique_index_requires_the_unique_capability(): void
    {
        self::assertSame(
            [DocumentStoreCapability::UniqueIndex],
            DocumentIndex::unique('devices', ['token'])->requiredCapabilities(),
        );
    }

    public function test_a_ttl_index_requires_the_ttl_capability(): void
    {
        $index = DocumentIndex::ttl('sessions', 'expiresAt', 3600);

        self::assertSame([DocumentStoreCapability::TtlIndex], $index->requiredCapabilities());
        self::assertSame(3600, $index->ttlSeconds);
    }

    public function test_a_negative_ttl_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DocumentIndex::ttl('sessions', 'expiresAt', -1);
    }

    public function test_an_unsupported_capability_produces_a_message_that_names_the_feature(): void
    {
        $e = DocumentStoreException::unsupported(
            DocumentStoreCapability::UniqueIndex,
            'Firestore (standard)',
            'ensureIndex',
        );

        self::assertStringContainsString('unique indexes', $e->getMessage());
        self::assertStringContainsString('Firestore (standard)', $e->getMessage());
        self::assertStringContainsString(
            'silently skipped',
            $e->getMessage(),
            'the message has to say why it refused rather than degraded, or the next person to read '
            . 'it will "fix" it by degrading',
        );
        self::assertSame('ensureIndex', $e->operation);
    }

    // ── The outcome type ────────────────────────────────────────────────────

    public function test_a_conflict_is_not_satisfied_and_everything_else_is(): void
    {
        self::assertTrue(IndexResult::created('a')->isSatisfied());
        self::assertTrue(IndexResult::alreadyExists('a')->isSatisfied());
        self::assertTrue(
            IndexResult::building('a')->isSatisfied(),
            'a building index matches the definition; it is merely not finished',
        );
        self::assertFalse(IndexResult::conflict('a', 'existing index is not unique')->isSatisfied());
    }

    public function test_only_a_present_index_is_usable(): void
    {
        self::assertTrue(IndexOutcome::Created->isUsable());
        self::assertTrue(IndexOutcome::AlreadyExists->isUsable());
        self::assertFalse(
            IndexOutcome::Building->isUsable(),
            'Firestore creates composite indexes asynchronously, so a query needing one still '
            . 'fails until the build completes',
        );
        self::assertFalse(IndexOutcome::Conflict->isUsable());
    }

    public function test_already_exists_and_conflict_are_distinct_outcomes(): void
    {
        // The whole reason ensureIndex() returns a result rather than a bool:
        // an adapter that swallows "already exists" to stay idempotent turns a
        // genuine conflict into a green deploy with no constraint behind it.
        self::assertNotSame(IndexOutcome::AlreadyExists, IndexOutcome::Conflict);
        self::assertTrue(IndexOutcome::AlreadyExists->matchesDefinition());
        self::assertFalse(IndexOutcome::Conflict->matchesDefinition());
    }

    public function test_a_conflict_without_an_explanation_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must explain what differs/');

        IndexResult::conflict('uniq_token', '');
    }

    public function test_describe_carries_the_definition_and_the_detail(): void
    {
        self::assertSame(
            'devices(token:asc) unique',
            DocumentIndex::unique('devices', ['token'])->describe(),
        );

        self::assertSame(
            'uniq_token: conflict (existing index is not unique)',
            IndexResult::conflict('uniq_token', 'existing index is not unique')->describe(),
        );
    }
}
