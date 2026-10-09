<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Fixtures\HookModule\Provider as HookProvider;
use Tests\Feature\Support\KernelTestCase;

/**
 * Every hook slot a module can register into must actually run.
 *
 * `after.execute` used to be appended BEHIND ExecuteStage, which is terminal
 * (it returns the controller's Response and never calls $next), so a stage
 * registered there was silently never reached. No test covered any slot.
 */
#[Group('feature')]
final class PipelineHooksTest extends KernelTestCase
{
    /** @return array<string, string> */
    private function hookedResponseHeaders(): array
    {
        $kernel = $this->boot(modules: [HookProvider::class]);

        $response = $kernel->http()->handle(Request::build(method: 'POST', path: '/hooked'));

        self::assertSame(201, $response->status());

        return $response->headers();
    }

    public function testEveryHookSlotRuns(): void
    {
        $headers = $this->hookedResponseHeaders();

        self::assertArrayHasKey('X-Hook-Security', $headers);
        self::assertArrayHasKey('X-Hook-Load', $headers);
        self::assertArrayHasKey('X-Hook-Execute', $headers, 'an after.execute hook must be reached');
    }

    /** An after.execute stage sees the controller's own Response on the way out. */
    public function testAnAfterExecuteHookReceivesTheControllersResponse(): void
    {
        self::assertSame('201', $this->hookedResponseHeaders()['X-Hook-Execute'] ?? null);
    }
}
