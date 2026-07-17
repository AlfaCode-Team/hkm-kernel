<?php

declare(strict_types=1);

namespace Plugins\Edge\Infrastructure;

/**
 * Manages a single marked block in /etc/hosts for the platform's LOCAL domains
 * (.local / .test / …). Only the block between the markers is ever touched — the
 * rest of the user's hosts file is preserved verbatim. Idempotent: re-running
 * replaces the block, never appends duplicates.
 */
final class HostsFileWriter
{
    private const BEGIN = '# >>> HKM Edge (local domains) >>>';
    private const END   = '# <<< HKM Edge (local domains) <<<';

    /**
     * @param list<string> $domains local hostnames to point at $ip
     * @return array{ok: bool, changed?: bool, dry_run?: bool, path: string, count: int, block?: string, message?: string}
     */
    public function sync(array $domains, string $ip, string $path, bool $remove = false, bool $dryRun = false): array
    {
        if (!is_file($path)) {
            return ['ok' => false, 'path' => $path, 'count' => 0, 'message' => "hosts file not found: {$path}"];
        }

        $current  = (string) file_get_contents($path);
        $stripped = $this->stripBlock($current);

        $block = '';
        if (!$remove && $domains !== []) {
            $lines = [self::BEGIN];
            foreach ($domains as $d) {
                $lines[] = sprintf('%s    %s', $ip, $d);
            }
            $lines[] = self::END;
            $block   = implode("\n", $lines);
        }

        $new = $block === ''
            ? rtrim($stripped, "\n") . "\n"
            : rtrim($stripped, "\n") . "\n\n" . $block . "\n";

        $changed = $new !== $current;

        if ($dryRun) {
            return ['ok' => true, 'dry_run' => true, 'changed' => $changed, 'path' => $path, 'count' => count($domains), 'block' => $block];
        }
        if (!$changed) {
            return ['ok' => true, 'changed' => false, 'path' => $path, 'count' => count($domains), 'message' => 'already up to date'];
        }

        $tmp = $path . '.hkm.tmp';
        if (@file_put_contents($tmp, $new) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            return ['ok' => false, 'path' => $path, 'count' => count($domains), 'message' => "cannot write {$path} (run with the privileges to edit it, e.g. sudo)"];
        }

        return ['ok' => true, 'changed' => true, 'path' => $path, 'count' => count($domains)];
    }

    /** Remove any existing HKM-managed block (and the blank lines around it). */
    private function stripBlock(string $contents): string
    {
        $pattern = '/\n*' . preg_quote(self::BEGIN, '/') . '.*?' . preg_quote(self::END, '/') . '\n*/s';

        return preg_replace($pattern, "\n", $contents) ?? $contents;
    }
}
