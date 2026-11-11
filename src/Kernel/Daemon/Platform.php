<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Daemon;

use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\Exceptions\DaemonException;

/**
 * Which release asset this machine needs: `darwin-arm64`, `linux-amd64`, …
 *
 * WHY THE TOKENS ARE GO'S AND NOT PHP'S
 * -------------------------------------
 * Every daemon this mechanism provisions is a Go program, because that is what
 * single-binary infrastructure is written in — so their release assets are
 * named with `GOOS` and `GOARCH`, and those are the tokens a plugin's URL
 * template interpolates. PHP reports the same facts under different names
 * (`Darwin`, `x86_64`, `aarch64`), so this translates once, here, instead of in
 * each plugin's manifest.
 *
 * It is a closed mapping on purpose. An unrecognised architecture becomes an
 * error naming what was detected, never a guess: a guess produces a download
 * that is the wrong machine code, and the failure then surfaces as
 * "Exec format error" from a service unit at boot — which is a long way from
 * the cause.
 *
 * WINDOWS IS REFUSED, AND SAYING SO IS KINDER THAN HALF-TRYING
 * -----------------------------------------------------------
 * These daemons publish `.zip` on Windows, are supervised by the Service
 * Control Manager rather than systemd or launchd, and resolve paths
 * differently. Supporting it means a second archive reader, a third service
 * backend and a path layer, none of which can be tested here. One clear
 * refusal beats three partial implementations.
 */
final readonly class Platform
{
    private function __construct(
        /** `darwin` | `linux` */
        public string $os,
        /** `amd64` | `arm64` */
        public string $arch,
    ) {}

    /**
     * This machine.
     *
     * @throws DaemonException on an OS or architecture that cannot be served
     */
    public static function current(): self
    {
        return self::of(PHP_OS_FAMILY, php_uname('m'));
    }

    /**
     * Build from raw OS-family and machine strings.
     *
     * Separate from {@see self::current()} so every branch is testable; the
     * alternative is a class whose behaviour can only be observed on the
     * machine the tests happen to run on, which means three of the four
     * branches are never exercised.
     *
     * @throws DaemonException
     */
    public static function of(string $osFamily, string $machine): self
    {
        $os = match (strtolower(trim($osFamily))) {
            'darwin' => 'darwin',
            'linux'  => 'linux',
            'windows' => throw DaemonException::unsupportedPlatform(
                'Windows. These daemons ship .zip archives there and are supervised by the Service '
                . 'Control Manager rather than systemd or launchd, so provisioning would be a '
                . 'different mechanism rather than a different token. Install the daemon with its '
                . 'own Windows installer and point the project at it.',
            ),
            default => throw DaemonException::unsupportedPlatform(
                'PHP reports the OS family as [' . $osFamily . '], which is neither Darwin nor Linux.',
            ),
        };

        $arch = match (strtolower(trim($machine))) {
            'x86_64', 'amd64'  => 'amd64',
            'arm64', 'aarch64' => 'arm64',
            default            => throw DaemonException::unsupportedPlatform(
                'the machine architecture is [' . $machine . ']. Only x86_64/amd64 and '
                . 'arm64/aarch64 are mapped; a guess here downloads the wrong machine code and the '
                . 'failure surfaces as "Exec format error" from a service unit at boot.',
            ),
        };

        return new self($os, $arch);
    }

    /** `darwin-arm64` — the key a plugin pins its checksums under. */
    public function key(): string
    {
        return "{$this->os}-{$this->arch}";
    }

    /**
     * The supervisor this platform uses.
     *
     * Not a detail of the daemon: the SAME binary and the SAME config are
     * supervised by launchd on a Mac and systemd on a server, and the unit is
     * the only part that differs.
     */
    public function supervisor(): string
    {
        return $this->os === 'darwin' ? 'launchd' : 'systemd';
    }
}
