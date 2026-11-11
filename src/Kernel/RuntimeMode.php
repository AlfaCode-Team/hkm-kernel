<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel;

/**
 * RuntimeMode — the surface a kernel is materialized for.
 *
 * build() compiles manifests but constructs no pipelines. The first call to an
 * entry point (http()/cli()/workerLoop()/server()) materializes the kernel for
 * that surface: pipelines are constructed, modules are wired ONCE, and the core
 * container is frozen. Only the surface actually used does any real work — the
 * manifest-backed pipelines defer their disk I/O until their own first run, so
 * a process that only ever serves HTTP never reads the job manifest, and a CLI
 * process never reads the route manifest.
 *
 * FOUR CASES, NOT FIVE — PHP-FPM AND OPENSWOOLE ARE ONE SURFACE
 * -------------------------------------------------------------
 * They differ in how long the process lives, not in what it does: both answer
 * requests, through the same HttpPipeline, with the same stages. The one place
 * the difference matters is per-request teardown, which is why
 * {@see Kernel::requestTeardown()} exists and is a no-op under FPM. Splitting
 * the enum would put a condition on every consumer for a distinction almost
 * none of them need.
 *
 * WHY Server IS ITS OWN CASE
 * --------------------------
 * The other three are request-shaped: something arrives, it is handled, the
 * process ends by finishing. A server inverts that — it owns its loop, holds
 * state for weeks, and ends only when it is told to. Two things follow that the
 * other surfaces never have to think about: it must be ASKED to stop (SIGTERM
 * is part of its contract, not an interruption of it), and nothing in it may
 * leak, because a per-unit allocation never freed is invisible under FPM and
 * fatal here.
 */
enum RuntimeMode: string
{
    case Http   = 'http';
    case Cli    = 'cli';
    case Worker = 'worker';
    case Server = 'server';
}
