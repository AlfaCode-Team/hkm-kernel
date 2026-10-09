<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * What Request::ip() returns, with and without a trusted proxy — the contract
 * the generated entry points rely on (TRUSTED_PROXIES, and the OpenSwoole
 * adapter passing REMOTE_ADDR into Request::build()).
 */
final class ClientIpTest extends TestCase
{
    protected function tearDown(): void
    {
        Request::setTrustedProxies([], 0);   // process-wide: never leak into other tests
    }

    private function request(string $peer, ?string $forwardedFor = null): Request
    {
        return Request::build(
            method: 'GET',
            path: '/',
            headers: $forwardedFor === null ? [] : ['X-Forwarded-For' => $forwardedFor],
            server: ['REMOTE_ADDR' => $peer],
        );
    }

    public function testTheTcpPeerIsTheClientIp(): void
    {
        self::assertSame('203.0.113.7', $this->request('203.0.113.7')->ip());
    }

    public function testAForwardedForHeaderIsIgnoredWhenNoProxyIsTrusted(): void
    {
        self::assertSame('10.0.0.2', $this->request('10.0.0.2', '198.51.100.9')->ip());
    }

    public function testAForwardedForHeaderIsHonouredFromATrustedProxy(): void
    {
        Request::setTrustedProxies(['10.0.0.0/8'], Request::HEADER_X_FORWARDED_FOR);

        self::assertSame('198.51.100.9', $this->request('10.0.0.2', '198.51.100.9')->ip());
    }

    public function testAForwardedForHeaderFromAnUntrustedPeerIsStillIgnored(): void
    {
        Request::setTrustedProxies(['10.0.0.0/8'], Request::HEADER_X_FORWARDED_FOR);

        self::assertSame('203.0.113.7', $this->request('203.0.113.7', '198.51.100.9')->ip());
    }
}
