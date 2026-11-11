<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Ports\Document;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document\DocumentFilter;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document\DocumentOperator;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document\FieldPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * One predicate, and the limits Firestore puts on it.
 *
 * The value-count caps are the interesting ones, because they are asymmetric in
 * a way nobody remembers: `in` and `array-contains-any` take 30 values, while
 * `not-in` takes 10. A list built from a variable-length set — "exclude the
 * blocked users" — therefore works until the set grows past ten, and then fails
 * in production on a store that had been fine all along.
 */
#[CoversClass(DocumentFilter::class)]
#[CoversClass(DocumentOperator::class)]
#[CoversClass(FieldPath::class)]
final class DocumentFilterTest extends TestCase
{
    public function test_in_accepts_thirty_values_and_refuses_thirty_one(): void
    {
        $thirty = range(1, 30);

        self::assertCount(30, (array) DocumentFilter::in('id', $thirty)->values);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at most 30 values/');
        DocumentFilter::in('id', range(1, 31));
    }

    public function test_not_in_accepts_ten_values_and_refuses_eleven(): void
    {
        self::assertCount(10, (array) DocumentFilter::notIn('id', range(1, 10))->values);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at most 10 values/');
        DocumentFilter::notIn('id', range(1, 11));
    }

    public function test_array_contains_any_accepts_thirty(): void
    {
        self::assertCount(30, (array) DocumentFilter::arrayContainsAny('tags', range(1, 30))->values);
    }

    public function test_an_empty_value_list_is_refused_rather_than_matching_nothing(): void
    {
        // Both stores answer an empty `in` with zero documents, so the bug is
        // invisible: an unfiltered collection that arrived empty reads as "no
        // results" instead of as a missing guard at the call site.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/guard the empty case/');

        DocumentFilter::in('id', []);
    }

    public function test_a_null_inside_a_value_list_is_refused(): void
    {
        // Firestore: a not-in containing null matches NO documents. MongoDB
        // would match every document missing the field. Neither is guessable.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/matches NO documents/');

        DocumentFilter::notIn('status', ['void', null]);
    }

    public function test_a_range_comparison_against_null_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not meaningful/');

        DocumentFilter::gt('age', null);
    }

    public function test_an_object_cannot_be_compared(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cannot be compared/');

        DocumentFilter::eq('meta', new \stdClass());
    }

    public function test_a_datetime_is_comparable(): void
    {
        $filter = DocumentFilter::gte('createdAt', new \DateTimeImmutable('2026-01-01'));

        self::assertSame(DocumentOperator::Gte, $filter->operator);
        self::assertTrue($filter->isRange());
    }

    public function test_an_exact_array_match_is_comparable_but_an_array_range_is_not(): void
    {
        self::assertSame(DocumentOperator::Eq, DocumentFilter::eq('point', [1, 2])->operator);

        $this->expectException(\InvalidArgumentException::class);
        DocumentFilter::gt('point', [1, 2]);
    }

    // ── Which operators carry the missing-field exclusion ───────────────────

    public function test_only_ne_and_not_in_exclude_a_missing_field(): void
    {
        self::assertTrue(DocumentOperator::Ne->excludesMissingField());
        self::assertTrue(DocumentOperator::NotIn->excludesMissingField());

        foreach ([
            DocumentOperator::Eq,
            DocumentOperator::Lt,
            DocumentOperator::Lte,
            DocumentOperator::Gt,
            DocumentOperator::Gte,
            DocumentOperator::In,
            DocumentOperator::ArrayContains,
            DocumentOperator::ArrayContainsAny,
        ] as $operator) {
            self::assertFalse(
                $operator->excludesMissingField(),
                $operator->value . ' must not claim the missing-field exclusion',
            );
        }
    }

    public function test_exactly_the_four_comparison_operators_are_ranges(): void
    {
        $ranges = array_values(array_filter(
            DocumentOperator::cases(),
            static fn (DocumentOperator $o): bool => $o->isRange(),
        ));

        self::assertSame(
            [DocumentOperator::Lt, DocumentOperator::Lte, DocumentOperator::Gt, DocumentOperator::Gte],
            $ranges,
            'the range set decides when the first-ordering rule applies, so an extra member here '
            . 'would refuse legal queries and a missing one would let an illegal query through',
        );
    }

    public function test_the_operator_set_is_exactly_firestores_ten(): void
    {
        // Pinned deliberately: the list is the intersection of the two query
        // languages, and an eleventh operator added casually would be one the
        // Firestore adapter could not implement.
        self::assertCount(10, DocumentOperator::cases());
    }

    // ── Field paths ─────────────────────────────────────────────────────────

    public function test_a_dotted_path_is_accepted(): void
    {
        self::assertSame('address.city', DocumentFilter::eq('address.city', 'Kampala')->field);
    }

    public function test_an_empty_path_segment_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/empty segment/');

        DocumentFilter::eq('address..city', 'Kampala');
    }

    public function test_a_leading_dollar_in_a_segment_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/may not start with/');

        DocumentFilter::eq('$where', 'x');
    }

    public function test_a_path_deeper_than_twenty_segments_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/segments deep/');

        DocumentFilter::eq(implode('.', array_fill(0, 21, 'a')), 1);
    }

    public function test_a_path_is_bounded_in_bytes_not_characters(): void
    {
        // The limit is 1,500 BYTES. A multibyte path passes a character count
        // while failing the real limit, so the check has to be on strlen.
        $multibyte = str_repeat('é', 800); // 1,600 bytes, 800 characters

        self::assertSame(800, mb_strlen($multibyte));
        self::assertGreaterThan(FieldPath::MAX_BYTES, \strlen($multibyte));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at most 1500 bytes/');
        DocumentFilter::eq($multibyte, 1);
    }

    public function test_a_long_path_is_not_echoed_whole_into_the_message(): void
    {
        try {
            DocumentFilter::eq(str_repeat('a', 2000), 1);
            self::fail('an over-long path should be refused');
        } catch (\InvalidArgumentException $e) {
            // A path can arrive from input and the message lands in a log, so
            // it is truncated rather than interpolated whole.
            self::assertLessThan(400, \strlen($e->getMessage()));
            self::assertStringContainsString('...', $e->getMessage());
        }
    }
}
