<?php

declare(strict_types=1);

namespace Plugins\Edge\Infrastructure;

/**
 * Manages a single marked block in /etc/hosts for the platform's LOCAL domains
 * (.local / .test / …). Only the block between the markers is ever touched — the
 * rest of the user's hosts file is preserved verbatim.
 *
 * MERGE semantics (idempotent, additive): a sync starts from the domains ALREADY
 * in the managed block and adds the ones passed in. So syncing a single project
 * never drops another project's (or an earlier run's) entries — a new host is
 * appended only when it is not already present. A host the user mapped by hand
 * OUTSIDE the block stays authoritative and is skipped. `--remove` clears the
 * whole managed block.
 */
final class HostsFileWriter
{
    private const BEGIN = '# >>> HKM Edge (local domains) >>>';
    private const END   = '# <<< HKM Edge (local domains) <<<';

    /**
     * @param list<string> $domains local hostnames to point at $ip
     * @return array{ok: bool, changed?: bool, dry_run?: bool, path: string, count: int, skipped?: list<string>, block?: string, message?: string}
     */
    public function sync(array $domains, string $ip, string $path, bool $remove = false, bool $dryRun = false): array
    {
        if (!is_file($path)) {
            return ['ok' => false, 'path' => $path, 'count' => 0, 'message' => "hosts file not found: {$path}"];
        }

        $current  = (string) file_get_contents($path);
        $stripped = $this->stripBlock($current);

        if ($remove) {
            // --remove clears the ENTIRE managed block; everything else is kept.
            $removed = \count($this->parseBlock($current));

            return $this->write($current, rtrim($stripped, "\n") . "\n", $path, $removed, [], $dryRun, '');
        }

        // MERGE: seed from the domains already in our block, then add new ones.
        // Insertion order is preserved (new hosts append) so a no-op run produces
        // a byte-identical block and reports "already current".
        $blockEntries = $this->parseBlock($current);        // [host => ip], in file order
        $existing     = $this->existingHosts($stripped);    // hosts mapped OUTSIDE our block

        $added   = [];
        $skipped = [];
        foreach ($domains as $d) {
            $key = strtolower(trim($d));
            if ($key === '') {
                continue;
            }
            if (isset($existing[$key])) {
                $skipped[] = $d;                            // hand-added elsewhere — leave authoritative
                continue;
            }
            if (!isset($blockEntries[$key])) {
                $added[] = $d;                             // genuinely new to the block
            }
            $blockEntries[$key] = $ip;                      // add, or re-point to the current ip
        }

        $block = $this->renderBlock($blockEntries);
        $new   = $block === ''
            ? rtrim($stripped, "\n") . "\n"
            : rtrim($stripped, "\n") . "\n\n" . $block . "\n";

        return $this->write($current, $new, $path, \count($added), $skipped, $dryRun, $block);
    }

    /**
     * Compute the result envelope (and perform the write unless $dryRun).
     *
     * @param list<string> $skipped
     * @return array{ok: bool, changed?: bool, dry_run?: bool, path: string, count: int, skipped?: list<string>, block?: string, message?: string}
     */
    private function write(string $current, string $new, string $path, int $count, array $skipped, bool $dryRun, string $block): array
    {
        $changed = $new !== $current;

        if ($dryRun) {
            return ['ok' => true, 'dry_run' => true, 'changed' => $changed, 'path' => $path, 'count' => $count, 'skipped' => $skipped, 'block' => $block];
        }
        if (!$changed) {
            return ['ok' => true, 'changed' => false, 'path' => $path, 'count' => $count, 'skipped' => $skipped, 'message' => 'already up to date'];
        }

        $tmp = $path . '.hkm.tmp';
        if (@file_put_contents($tmp, $new) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            return ['ok' => false, 'path' => $path, 'count' => $count, 'skipped' => $skipped, 'message' => "cannot write {$path} (run with the privileges to edit it, e.g. sudo)"];
        }

        return ['ok' => true, 'changed' => true, 'path' => $path, 'count' => $count, 'skipped' => $skipped];
    }

    /** Remove any existing HKM-managed block (and the blank lines around it). */
    private function stripBlock(string $contents): string
    {
        $pattern = '/\n*' . preg_quote(self::BEGIN, '/') . '.*?' . preg_quote(self::END, '/') . '\n*/s';

        return preg_replace($pattern, "\n", $contents) ?? $contents;
    }

    /**
     * The (host => ip) entries currently inside OUR managed block, in file order.
     * Empty when the block is absent — the merge then starts fresh.
     *
     * @return array<string, string>
     */
    private function parseBlock(string $contents): array
    {
        if (preg_match('/' . preg_quote(self::BEGIN, '/') . '(.*?)' . preg_quote(self::END, '/') . '/s', $contents, $m) !== 1) {
            return [];
        }

        $entries = [];
        foreach (explode("\n", $m[1]) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = preg_split('/\s+/', $line) ?: [];
            if (\count($parts) < 2) {
                continue;
            }
            $lineIp = $parts[0];
            foreach (\array_slice($parts, 1) as $name) {
                $name = strtolower(trim($name));
                if ($name !== '') {
                    $entries[$name] = $lineIp;
                }
            }
        }

        return $entries;
    }

    /**
     * Render the managed block from (host => ip) entries, or '' when empty.
     *
     * @param array<string, string> $entries
     */
    private function renderBlock(array $entries): string
    {
        if ($entries === []) {
            return '';
        }

        $lines = [self::BEGIN];
        foreach ($entries as $host => $ip) {
            $lines[] = sprintf('%s    %s', $ip, $host);
        }
        $lines[] = self::END;

        return implode("\n", $lines);
    }

    /**
     * Every hostname already mapped in the file (outside our managed block), as a
     * lowercase lookup set — so an entry the user added by hand is never
     * duplicated or overridden.
     *
     * @return array<string, true>
     */
    private function existingHosts(string $contents): array
    {
        $hosts = [];
        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            // Drop any trailing comment, then split "IP host [host…]".
            if (($hash = strpos($line, '#')) !== false) {
                $line = trim(substr($line, 0, $hash));
            }
            $parts = preg_split('/\s+/', $line) ?: [];
            if (\count($parts) < 2) {
                continue;
            }
            foreach (\array_slice($parts, 1) as $name) {
                $name = strtolower(trim($name));
                if ($name !== '') {
                    $hosts[$name] = true;
                }
            }
        }

        return $hosts;
    }
}
