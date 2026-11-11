<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Daemon;

use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\Downloader;
use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\Exceptions\DaemonException;

/**
 * Serves bytes the test chose, and records that it was asked.
 *
 * The two things this makes testable are the two that matter most and are the
 * hardest to arrange against a real server: bytes whose digest does NOT match
 * what was pinned, and the assertion that for an unpinned platform nothing is
 * requested AT ALL. The second is not a property of the response — it is the
 * absence of a request, and only a recording double can see that.
 *
 * @internal
 */
final class RecordingDownloader implements Downloader
{
    /** @var list<string> every URL asked for, in order */
    public array $fetched = [];

    public function __construct(
        private readonly string $body,
        private readonly bool $fail = false,
    ) {}

    public function fetch(string $url, string $destination): int
    {
        $this->fetched[] = $url;

        if ($this->fail) {
            throw DaemonException::downloadFailed($url, 'the scripted downloader was told to fail');
        }

        $directory = \dirname($destination);

        if (!is_dir($directory)) {
            mkdir($directory, 0o750, true);
        }

        file_put_contents($destination, $this->body);

        return \strlen($this->body);
    }
}
