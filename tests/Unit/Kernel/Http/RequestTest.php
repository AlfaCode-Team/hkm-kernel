<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Request::class)]
final class RequestTest extends TestCase
{
    public function test_method_is_upper_case_and_path_has_leading_slash(): void
    {
        $req = Request::create('/api/invoices?page=2', 'post');

        self::assertSame('POST', $req->method());
        self::assertSame('/api/invoices', $req->path());
    }

    public function test_input_merges_query_and_body(): void
    {
        $req = Request::create('/x?q=hello', 'POST', ['title' => 'Draft']);

        self::assertSame('hello', $req->input('q'));
        self::assertSame('Draft', $req->input('title'));
        self::assertSame('fallback', $req->input('missing', 'fallback'));
    }

    public function test_typed_accessors_cast_values(): void
    {
        $req = Request::create('/x', 'POST', ['active' => 'true', 'page' => '7']);

        self::assertTrue($req->boolean('active'));
        self::assertSame(7, $req->integer('page'));
        self::assertFalse($req->boolean('missing'));
        self::assertSame(0, $req->integer('missing'));
    }

    public function test_with_attribute_is_immutable(): void
    {
        $original = Request::create('/x', 'GET');
        $modified = $original->withAttribute('locale', 'fr');

        self::assertNotSame($original, $modified);
        self::assertNull($original->attribute('locale'));
        self::assertSame('fr', $modified->attribute('locale'));
    }

    public function test_with_identity_is_immutable_and_readable(): void
    {
        $original = Request::create('/x', 'GET');
        $withId   = $original->withIdentity(Identity::asUser('u42'));

        self::assertNotSame($original, $withId);
        self::assertNull($original->identity());
        self::assertSame('u42', $withId->identity()->userId);
    }

    public function test_with_attributes_sets_several_in_one_instance(): void
    {
        $original = Request::create('/x', 'GET');
        $modified = $original->withAttributes([
            'route_entry'    => ['solves' => 'invoice.generation'],
            'route_params'   => ['id' => '7'],
            'target_service' => 'invoice.generation',
        ]);

        self::assertNotSame($original, $modified);
        self::assertNull($original->attribute('route_entry'), 'the original must be untouched');
        self::assertSame(['id' => '7'], $modified->attribute('route_params'));
        self::assertSame('invoice.generation', $modified->attribute('target_service'));
    }

    public function test_with_attributes_matches_chaining_with_attribute(): void
    {
        // It exists purely to avoid the intermediate deep clones; if it ever
        // diverged in EFFECT from the chain it replaces, that would be a bug in
        // the routing stage that now calls it.
        $original = Request::create('/x', 'GET');

        $chained = $original->withAttribute('a', 1)->withAttribute('b', ['c' => 2]);
        $batched = $original->withAttributes(['a' => 1, 'b' => ['c' => 2]]);

        self::assertSame($chained->attributes->all(), $batched->attributes->all());
    }

    public function test_with_attributes_keeps_earlier_attributes(): void
    {
        $request = Request::create('/x', 'GET')
            ->withAttribute('locale', 'fr')
            ->withAttributes(['route_params' => []]);

        self::assertSame('fr', $request->attribute('locale'));
    }

    public function test_with_no_attributes_returns_the_same_instance(): void
    {
        // Nothing to copy, so nothing is copied.
        $original = Request::create('/x', 'GET');

        self::assertSame($original, $original->withAttributes([]));
    }
}
