<?php

declare(strict_types=1);

/**
 * =============================================================================
 *  CLI ENTRY POINT  (app/cli/run.php)
 * =============================================================================
 *
 * The terminal surface of your application. Run console commands through it:
 *
 *   php app/cli/run.php list                 # show all registered commands
 *   php app/cli/run.php <command> [args...]  # run a command
 *   php app/cli/run.php migrate              # (example) run migrations
 *
 * There is no Host header on the CLI, so domain resolution is skipped. The
 * bootstrap loads the .env cascade from $_SERVER['argv'], so flags like
 * `--env=production` / `--domain=...` still select the correct .env tier.
 *
 * Flow:
 *   1. Load the autoloaders (kernel + plugins + your code).
 *   2. Require the project bootstrap → fully-built Kernel. The bootstrap itself
 *      loads .env (argv-aware) and installs the pre-kernel error net, so this
 *      entry point does NOT duplicate that work.
 *   3. Run the CLI pipeline and exit with the command's status code.
 * =============================================================================
 */

// 1. Autoloaders.
require_once __DIR__ . '/../bootstrap/kernel-autoload.php';
psp_require_kernel_autoload();

// 2. Build the application. app/bootstrap/app.php loads the .env cascade
//    (reading $_SERVER['argv'] for --env / --domain) and installs ErrorGuard.
/** @var \AlfacodeTeam\PhpServicePlatform\Kernel\Kernel $kernel */
$kernel = require __DIR__ . '/../bootstrap/app.php';

// 3. Dispatch the command and propagate its exit code to the shell (0 = success,
//    non-zero = failure) so scripts and CI can react to it.
exit($kernel->cli()->run($argv));
