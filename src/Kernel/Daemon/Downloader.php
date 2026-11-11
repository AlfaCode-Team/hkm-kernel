<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Daemon;

use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\Exceptions\DaemonException;

/**
 * Fetch a URL to a local file.
 *
 * An interface for one reason: {@see DaemonInstaller} is the class that decides
 * whether bytes are acceptable, and a test of that decision must be able to
 * supply bytes that are not — a truncated archive, a tampered one, an HTML
 * error page served with a 200. Behind a real `curl` each of those needs a
 * server that misbehaves on demand.
 *
 * It writes to a path rather than returning a string because these downloads
 * are tens of megabytes and the digest is computed by streaming the file; a
 * `string` return would hold the whole archive in memory for no benefit, twice
 * over once it is also written out.
 */
interface Downloader
{
    /**
     * Fetch $url into $destination, replacing whatever is there.
     *
     * @return int bytes written
     * @throws DaemonException on any failure — a transport error, a non-2xx
     *         status, or a redirect chain that does not resolve. NEVER a
     *         partial file left behind as if it had succeeded: the next step is
     *         a digest check, and a truncated download would fail it with a
     *         mismatch message, sending the operator to look for tampering
     *         where there was only a dropped connection.
     */
    public function fetch(string $url, string $destination): int;
}
