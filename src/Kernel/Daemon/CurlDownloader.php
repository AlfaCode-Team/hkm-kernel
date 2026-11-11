<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Daemon;

use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\Exceptions\DaemonException;

/**
 * `ext-curl`, streaming to disk, with TLS verification left ON.
 *
 * TLS VERIFICATION IS NOT A SETTING HERE
 * --------------------------------------
 * There is no option to disable it and no `CURLOPT_SSL_VERIFYPEER => false`
 * anywhere in this class. The digest check that follows is a second line of
 * defence, not a reason to skip the first: a disabled verify turns a captive
 * portal or an intercepting proxy into a silent code-substitution point, and
 * the only thing left standing between it and a service running as root is
 * whether the plugin's pinned digest happens to differ.
 *
 * REDIRECTS ARE FOLLOWED, BOUNDED, AND ONLY TO https
 * --------------------------------------------------
 * GitHub release downloads are a 302 to an object store, so following
 * redirects is mandatory rather than convenient. `CURLOPT_REDIR_PROTOCOLS`
 * pins the hops to HTTPS, because the default allows a redirect to downgrade
 * the whole transfer to plain HTTP and curl will follow it without comment.
 *
 * A NON-2xx RESPONSE IS NOT A FILE
 * --------------------------------
 * A 404 from a release asset returns a body, and written to disk it is a
 * perfectly good file of the wrong thing. Checking the status is what turns
 * "the plugin pinned a version that was never published" into that sentence
 * instead of a digest mismatch that reads like tampering.
 */
final class CurlDownloader implements Downloader
{
    public function __construct(
        private readonly int $timeoutSeconds = 300,
        private readonly int $connectTimeoutSeconds = 15,
        private readonly string $userAgent = 'hkm-kernel-daemon-provisioner',
    ) {}

    public function fetch(string $url, string $destination): int
    {
        if (!\extension_loaded('curl')) {
            throw DaemonException::toolMissing('ext-curl');
        }

        $directory = \dirname($destination);

        if (!is_dir($directory) && !@mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw DaemonException::notWritable($directory, 'the directory could not be created');
        }

        $handle = @fopen($destination, 'wb');

        if ($handle === false) {
            throw DaemonException::notWritable($destination, 'could not be opened for writing');
        }

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL             => $url,
            CURLOPT_FILE            => $handle,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 5,
            // The default permits a downgrade to http on a redirect hop.
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_CONNECTTIMEOUT  => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT         => $this->timeoutSeconds,
            CURLOPT_USERAGENT       => $this->userAgent,
            CURLOPT_FAILONERROR     => false,
        ]);

        $ok     = curl_exec($curl);
        $error  = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        // No curl_close(): deprecated in PHP 8.5 and a no-op since 8.0, where
        // the handle became an object freed by the garbage collector. Calling
        // it emits a deprecation on every download.
        unset($curl);
        fclose($handle);

        if ($ok === false) {
            @unlink($destination);

            throw DaemonException::downloadFailed($url, $error !== '' ? $error : 'the transfer failed');
        }

        if ($status < 200 || $status >= 300) {
            // Deleted, because a 404 body on disk is a file — and the digest
            // check would then report a mismatch, which reads like tampering
            // rather than a wrong version.
            @unlink($destination);

            throw DaemonException::downloadFailed(
                $url,
                "the server answered HTTP {$status}" . (
                    $status === 404
                        ? '. Either the pinned version was never published, or the asset is named '
                          . 'differently for this platform — check the release page.'
                        : ''
                ),
            );
        }

        clearstatcache(true, $destination);
        $size = @filesize($destination);

        if ($size === false || $size === 0) {
            @unlink($destination);

            throw DaemonException::downloadFailed($url, 'the response was empty');
        }

        return $size;
    }
}
