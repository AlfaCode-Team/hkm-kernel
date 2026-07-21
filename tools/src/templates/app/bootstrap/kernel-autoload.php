<?php

declare(strict_types=1);

/**
 * =============================================================================
 *  AUTOLOAD BOOTSTRAP  (app/bootstrap/kernel-autoload.php)
 * =============================================================================
 *
 * This is the VERY FIRST file every entry point of your project loads — before
 * the kernel, before your .env, before anything. Its single job is to make the
 * autoloaders available so PHP can find:
 *
 *   1. The FRAMEWORK KERNEL classes  → namespace AlfacodeTeam\PhpServicePlatform\Kernel\
 *   2. The PLUGINS you depend on      → namespace Plugins\
 *   3. YOUR project code              → namespace App\  and  <Studly>\  (this project's src/)
 *
 * -----------------------------------------------------------------------------
 *  WHY A CUSTOM AUTOLOAD FILE (the "hybrid global-kernel model")
 * -----------------------------------------------------------------------------
 * In this model the framework kernel is installed ONCE, GLOBALLY (via
 * `composer global require alfacode-team/php-service-platform`) and shared by
 * every project on the machine. Each project keeps ONLY its own plugins and
 * `src/` in a LOCAL `vendor/`. The benefit: many projects, one kernel copy,
 * upgraded in one place.
 *
 * That means a single `require 'vendor/autoload.php'` is NOT enough — the kernel
 * may not live in this project's vendor at all. This file resolves both halves:
 *
 *   LOCAL vendor   → registers Plugins\ + your project PSR-4 (App\, <Studly>\)
 *   GLOBAL vendor  → registers the kernel namespace, only if not already loaded
 *
 * -----------------------------------------------------------------------------
 *  RESOLUTION ORDER (first hit that provides the kernel class wins)
 * -----------------------------------------------------------------------------
 *   1. ./vendor/autoload.php                  — this project's local vendor.
 *                                               Always loaded first so plugins +
 *                                               your code register. If you also
 *                                               `composer require` the kernel
 *                                               locally, this alone is enough and
 *                                               the steps below are skipped.
 *   2. $PSP_GLOBAL_AUTOLOAD                    — explicit override env var. Point
 *                                               it at any vendor/autoload.php
 *                                               (e.g. the monorepo's) to reuse a
 *                                               specific kernel + its plugins.
 *   3. $COMPOSER_HOME/vendor/autoload.php     — Composer's configured home.
 *   4. ~/.config/composer/vendor/autoload.php — Linux/macOS default global home.
 *   5. ~/.composer/vendor/autoload.php        — older Composer default home.
 *
 * If NONE provide the kernel class, the function prints an actionable message
 * (CLI → STDERR, web → HTTP 500 + plain text) and hard-exits with code 1. We
 * `exit()` rather than throw because at this point the kernel's error pipeline
 * does not exist yet — there is nothing to catch a throw.
 *
 * -----------------------------------------------------------------------------
 *  HOW TO CUSTOMISE
 * -----------------------------------------------------------------------------
 *   - Kernel in a non-standard location?      set PSP_GLOBAL_AUTOLOAD=/abs/.../vendor/autoload.php
 *   - Want a fully self-contained project?    `composer require alfacode-team/php-service-platform`
 *                                              locally; step 1 then satisfies everything.
 *   - The function is guarded by function_exists() + the class_exists() early
 *     returns, so requiring this file more than once (every entry script does)
 *     is safe and cheap — no autoloader is registered twice.
 *
 * `dirname(__DIR__, 2)` walks up two levels (bootstrap → app → project root)
 * because this file lives at <project>/app/bootstrap/kernel-autoload.php.
 * =============================================================================
 */

