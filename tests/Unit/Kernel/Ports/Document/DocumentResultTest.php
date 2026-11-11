<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Ports\Document;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document\DocumentPage;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document\DocumentRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What comes back: a record whose id is not a field, and a page whose cursor
 * means "there is more".
 */
#[CoversClass(DocumentRecord::class)]
#[CoversClass(DocumentPage::class)]
final class DocumentResultTest extends TestCase
{
    public function test_the_id_is_not_mixed_into_the_data(): void
    {
        // The reason the record has two properties: in Firestore the id is part
        // of the document's NAME, and a document may ALSO hold a field called
        // `_id` meaning something else. Merging them would lose one of the two.
        $record = new DocumentRecord('abc123', ['_id' => 'a field that happens to be called _id']);

        self::assertSame('abc123', $record->id);
        self::assertSame('a field that happens to be called _id', $record->data['_id']);
    }

    public function test_to_array_folds_the_id_in_under_a_chosen_key(): void
    {
        $record = new DocumentRecord('abc123', ['name' => 'Ada']);

        self::assertSame(['id' => 'abc123', 'name' => 'Ada'], $record->toArray());
        self::assertSame(['key' => 'abc123', 'name' => 'Ada'], $record->toArray('key'));
    }

    public function test_a_nested_field_is_read_by_dotted_path(): void
    {
        $record = new DocumentRecord('1', ['address' => ['city' => 'Kampala', 'zip' => null]]);

        self::assertSame('Kampala', $record->get('address.city'));
        self::assertSame('fallback', $record->get('address.country', 'fallback'));
        self::assertSame('fallback', $record->get('address.zip', 'fallback'));
    }

    public function test_has_distinguishes_an_explicit_null_from_an_absent_field(): void
    {
        // The distinction get() deliberately collapses. It matters here because
        // the port's whole missing-field story is about exactly this difference.
        $record = new DocumentRecord('1', ['a' => null]);

        self::assertTrue($record->has('a'));
        self::assertFalse($record->has('b'));
        self::assertNull($record->get('a'));
        self::assertNull($record->get('b'));
    }

    public function test_a_path_through_a_scalar_is_absent_rather_than_an_error(): void
    {
        $record = new DocumentRecord('1', ['a' => 'scalar']);

        self::assertFalse($record->has('a.b'));
        self::assertSame('d', $record->get('a.b', 'd'));
    }

    // ── Pages ───────────────────────────────────────────────────────────────

    public function test_a_cursor_means_there_is_more(): void
    {
        $page = DocumentPage::of([new DocumentRecord('1', [])], 'cursor-token');

        self::assertTrue($page->hasMore());
        self::assertSame('cursor-token', $page->cursor);
        self::assertSame(1, $page->count());
    }

    public function test_the_last_page_carries_no_cursor(): void
    {
        // An adapter must not hand back a cursor on a page it knows to be last:
        // every caller paginating to exhaustion would then make one extra
        // request per listing that returns nothing.
        $page = DocumentPage::last([new DocumentRecord('1', [])]);

        self::assertFalse($page->hasMore());
        self::assertNull($page->cursor);
    }

    public function test_an_empty_page_is_empty_and_final(): void
    {
        $page = DocumentPage::empty();

        self::assertTrue($page->isEmpty());
        self::assertFalse($page->hasMore());
        self::assertSame(0, $page->count());
    }

    public function test_an_empty_string_cursor_is_refused(): void
    {
        // `''` is not a position, and `if ($page->cursor !== null)` would loop
        // on it forever.
        $this->expectException(\InvalidArgumentException::class);
        DocumentPage::of([], '');
    }

    public function test_a_page_converts_to_plain_arrays_with_ids(): void
    {
        $page = DocumentPage::last([
            new DocumentRecord('1', ['name' => 'Ada']),
            new DocumentRecord('2', ['name' => 'Grace']),
        ]);

        self::assertSame(
            [['id' => '1', 'name' => 'Ada'], ['id' => '2', 'name' => 'Grace']],
            $page->toArray(),
        );
    }

    public function test_records_are_reindexed_as_a_list(): void
    {
        $page = DocumentPage::of([5 => new DocumentRecord('1', []), 9 => new DocumentRecord('2', [])]);

        self::assertSame([0, 1], array_keys($page->records));
    }
}
