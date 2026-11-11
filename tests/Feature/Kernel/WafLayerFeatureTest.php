<?php

declare(strict_types=1);

namespace Tests\Feature\Kernel;

use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Layers\WafLayer;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Fixtures\BlogModule\Provider as BlogProvider;
use Tests\Feature\Support\KernelTestCase;

/**
 * WafLayer inside a running kernel: refused before any module loads, and wired
 * to the CachePort bound with withPorts() without being handed it.
 */
#[Group('feature')]
final class WafLayerFeatureTest extends KernelTestCase
{
    public function testAScannerProbeIsRefusedBeforeAnyModuleLoads(): void
    {
        $this->db->answer('FROM posts', [['id' => '1']]);
        $this->boot(modules: [BlogProvider::class], security: [new WafLayer()]);

        $this->get('/posts?q=' . rawurlencode("1' UNION SELECT password FROM users"))->assertForbidden();

        self::assertSame([], $this->db->statements, 'A WAF denial must not reach any module.');
    }

    public function testACleanRequestStillReachesTheRoute(): void
    {
        $this->db->answer('FROM posts', [['id' => '1']]);
        $this->boot(modules: [BlogProvider::class], security: [new WafLayer()]);

        $this->get('/posts?q=awards')->assertOk();
    }

    public function testTheKernelGivesTheLayerTheBoundCachePortForBans(): void
    {
        $this->db->answer('FROM posts', [['id' => '1']]);
        $this->boot(modules: [BlogProvider::class], security: [new WafLayer(banAfter: 1)]);

        $this->get('/.env')->assertForbidden();
        $this->get('/posts')->assertForbidden();
    }
}
