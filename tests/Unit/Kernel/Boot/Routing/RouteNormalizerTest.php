<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Boot\Routing;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\BootException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Routing\RouteNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One declared value at a time.
 *
 * Before the split, every one of these cases needed a fixture module, a temp
 * directory and a full manifest compilation to reach. That cost is why the
 * malformed-input branches were the least-covered code in the kernel — which is
 * unfortunate, because they are the ones that decide whether a mistake becomes a
 * boot failure or a silent 404.
 */
#[CoversClass(RouteNormalizer::class)]
final class RouteNormalizerTest extends TestCase
{
    // ── path ─────────────────────────────────────────────────────────────────

    public function testAnAbsolutePathPassesThrough(): void
    {
        self::assertSame('/users/{id}', RouteNormalizer::path('/users/{id}', 'ctx'));
    }

    /** Never prepend the slash: that would silently PUBLISH an endpoint. */
    #[DataProvider('nonAbsolutePaths')]
    public function testARelativePathFailsRatherThanBeingFixed(string $path): void
    {
        $this->expectException(BootException::class);
        $this->expectExceptionMessageMatches("/does not start with/");

        RouteNormalizer::path($path, 'ctx');
    }

    /** @return iterable<string, array{string}> */
    public static function nonAbsolutePaths(): iterable
    {
        yield 'bare word' => ['users'];
        yield 'empty'     => [''];
        yield 'wildcard'  => ['*'];
    }

    // ── prefix ───────────────────────────────────────────────────────────────

    /** @return iterable<string, array{mixed, string}> */
    public static function prefixes(): iterable
    {
        yield 'absent'          => [null, ''];
        yield 'empty string'    => ['', ''];
        yield 'false'           => [false, ''];
        yield 'plain'           => ['/api', '/api'];
        yield 'trailing slash'  => ['/api/', '/api'];
        yield 'padded'          => ['  /api  ', '/api'];
        yield 'root collapses'  => ['/', ''];
    }

    #[DataProvider('prefixes')]
    public function testPrefixNormalization(mixed $input, string $expected): void
    {
        self::assertSame($expected, RouteNormalizer::prefix($input, 'ctx'));
    }

    public function testANonStringPrefixFails(): void
    {
        $this->expectException(BootException::class);
        RouteNormalizer::prefix(42, 'ctx');
    }

    public function testARelativePrefixFails(): void
    {
        $this->expectException(BootException::class);
        RouteNormalizer::prefix('api', 'ctx');
    }

    // ── filters ──────────────────────────────────────────────────────────────

    public function testASingleFilterStringBecomesAList(): void
    {
        self::assertSame(['auth'], RouteNormalizer::filters('auth'));
    }

    public function testBlankFiltersAreDropped(): void
    {
        self::assertSame(['auth', 'throttle:60,1'], RouteNormalizer::filters(['auth', '  ', 'throttle:60,1', '']));
    }

    /**
     * This used to cast an array to the literal string "Array", producing a
     * filter alias that failed at request time on whichever page declared it.
     */
    public function testANonScalarFilterFailsTheBootInsteadOfBecomingTheAliasArray(): void
    {
        $this->expectException(BootException::class);
        $this->expectExceptionMessageMatches('/not a string/');

        RouteNormalizer::filters([['auth']]);
    }

    public function testFiltersThatAreNeitherStringNorListFail(): void
    {
        $this->expectException(BootException::class);
        RouteNormalizer::filters(42);
    }

    // ── mergeFilters ─────────────────────────────────────────────────────────

    /** The rule that keeps a stage from running twice. */
    public function testARouteFilterReplacesAGroupFilterWithTheSameAlias(): void
    {
        self::assertSame(
            ['auth', 'throttle:5,1'],
            RouteNormalizer::mergeFilters(['auth', 'throttle:60,1'], ['throttle:5,1']),
        );
    }

    public function testGroupFiltersComeFirstAndSurviveWhenNotOverridden(): void
    {
        self::assertSame(
            ['auth', 'json'],
            RouteNormalizer::mergeFilters(['auth'], ['json']),
        );
    }

    public function testNoDefaultsMeansTheRoutesOwnFilters(): void
    {
        self::assertSame(['json'], RouteNormalizer::mergeFilters([], ['json']));
    }

    // ── parseFilterSpec ──────────────────────────────────────────────────────

    /** @return iterable<string, array{string, string, list<string>}> */
    public static function filterSpecs(): iterable
    {
        yield 'bare'            => ['auth', 'auth', []];
        yield 'one arg'         => ['throttle:60', 'throttle', ['60']];
        yield 'two args'        => ['throttle:60,1', 'throttle', ['60', '1']];
        yield 'padded'          => ['  throttle : 60 , 1 ', 'throttle', ['60', '1']];
        yield 'empty args kept out' => ['throttle:60,,1', 'throttle', ['60', '1']];
    }

    #[DataProvider('filterSpecs')]
    public function testFilterSpecParsing(string $spec, string $alias, array $args): void
    {
        self::assertSame(['alias' => $alias, 'args' => $args], RouteNormalizer::parseFilterSpec($spec));
    }

    // ── faces / requires ─────────────────────────────────────────────────────

    public function testFacesAreLowercasedAndCompacted(): void
    {
        self::assertSame(['admin', 'api'], RouteNormalizer::faces([' Admin ', '', 'API']));
    }

    public function testASingleFaceStringBecomesAList(): void
    {
        self::assertSame(['admin'], RouteNormalizer::faces('Admin'));
    }

    public function testRequiresAcceptsAStringOrAList(): void
    {
        self::assertSame(['view.rendering'], RouteNormalizer::requires('view.rendering'));
        self::assertSame(['a', 'b'], RouteNormalizer::requires(['a', ' b ', '']));
    }

    public function testMergeRequiresIsAUnionWithoutDuplicates(): void
    {
        self::assertSame(['a', 'b', 'c'], RouteNormalizer::mergeRequires(['a', 'b'], ['b', 'c']));
    }

    // ── stringOrEmpty ────────────────────────────────────────────────────────

    public function testStringOrEmptyTreatsAbsenceAsEmpty(): void
    {
        self::assertSame('', RouteNormalizer::stringOrEmpty(null, 'owner', 'name'));
        self::assertSame('', RouteNormalizer::stringOrEmpty(false, 'owner', 'name'));
    }

    public function testStringOrEmptyRejectsANonString(): void
    {
        $this->expectException(BootException::class);
        $this->expectExceptionMessageMatches('/non-string name/');

        RouteNormalizer::stringOrEmpty(['x'], 'owner', 'name');
    }
}
