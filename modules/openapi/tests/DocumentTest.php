<?php

declare(strict_types=1);

namespace AlfacodeTeam\OpenApi\Tests;

use AlfacodeTeam\OpenApi\Document;
use AlfacodeTeam\OpenApi\Info;
use AlfacodeTeam\OpenApi\Operation;
use AlfacodeTeam\OpenApi\Parameter;
use AlfacodeTeam\OpenApi\Response;
use AlfacodeTeam\OpenApi\Schema;
use AlfacodeTeam\OpenApi\SecurityScheme;
use AlfacodeTeam\OpenApi\Server;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Document::class)]
#[CoversClass(Operation::class)]
#[CoversClass(Parameter::class)]
#[CoversClass(Response::class)]
#[CoversClass(Schema::class)]
#[CoversClass(SecurityScheme::class)]
#[CoversClass(Info::class)]
#[CoversClass(Server::class)]
final class DocumentTest extends TestCase
{
    private function document(): Document
    {
        return new Document(new Info('Test API', '1.0.0'));
    }

    public function test_a_minimal_document_has_the_required_top_level_keys(): void
    {
        $spec = $this->document()->toArray();

        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertSame(['title' => 'Test API', 'version' => '1.0.0'], $spec['info']);
        $this->assertSame([], $spec['paths']);
    }

    public function test_an_operation_lands_under_its_path_and_lowercased_method(): void
    {
        $doc = $this->document();
        $doc->addOperation('/things', 'GET', Operation::create()->withOperationId('things.index'));

        $spec = $doc->toArray();

        $this->assertArrayHasKey('/things', $spec['paths']);
        $this->assertArrayHasKey('get', $spec['paths']['/things']);
        $this->assertSame('things.index', $spec['paths']['/things']['get']['operationId']);
    }

    /** `responses` is required by the spec even when nothing was declared. */
    public function test_every_operation_emits_a_responses_object(): void
    {
        $doc = $this->document();
        $doc->addOperation('/things', 'GET', Operation::create());

        $this->assertSame([], $doc->toArray()['paths']['/things']['get']['responses']);
    }

    // ── The guards that stop a malformed spec being built ────────────────────

    /**
     * The single most likely generator bug: handing the framework's own path
     * through unchanged. `/things/{id:num}` is not an OpenAPI template and no
     * generated client could ever match it, so it fails loudly here rather than
     * producing a document that looks fine and does not work.
     */
    public function test_a_path_carrying_a_parameter_type_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('still carries a parameter type');