if (!function_exists('psp_require_kernel_autoload')) {
    /**
     * Locate and require the autoloader(s) that provide the framework kernel.
     *
     * Idempotent: safe to call repeatedly. Returns as soon as the kernel class
     * is resolvable; hard-exits with code 1 if it can never be found.
     */
    function psp_require_kernel_autoload(): void
    {
        // The canonical "is the framework available?" probe. As soon as this
        // class exists, every kernel namespace is autoloadable and we are done.
        $kernelClass = \AlfacodeTeam\PhpServicePlatform\Kernel\Kernel::class;

        // --- STEP 1: local vendor -------------------------------------------
        // Load this project's own vendor first. This registers Plugins\ and the
        // project's PSR-4 roots (App\, <Studly>\). It MIGHT also already contain
        // the kernel (if you required it locally) — checked right after.
        $localVendor = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (is_file($localVendor)) {
            require_once $localVendor;
        }
        // Local vendor already provided the kernel → nothing more to do.
        if (class_exists($kernelClass)) {
            return;
        }

        // --- STEP 2-5: build the ordered list of GLOBAL autoload candidates --
        // Each entry is a possible vendor/autoload.php that may hold the kernel.
        // They are tried in priority order until one resolves the kernel class.
        $candidates = [];

        // (2) Explicit override — highest priority after local vendor. Lets an
        // operator or a test harness pin an exact kernel install.
        $explicit = getenv('PSP_GLOBAL_AUTOLOAD');
        if (is_string($explicit) && $explicit !== '') {
            $candidates[] = $explicit;
        }

        // (3) Composer's configured home directory, if COMPOSER_HOME is set.
        $composerHome = getenv('COMPOSER_HOME');
        if (is_string($composerHome) && $composerHome !== '') {
            $candidates[] = rtrim($composerHome, '/\\') . '/vendor/autoload.php';
        }

        // (4)+(5) Default global Composer homes on Linux/macOS.
        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            $home = rtrim($home, '/\\');
            $candidates[] = $home . '/.config/composer/vendor/autoload.php'; // current default
            $candidates[] = $home . '/.composer/vendor/autoload.php';        // legacy default
        }

        // Try each candidate; the first one that makes the kernel class
        // resolvable wins and we return immediately.
        foreach ($candidates as $autoload) {
            if (is_string($autoload) && is_file($autoload)) {
                require_once $autoload;
                if (class_exists($kernelClass)) {
                    return;
                }
            }
        }

        // --- FAILURE: kernel not found anywhere -----------------------------
        // We cannot continue without the framework, and the kernel's own error
        // handling is not installed this early, so report clearly and exit(1).
        $msg = "[PSP] Could not load the global kernel autoload.\n"
            . "Install globally: composer global require alfacode-team/php-service-platform\n"
            . "Or set PSP_GLOBAL_AUTOLOAD=/absolute/path/to/vendor/autoload.php\n";

        // STDERR only exists under the CLI SAPI; the web SAPI has no such
        // constant, so branch on PHP_SAPI to emit the error correctly.
        if (\PHP_SAPI === 'cli' || \PHP_SAPI === 'phpdbg') {
            fwrite(\defined('STDERR') ? STDERR : fopen('php://stderr', 'w'), $msg);
        } else {
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=utf-8');
            }
            echo $msg;
        }
        exit(1);
    }
}

if (!function_exists('psp_kernel_home')) {
    /**
     * Resolve the framework KERNEL HOME — the directory that owns the shared
     * `plugins/` tree — used by the `require`s that the `hkm plugins` tooling
     * wires into this bootstrap (e.g. a kernel plugin's Support/helpers.php).
     *
     * Resolution order:
     *   1. HKM_KERNEL_HOME env var (set by `hkm run`, the launcher, or the operator)
     *   2. the $fallback captured when the require was wired (dev-machine path)
     *   3. derived from the loaded kernel — walk up from the Kernel class file to
     *      the first ancestor directory that owns a plugins/ tree
     *
     * If NONE resolve, the framework is not installed correctly: report clearly
     * and hard-exit, rather than let a later require_once fatal cryptically with
     * "failed to open stream".
     */
    function psp_kernel_home(?string $fallback = null): string
    {
        static $home = null;
        if ($home !== null) {
            return $home;
        }

        // 1. Explicit environment variable — the canonical, relocatable source.
        $env = getenv('HKM_KERNEL_HOME');
        if (is_string($env) && $env !== '' && is_dir($env)) {
            return $home = rtrim($env, "/\\");
        }

        // 2. Wire-time fallback (the kernel path known when the require was added).
        if (is_string($fallback) && $fallback !== '' && is_dir($fallback)) {
            return $home = rtrim($fallback, "/\\");
        }

        // 3. Derive from the already-loaded kernel package.
        $kernelClass = \AlfacodeTeam\PhpServicePlatform\Kernel\Kernel::class;
        if (class_exists($kernelClass)) {
            $file = (new \ReflectionClass($kernelClass))->getFileName();
            if (is_string($file) && $file !== '') {
                $dir = dirname($file);
                for ($i = 0; $i < 8 && $dir !== dirname($dir); $i++, $dir = dirname($dir)) {
                    if (is_dir($dir . '/plugins')) {
                        return $home = $dir;
                    }
                }
            }
        }

        // Nothing resolved — the framework is not installed correctly.
        $msg = "[PSP] HKM_KERNEL_HOME is not set and the framework kernel could not be\n"
            . "located — the framework is not installed correctly.\n"
            . "Set it to the kernel root, e.g.:\n"
            . "  export HKM_KERNEL_HOME=/path/to/php-service-platform\n"
            . (is_string($fallback) && $fallback !== '' ? "(tried wire-time path: {$fallback})\n" : '');

        if (\PHP_SAPI === 'cli' || \PHP_SAPI === 'phpdbg') {
            fwrite(\defined('STDERR') ? STDERR : fopen('php://stderr', 'w'), $msg);
        } else {
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=utf-8');
            }
            echo $msg;
        }

        exit(1);
    }
}
