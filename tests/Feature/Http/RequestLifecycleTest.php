<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Fixtures\BlogModule\Provider as BlogProvider;
use Tests\Feature\Support\KernelTestCase;

/**
 * The whole lifecycle, end to end: boot → route → load → execute → response.
 *
 * Nothing here is mocked except the database, cache and queue. Every assertion
 * is on what a real request actually produced.
 */
#[Group('feature')]
final class RequestLifecycleTest extends KernelTestCase
{
    private function bootBlog(): void
    {
        $this->boot(modules: [BlogProvider::class]);
    }

    public function testAModuleRouteReachesItsControllerAndReturnsItsResponse(): void
    {
        $this->db->answer('FROM posts', [['id' => '1', 'title' => 'Hello']]);
        $this->bootBlog();

        $this->get('/posts')
            ->assertOk()
            ->assertExactJson(['posts' => [['id' => '1', 'title' => 'Hello']]]);
    }

    public function testARoutePrefixIsAppliedToEveryRouteInTheModule(): void
    {
        $this->bootBlog();

        // Declared as "" with routePrefix "/posts" — the bare path must 404.
        $this->get('/')->assertNotFound();
        $this->get('/posts')->assertOk();
    }

    public function testATypedParameterIsCapturedAndPassedToTheAction(): void
    {
        $this->db->answer('WHERE id = :id', [['id' => '42', 'title' => 'Answer']]);
        $this->bootBlog();

        $this->get('/posts/42')->assertOk()->assertJsonFragment(['id' => '42']);
    }

    /** {id:num} must reject a non-numeric segment at the ROUTER, not the controller. */
    public function testATypedParameterRejectsAValueOfTheWrongType(): void
    {
        $this->bootBlog();

        $this->get('/posts/not-a-number')->assertNotFound();
        self::assertFalse($this->db->ran('WHERE id = :id'), 'the request must not have reached the repository');
    }

    /** A literal always beats a placeholder, regardless of declaration order. */
    public function testAStaticRouteWinsOverADynamicOneOnTheSamePath(): void
    {
        $this->bootBlog();

        $this->get('/posts/me')->assertOk()->assertJsonFragment(['route' => 'mine']);
    }

    public function testAPostBodyReachesTheServiceAndTheResponseIs201(): void
    {
        $this->bootBlog();

        $this->post('/posts', ['title' => 'Written in a test'])
            ->assertCreated()
            ->assertJsonFragment(['title' => 'Written in a test']);

        self::assertTrue($this->db->ran('INSERT INTO posts'));
    }

    public function testAJsonBodyIsDecodedIntoInput(): void
    {
        $this->bootBlog();

        $this->postJson('/posts', ['title' => 'From JSON'])
            ->assertCreated()
            ->assertJsonFragment(['title' => 'From JSON']);
    }

    public function testTheServiceRunsInsideARealTransaction(): void
    {
        $this->bootBlog();

        $this->post('/posts', ['title' => 'Transactional'])->assertCreated();

        self::assertSame(['begin', 'commit'], $this->db->transactions);
    }

    /**
     * The rollback path — the one the framework's rules are most insistent
     * about, and the one a unit test with a fake transaction manager can only
     * assert indirectly.
     */
    public function testAFailedWriteRollsBackAndReturnsTheErrorEnvelope(): void
    {
        $this->bootBlog();
        $this->db->failNextWrite = new \RuntimeException('constraint violation');

        // 422, not 500: the documented hierarchy maps ServiceException there.
        $this->post('/posts', ['title' => 'Doomed'])
            ->assertUnprocessable()
            ->assertErrorCode('service.post');

        self::assertSame(['begin', 'rollback'], $this->db->transactions);
    }

    public function testA204CarriesNoBody(): void
    {
        $this->bootBlog();

        $this->delete('/posts/9')->assertNoContent()->assertEmptyBody();
    }

    public function testAThrowableBecomesTheFrameworksErrorEnvelope(): void
    {
        $this->bootBlog();

        $response = $this->get('/posts/boom')->assertServerError();

        $body = $response->json();
        self::assertArrayHasKey('error', $body);
        self::assertArrayHasKey('message', $body['error']);
        self::assertStringNotContainsString(
            'deliberate explosion',
            $response->body(),
            'An unhandled exception message must not leak to the client outside debug mode.',
        );
    }

    /**
     * The error envelope's requestId is the ONLY thing tying a user's screenshot
     * of a 500 to the line in the log. It was always empty: ErrorStage sat
     * OUTSIDE CorrelationIdStage, so the request it holds when it catches never
     * received the correlation_id attribute. Every error response, and every
     * ErrorContext handed to the notifiers, carried ''.
     */
    public function testAnErrorEnvelopeCarriesTheCorrelationId(): void
    {
        $this->bootBlog();

        $response = $this->get('/posts/boom', ['HTTP_X_CORRELATION_ID' => 'trace-me-123']);

        $response->assertServerError();
        self::assertSame('trace-me-123', $response->json()['error']['requestId'] ?? null);
    }

    public function testAnErrorResponseAlsoCarriesTheCorrelationIdHeader(): void
    {
        $this->bootBlog();

        $this->get('/posts/boom', ['HTTP_X_CORRELATION_ID' => 'trace-me-123'])
            ->assertHeader('X-Correlation-ID', 'trace-me-123');
    }

    public function testEveryResponseCarriesTheCorrelationId(): void
    {
        $this->bootBlog();

        $this->get('/posts')->assertHeader('X-Correlation-ID');
    }

    public function testAnInboundCorrelationIdIsPropagatedRatherThanReplaced(): void
    {
        $this->bootBlog();

        $this->get('/posts', ['HTTP_X_CORRELATION_ID' => 'from-the-caller'])
            ->assertHeader('X-Correlation-ID', 'from-the-caller');
    }

    /** HEAD is served by the GET route with the body stripped — see RouteMatcher. */
    public function testHeadIsServedByTheGetRouteWithNoBody(): void
    {
        $this->db->answer('FROM posts', [['id' => '1']]);
        $this->bootBlog();

        $this->call('HEAD', '/posts')->assertOk()->assertEmptyBody();
    }

    public function testAnUnknownPathIs404(): void
    {
        $this->bootBlog();

        $this->get('/nothing-here')->assertNotFound();
    }

    /**
     * Off by default on purpose: a 405 confirms the path exists and hands a
     * scanner free reconnaissance.
     */
    public function testAMethodMismatchIs404UntilTheFlagIsSet(): void
    {
        $this->bootBlog();

        $this->put('/posts/1')->assertNotFound();
    }

    public function testTheIdentityReachesTheControllerAndTheRepository(): void
    {
        $this->bootBlog();

        $this->actingAs(Identity::asUser('user-7', 'tenant-x'), 'GET', '/posts/me')
            ->assertOk()
            ->assertJsonFragment(['user' => 'user-7']);
    }

    /** Tenant scoping is a rule; here it is an observed parameter. */
    public function testTheRepositoryScopesEveryQueryToTheIdentitysTenant(): void
    {
        $this->db->answer('FROM posts', []);
        $this->bootBlog();

        $this->actingAs(Identity::asUser('user-7', 'tenant-x'), 'GET', '/posts')->assertOk();

        self::assertSame('tenant-x', $this->db->statements[0]['params']['tenant']);
    }
}
