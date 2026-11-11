<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Support\KernelTestCase;
use Tests\Fixtures\FilterProviderModule\Provider as FilterProvider;

/**
 * A route naming a filter alias nobody registered must fail at pipeline build,
 * and the failure should name the plugin that was supposed to provide it.
 *
 * "no Provider registered [auth]" is the symptom. "the module solving
 * [filters.provider] declares that it provides this alias" is the cause, and
 * before `providesFilters` existed there was no way for the kernel to know it —
 * the reader had to already be able to answer the question the error asked.
 */
#[Group('feature')]
final class StrictRouteFiltersTest extends KernelTestCase
{
    /** @return array{string, string} the route and the message */
    private function failureFor(array $modules): array
    {
        $kernel = $this->boot(
            modules: $modules,
            routes: [[
                'method' => 'GET',
                'path' => '/guarded',
                'handler' => 'Tests\\Feature\\Kernel\\ProjectPingController@ping',
                'filters' => ['auth'],
            ]],
        );

        try {
            $kernel->http()->handle(Request::build(method: 'GET', path: '/guarded'));
        } catch (\InvalidArgumentException $e) {
            return ['/guarded', $e->getMessage()];
        }

        self::fail('Expected the pipeline build to reject the unregistered filter alias.');
    }

    public function test_an_unregistered_alias_fails_at_pipeline_build(): void
    {
        [, $message] = $this->failureFor([]);

        self::assertStringContainsString('GET /guarded', $message);
        self::assertStringContainsString('[auth]', $message);
        self::assertStringContainsString('no Provider::boot() registered', $message);
    }

    /**
     * The payoff of the new declaration: the message points at the plugin.
     *
     * FilterProviderModule declares providesFilters ["auth","throttle"] in its
     * module.json but never calls $http->filter() — which is exactly the shape
     * of "the right plugin is installed but something is wrong with its wiring",
     * and also of "you removed it from withModules()".
     */
    public function test_the_failure_names_the_declared_owner_when_one_exists(): void
    {
        [, $message] = $this->failureFor([FilterProvider::class]);

        self::assertStringContainsString('filters.provider', $message);
        self::assertStringContainsString('withModules()', $message);
    }

    /** With nothing declaring ownership the message degrades to what it always said. */
    public function test_the_message_omits_an_owner_when_nothing_declares_one(): void
    {
        [, $message] = $this->failureFor([]);

        self::assertStringNotContainsString('declares that it provides', $message);
    }
}
