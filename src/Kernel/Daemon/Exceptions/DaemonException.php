<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\Exceptions;

/**
 * A daemon could not be provisioned, and the message says what to do instead.
 *
 * Every constructor here is reachable by an operator running one command on a
 * machine they are trying to deploy to, so the messages carry the next action
 * rather than the failed assertion. "sha256 mismatch" is a true statement that
 * leaves somebody reading source; naming the two digests and saying which file
 * to re-pin does not.
 */
final class DaemonException extends \RuntimeException
{
    private function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function malformed(string $plugin, string $reason): self
    {
        return new self(
            "The `daemon` block in {$plugin}/module.json is unusable: {$reason}. "
            . 'It is the plugin\'s own declaration, so this is a bug in the plugin rather than in '
            . 'this project\'s configuration.',
        );
    }

    /**
     * No digest is pinned for the platform we are running on.
     *
     * Refusing is the whole point. See
     * {@see \AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\DaemonSpec::digestFor()}
     * — a provisioner that downloads an unpinned binary because it has no
     * digest to check is not verifying anything, and the moment it does that
     * once it will do it on every platform nobody remembered to pin.
     *
     * @param list<string> $pinned
     */
    public static function platformNotPinned(string $name, string $platform, array $pinned): self
    {
        return new self(
            "{$name} has no pinned checksum for {$platform}, so it will not be downloaded here. "
            . 'Pinned: ' . (
                $pinned === [] ? '(none at all)' : implode(', ', $pinned)
            ) . ". Add the sha256 for {$platform} to the plugin's module.json `daemon.platforms`, "
            . 'or install the daemon through the operating system\'s package manager and point the '
            . 'project at it. Downloading it unverified is not offered.',
        );
    }

    public static function downloadFailed(string $url, string $reason): self
    {
        return new self("Could not download {$url}: {$reason}");
    }

    /**
     * The bytes are not the bytes that were pinned.
     *
     * This is the message that matters most in the whole class, because the
     * innocent cause (the plugin pinned the wrong digest, or the upstream
     * re-cut a release under the same tag) and the serious one (somebody is
     * serving different bytes) are indistinguishable from here. So it describes
     * both and recommends nothing that would paper over the second.
     */
    public static function digestMismatch(string $url, string $expected, string $actual): self
    {
        return new self(
            "Refusing to install {$url}: its sha256 is\n"
            . "  actual   {$actual}\n"
            . "  expected {$expected}\n"
            . 'The download was deleted. Either the plugin pinned the wrong digest, the upstream '
            . 're-published this version under the same tag, or these are not the bytes the '
            . 'publisher released. There is no flag to skip this check: the digest is the only '
            . 'thing standing between a release tag and arbitrary code running as a service.',
        );
    }

    public static function extractFailed(string $member, string $archive, string $reason): self
    {
        return new self(
            "Could not take [{$member}] out of {$archive}: {$reason}. The archive's digest matched, "
            . 'so this is a wrong `daemon.member` path in the plugin rather than a bad download — '
            . 'the member is spelled exactly as `tar -tzf` prints it.',
        );
    }

    public static function unsupportedArchive(string $archive): self
    {
        return new self(
            "Unsupported archive type [{$archive}]. Only tar.gz is handled, because that is what "
            . 'every daemon this mechanism exists for publishes, and because a single named member '
            . 'can be taken out of it without unpacking anything else.',
        );
    }

    public static function unsupportedPlatform(string $detail): self
    {
        return new self("This platform cannot be provisioned automatically: {$detail}");
    }

    public static function templateMissing(string $path): self
    {
        return new self(
            "The plugin declares a config template at [{$path}] and there is no such file. Nothing "
            . 'was written: generating a daemon config from a missing template would produce an '
            . 'empty one, and an empty config means the daemon starts with ITS defaults — which for '
            . 'a message bus means listening on every interface with no authentication.',
        );
    }

    /** @param list<string> $tokens */
    public static function unresolvedTokens(string $path, array $tokens): self
    {
        return new self(
            "The rendered config for [{$path}] still contains " . \count($tokens) . ' unresolved '
            . 'placeholder(s): ' . implode(', ', $tokens) . '. Writing it would hand the daemon a '
            . 'config containing literal braces, which it either rejects or — worse — reads as a '
            . 'value. Nothing was written.',
        );
    }

    public static function configRejected(string $path, string $output): self
    {
        return new self(
            "The daemon refused the generated config at {$path}:\n{$output}\n"
            . 'The file was kept so it can be read and fixed, but it must not be used as it stands.',
        );
    }

    public static function notWritable(string $path, string $reason): self
    {
        return new self("Cannot write {$path}: {$reason}");
    }

    public static function toolMissing(string $tool): self
    {
        return new self(
            "[{$tool}] is not on PATH, and it is needed to unpack the download. Install it, or "
            . 'install the daemon through the operating system\'s package manager instead.',
        );
    }
}