        $this->document()->addOperation('/things/{id:num}', 'GET', Operation::create());
    }

    public function test_a_relative_path_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->document()->addOperation('things', 'GET', Operation::create());
    }

    public function test_an_unknown_method_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->document()->addOperation('/things', 'FETCH', Operation::create());
    }

    public function test_the_same_path_and_method_cannot_be_added_twice(): void
    {
        $doc = $this->document();
        $doc->addOperation('/things', 'GET', Operation::create());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate operation');

        $doc->addOperation('/things', 'get', Operation::create());
    }

    /**
     * OpenAPI requires operationIds to be unique document-wide. A duplicate
     * makes most client generators either fail or silently emit only one of the
     * two methods, so it is rejected here rather than discovered by whoever
     * tries to use the generated client.
     */
    public function test_a_duplicate_operation_id_is_rejected(): void
    {
        $doc = $this->document();
        $doc->addOperation('/things', 'GET', Operation::create()->withOperationId('things.index'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Duplicate operationId 'things.index'");

        $doc->addOperation('/other', 'GET', Operation::create()->withOperationId('things.index'));
    }

    public function test_operations_without_an_id_do_not_collide(): void
    {
        $doc = $this->document();
        $doc->addOperation('/a', 'GET', Operation::create());
        $doc->addOperation('/b', 'GET', Operation::create());

        $this->assertSame(2, $doc->operationCount());
    }

    // ── Determinism ──────────────────────────────────────────────────────────

    /**
     * A spec that reshuffles itself on every build cannot be reviewed in a diff.
     * Paths sort, and methods within a path follow a fixed verb order rather
     * than insertion order.
     */
    public function test_output_is_deterministic_regardless_of_insertion_order(): void
    {
        $first = $this->document();
        $first->addOperation('/zebra', 'DELETE', Operation::create());
        $first->addOperation('/zebra', 'GET', Operation::create());
        $first->addOperation('/alpha', 'POST', Operation::create());

        $second = $this->document();
        $second->addOperation('/alpha', 'POST', Operation::create());
        $second->addOperation('/zebra', 'GET', Operation::create());
        $second->addOperation('/zebra', 'DELETE', Operation::create());

        $this->assertSame($first->toJson(), $second->toJson());
        $this->assertSame(['/alpha', '/zebra'], array_keys($first->toArray()['paths']));
        $this->assertSame(['get', 'delete'], array_keys($first->toArray()['paths']['/zebra']));
    }

    public function test_duplicate_servers_are_collapsed(): void
    {
        $doc = $this->document();
        $doc->addServer(new Server('https://api.example.com'));
        $doc->addServer(new Server('https://api.example.com', 'again'));

        $this->assertCount(1, $doc->toArray()['servers']);
    }

    // ── Parameters and schemas ───────────────────────────────────────────────

    /** A non-required path parameter is invalid OpenAPI, so it is not expressible. */
    public function test_path_parameters_are_always_required(): void
    {
        $parameter = Parameter::path('id', Schema::integer());

        $this->assertTrue($parameter->toArray()['required']);
        $this->assertSame('path', $parameter->toArray()['in']);
    }

    public function test_query_parameters_default_to_optional(): void
    {
        $this->assertFalse(Parameter::query('page', Schema::integer())->toArray()['required']);
        $this->assertTrue(Parameter::query('q', Schema::string(), required: true)->toArray()['required']);
    }

    public function test_enum_schema_carries_its_values(): void
    {
        $schema = Schema::enum(['draft', 'published'])->toArray();

        $this->assertSame('string', $schema['type']);
        $this->assertSame(['draft', 'published'], $schema['enum']);
    }

    public function test_schema_omits_keys_it_has_no_value_for(): void
    {
        $this->assertSame(['type' => 'string'], Schema::string()->toArray());
    }

    public function test_string_schema_carries_a_pattern_when_given_one(): void
    {
        $schema = Schema::string(pattern: '^[0-9]+$')->toArray();

        $this->assertSame('^[0-9]+$', $schema['pattern']);
    }

    // ── Security ─────────────────────────────────────────────────────────────

    public function test_security_schemes_land_in_components_and_sort(): void
    {
        $doc = $this->document();
        $doc->addSecurityScheme('session', SecurityScheme::cookieSession('hkm_session'));
        $doc->addSecurityScheme('bearer', SecurityScheme::bearer('JWT'));

        $schemes = $doc->toArray()['components']['securitySchemes'];

        $this->assertSame(['bearer', 'session'], array_keys($schemes));
        $this->assertSame(['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'], $schemes['bearer']);
        $this->assertSame('cookie', $schemes['session']['in']);
    }

    public function test_an_operation_references_a_scheme_by_name(): void
    {
        $doc = $this->document();
        $doc->addOperation('/me', 'GET', Operation::create()->withSecurity('bearer'));

        $this->assertSame([['bearer' => []]], $doc->toArray()['paths']['/me']['get']['security']);
    }

    // ── Responses ────────────────────────────────────────────────────────────

    public function test_the_error_envelope_matches_the_kernel_shape(): void
    {
        $body = Response::error('Unprocessable.', withFields: true)->toArray();
        $error = $body['content']['application/json']['schema']['properties']['error'];

        $this->assertSame(['code', 'message', 'requestId', 'fields'], array_keys($error['properties']));
        $this->assertSame(['code', 'message'], $error['required']);
    }

    public function test_an_empty_response_has_no_content(): void
    {
        $this->assertSame(['description' => 'No content.'], Response::empty('No content.')->toArray());
    }

    public function test_json_output_is_pretty_and_does_not_escape_slashes(): void
    {
        $doc = $this->document();
        $doc->addOperation('/a/b', 'GET', Operation::create());

        $json = $doc->toJson();

        $this->assertStringContainsString('"/a/b"', $json);
        $this->assertStringContainsString("\n", $json);
        $this->assertStringEndsWith("\n", $json);
    }

    public function test_operation_count_reports_operations_not_paths(): void
    {
        $doc = $this->document();
        $doc->addOperation('/things', 'GET', Operation::create());
        $doc->addOperation('/things', 'POST', Operation::create());

        $this->assertSame(2, $doc->operationCount());
    }
}
