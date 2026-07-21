<?php

declare(strict_types=1);

namespace Plugins\Edge\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\Edge\API\Contracts\EdgeServiceContract;

/**
 * edge:apply — detect the stack, render the matching config from the platform's
 * domains, write it, then validate + reload the web server.
 *
 *   hkm edge:apply                 # write + `nginx -t`/`apachectl configtest` + reload
 *   hkm edge:apply --dry-run       # print the config that WOULD be written, touch nothing
 *   hkm edge:apply --no-reload     # write the file only (skip test + reload)
 *
 * Environment → cache profile. These flags are specific to edge:apply (they are
 * NOT launcher-global) and the profile is ALWAYS derived from APP_ENV, never from
 * the kernel mode — so `hkm --dev` choosing the dev KERNEL stays a separate concern:
 *   hkm edge:apply --local          # APP_ENV=local        → DEVELOPMENT profile
 *   hkm edge:apply --development    # APP_ENV=development  → DEVELOPMENT profile
 *   hkm edge:apply -d               # same as --development
 *   hkm edge:apply --production     # APP_ENV=production   → PRODUCTION profile
 * With no flag the configured APP_ENV/EDGE_APP_ENV is used. Anything unrecognised
 * falls back to the DEVELOPMENT profile, never production.
 *
 * `--dev` is accepted as an alias for --local, but the `hkm` launcher strips
 * --dev (it selects the dev kernel), so prefer --local when running via `hkm`.
 *
 * TLS mode (default: config `tls.mode`, i.e. `ssl`):
 *   hkm edge:apply --tls=ssl       # HTTPS only (:443)
 *   hkm edge:apply --tls=none      # plain HTTP only (:80, no certificate)
 *   hkm edge:apply --no-ssl        # alias for --tls=none
 *   hkm edge:apply --tls=both      # plain :80 that 301-redirects to HTTPS (:443)
 *   hkm edge:apply --ssl-cert=/path/fullchain.pem --ssl-key=/path/privkey.pem
 *
 * Strategy override (default: auto-detect the running servers):
 *   hkm edge:apply --nginx-only    # nginx serves everything, NO Apache fallback
 *   hkm edge:apply --apache-only   # Apache serves everything, no fallback
 * When both nginx and Apache run and nginx already has an SNI `stream {}` splitter,
 * Edge REUSES it instead of writing a second one (disable with EDGE_REUSE_STREAM=0).
 */
final class EdgeApplyCommand extends AbstractCommand
{
    public function __construct(private readonly EdgeServiceContract $edge)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'edge:apply';
        $this->description = 'Generate the nginx/Apache edge config from platform domains, then reload the server';

