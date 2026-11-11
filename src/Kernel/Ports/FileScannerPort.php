<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

/**
 * FileScannerPort — asks an external malware scanner about a stored file.
 *
 * WHY THIS IS SEPARATE FROM {@see FileSanitizerPort}
 * --------------------------------------------------
 * They answer different questions and have different costs. A sanitiser rewrites
 * bytes in memory, synchronously, on the way in, and knows the answer itself. A
 * scanner asks a daemon with a signature database that is updated daily, takes
 * long enough that it does not belong in a request, and can be unreachable.
 *
 * That last property is what shapes this contract. The scan is ASYNCHRONOUS by
 * nature: the natural consumer is a queued job that runs after the upload is
 * stored, records the outcome, and lets a download gate read it.
 *
 * {@see ScanStatus::isServable()} therefore reports Pending as NOT servable —
 * that is the posture this port recommends, and the default a new gate should
 * take. It is a recommendation and not a guarantee: a gate is free to serve
 * Pending, and an existing consumer does. `hkm-plugin-file-manager` gates only
 * on `infected`, deliberately, because its `scan_status` column shipped before
 * any scanner existed and withholding Pending would have broken every
 * deployment that set `scan_required` with no scanner installed. Read the gate
 * in the consumer; do not infer it from here.
 *
 * WHY A KERNEL PORT
 * -----------------
 * Same reasoning as {@see FileSanitizerPort}: several unrelated capabilities
 * store user uploads, and the contract belongs where they can all depend on it
 * without depending on each other. The implementation — a socket client for a
 * scanning daemon — is a plugin, like every other port implementation.
 *
 * ON FAILING SAFE
 * ---------------
 * This is the one port in the kernel that must NOT fail open.
 * {@see \AlfacodeTeam\PhpServicePlatform\Kernel\Security\Layers\WafLayer}
 * lets a request through when its own cache breaks, because a firewall that
 * takes the site down has become the attack. The trade here is the other way
 * round: the cost of withholding a file until it can be scanned is a delay,
 * while the cost of serving an unscanned file is distributing malware under the
 * application's own domain. So an unreachable scanner yields
 * {@see ScanVerdict::unavailable()}, the file stays Pending, and it is not
 * served until something concludes.
 */
interface FileScannerPort
{
    /**
     * Scan a stored file and report what was found.
     *
     * $path is a path the SCANNER can read, which is not always a path PHP can
     * read: a scanning daemon in another container sees a shared volume under
     * its own mount point. An implementation owns that translation, and a
     * caller passes the path its StoragePort gave it.
     *
     * MUST NOT throw for an unreachable or unresponsive scanner — return
     * {@see ScanVerdict::unavailable()}, which keeps the file withheld and lets
     * the caller retry. An exception here would surface as a failed job for a
     * condition that is routine and self-correcting.
     *
     * MUST NOT return {@see ScanVerdict::clean()} for any file it did not
     * actually scan. "Clean" means a scanner looked and found nothing; every
     * other circumstance is `unavailable`.
     */
    public function scan(string $path): ScanVerdict;
}