        $this->addOption('dry-run', '', 'Print the config that would be written; change nothing');
        $this->addOption('no-reload', '', 'Write the config file but do not validate or reload');
        $this->addOption('no-hosts', '', 'Skip writing local (.local/.test) domains to /etc/hosts');
        $this->addOption('all', '', 'Include every registered project (default: only the current one)');
        $this->addOption('local', '', 'APP_ENV=local — developer machine (DEVELOPMENT cache profile)');
        $this->addOption('dev', '', 'Alias for --local (note: `hkm` itself consumes --dev for kernel selection)');
        $this->addOption('development', 'd', 'APP_ENV=development — shared dev/staging server (DEVELOPMENT cache profile)');
        $this->addOption('production', '', 'APP_ENV=production — live server (PRODUCTION cache profile)');
        $this->addOption('tls', '', 'TLS mode: ssl (HTTPS only), none (HTTP only), both (HTTP→HTTPS redirect)', true);
        $this->addOption('no-ssl', '', 'Plain HTTP only, listen on :80 — alias for --tls=none');
        $this->addOption('ssl-cert', '', 'Path to the TLS certificate (overrides config ssl.cert)', true);
        $this->addOption('ssl-key', '', 'Path to the TLS private key (overrides config ssl.key)', true);
        $this->addOption('nginx-only', '', 'Serve everything through nginx with NO Apache fallback (overrides auto-detection)');
        $this->addOption('apache-only', '', 'Serve everything through Apache with no fallback (overrides auto-detection)');
    }

    protected function handle(): int
    {
        $dryRun = $this->hasOption('dry-run');
        $reload = !$this->hasOption('no-reload');
        $hosts  = $this->hasOption('no-hosts') ? false : null; // null = use config default
        $all    = $this->hasOption('all');

        // TLS overrides — null means "use the config default". --no-ssl is a
        // convenience alias for --tls=none.
        $tlsMode = $this->hasOption('no-ssl') ? 'none' : $this->stringOption('tls');
        $sslCert = $this->stringOption('ssl-cert');
        $sslKey  = $this->stringOption('ssl-key');

        $appEnv = $this->resolveAppEnv();
        if ($appEnv === false) {
            $this->error('Pick ONE environment: --local (or --dev), --development/-d, or --production.');

            return self::INVALID;
        }

        $force = $this->resolveForce();
        if ($force === false) {
            $this->error('Pick at most ONE of --nginx-only or --apache-only.');

            return self::INVALID;
        }

        $result = $this->edge->apply(
            reload: $reload,
            dryRun: $dryRun,
            manageHosts: $hosts,
            all: $all,
            tlsMode: $tlsMode,
            sslCert: $sslCert,
            sslKey: $sslKey,
            appEnv: $appEnv,
            force: $force,
        );

        $this->reportHosts($result['hosts'] ?? null);

        if (($result['ok'] ?? false) !== true) {
            $this->error('Edge apply failed [' . ($result['strategy'] ?? '?') . ']: ' . ($result['message'] ?? 'unknown error'));
            foreach ((array) ($result['steps'] ?? []) as $step) {
                $this->muted('  - ' . $step);
            }

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->info('strategy: ' . $result['strategy'] . '  →  ' . $result['path'] . '  (' . ($result['sites'] ?? 0) . ' site(s))');
            $this->newLine();
            $this->muted($result['contents']);
            $this->reportStream($result['stream'] ?? null, dryRun: true);

            return self::SUCCESS;
        }

        $this->success('Edge applied [' . $result['strategy'] . ']');
        foreach ((array) ($result['steps'] ?? []) as $step) {
            $this->info('  - ' . $step);
        }
        $this->reportStream($result['stream'] ?? null, dryRun: false);

        return self::SUCCESS;
    }

    /**
     * The APP_ENV selected by the environment flags: 'local' | 'development' |
     * 'production', null when none was given (fall back to the configured
     * APP_ENV), or false when more than one was passed.
     *
     * These flags are deliberately EDGE-LOCAL (not launcher-global) — they only
     * steer this command's cache profile.
     *
     * NOTE: the `hkm` launcher consumes `--dev` for KERNEL selection and strips
     * it before the command runs, so use `--local` to select APP_ENV=local via
     * the launcher. `--dev` is kept as an alias for direct invocation.
     */
    private function resolveAppEnv(): string|false|null
    {
        $picked = array_keys(array_filter([
            'local'       => $this->hasOption('local') || $this->hasOption('dev'),
            'development' => $this->hasOption('development'),
            'production'  => $this->hasOption('production'),
        ]));

        return match (\count($picked)) {
            0       => null,
            1       => $picked[0],
            default => false,
        };
    }

    /**
     * The forced strategy from --nginx-only / --apache-only: 'nginx-only' |
     * 'apache-only', null when neither was given (auto-detect), or false when
     * both were passed (mutually exclusive).
     */
    private function resolveForce(): string|false|null
    {
        $picked = array_keys(array_filter([
            'nginx-only'  => $this->hasOption('nginx-only'),
            'apache-only' => $this->hasOption('apache-only'),
        ]));

        return match (\count($picked)) {
            0       => null,
            1       => $picked[0],
            default => false,
        };
    }

    /** A value-accepting option as a non-empty string, or null (unset / bare flag). */
    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Report the in-place merge into the host's existing SNI stream splitter.
     *
     * @param array<string, mixed>|null $stream
     */
    private function reportStream(?array $stream, bool $dryRun): void
    {
        if ($stream === null) {
            return;
        }
        $file    = (string) ($stream['file'] ?? '?');
        $added   = (array) ($stream['added'] ?? []);
        $present = (array) ($stream['present'] ?? []);

        if (($stream['ok'] ?? false) !== true) {
            $this->warning('stream: could NOT update ' . $file . ' — ' . ($stream['message'] ?? 'error'));

            return;
        }
        if ($present !== []) {
            $this->muted('stream: ' . \count($present) . ' domain(s) already in the map (left untouched)');
        }
        if ($added === []) {
            $this->info('stream: ' . $file . ' already current — no domains to add');

            return;
        }
        $verb = $dryRun ? 'would merge' : 'merged';
        $this->info("stream: {$verb} " . \count($added) . " domain(s) into {$file} → nginx_backend");
        foreach ($added as $d) {
            $this->muted('  + ' . $d);
        }
    }

    /** @param array<string, mixed>|null $hosts */
    private function reportHosts(?array $hosts): void
    {
        if ($hosts === null) {
            return;
        }
        $count = (int) ($hosts['count'] ?? 0);
        $path  = (string) ($hosts['path'] ?? '/etc/hosts');

        if (($hosts['ok'] ?? false) !== true) {
            $this->warning("hosts: {$count} local domain(s) NOT written — " . ($hosts['message'] ?? 'error'));
            return;
        }
        if (($hosts['dry_run'] ?? false) === true) {
            $this->info("hosts: would sync {$count} local domain(s) to {$path}");
            return;
        }
        $verb = ($hosts['changed'] ?? false) ? 'synced' : 'already current';
        $this->info("hosts: {$verb} {$count} local domain(s) in {$path}");
    }
}
