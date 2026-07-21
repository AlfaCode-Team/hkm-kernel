<?php

declare(strict_types=1);

namespace Plugins\Edge\Infrastructure;

/**
 * Merges the platform's public domains INTO the host's existing nginx SNI stream
 * splitter, editing that file in place. When your `nginx.conf` (or an included
 * file) already has a `map $ssl_preread_server_name $backend_name { … }` routing
 * SNI to nginx (127.0.0.1:444) vs Apache, Edge keeps YOUR block and only inserts
 * the platform domains that are missing — mapped to nginx_backend — inside a
 * marked, idempotent sub-block placed just before the `default` line:
 *
 *   map $ssl_preread_server_name $backend_name {
 *       your.manual.domain             nginx_backend;
 *       # >>> HKM Edge (managed domains) >>>
 *       app.migratetravel.com          nginx_backend;
 *       # <<< HKM Edge (managed domains) <<<
 *       default                        apache_ssl;
 *   }
 *
 * The managed sub-block is fully rewritten each run, so re-applies never
 * duplicate; a domain already present anywhere in the map (yours or ours) is
 * left untouched. Nothing outside the map is modified.
 */
final class StreamConfigWriter
{
    private const BEGIN = '# >>> HKM Edge (managed domains) >>>';
    private const END   = '# <<< HKM Edge (managed domains) <<<';

    /**
     * Insert the missing $domains into the ssl_preread map inside $file.
     *
     * @param list<string> $domains platform public domains → nginx_backend
     * @return array{
     *   ok: bool, file: string, added: list<string>, present: list<string>,
     *   changed: bool, dry_run?: bool, contents?: string, message?: string
     * }
     */
    public function merge(string $file, array $domains, string $backend = 'nginx_backend', bool $dryRun = false): array
    {
        $result = ['ok' => false, 'file' => $file, 'added' => [], 'present' => [], 'changed' => false];

        $original = @file_get_contents($file);
        if ($original === false) {
            return [...$result, 'message' => "cannot read {$file}"];
        }

        // Isolate the map block. Value var is captured so we tolerate any name.
        if (!preg_match('/map\s+\$ssl_preread_server_name\s+\$\w+\s*\{/', $original, $m, PREG_OFFSET_CAPTURE)) {
            return [...$result, 'message' => 'no `map $ssl_preread_server_name` block found in ' . $file];
        }
        $openPos  = (int) $m[0][1];
        $bracePos = strpos($original, '{', $openPos);
        if ($bracePos === false) {
            return [...$result, 'message' => 'malformed map block in ' . $file];
        }
        $closePos = $this->matchingBrace($original, $bracePos);
        if ($closePos === null) {
            return [...$result, 'message' => 'unbalanced braces in the map block in ' . $file];
        }

        $body = substr($original, $bracePos + 1, $closePos - $bracePos - 1);

        // Drop any previous HKM-managed sub-block, then read the domains that
        // remain (yours + any non-managed entries) so we never re-add them.
        $bodyNoManaged = $this->stripManaged($body);
        $existing      = $this->existingHosts($bodyNoManaged);

        [$added, $present] = [[], []];
        foreach ($this->normaliseDomains($domains) as $d) {
            if (isset($existing[$d])) {
                $present[] = $d;
            } else {
                $added[] = $d;
                $existing[$d] = true; // guard against dupes within $domains
            }
        }

        $result['added']   = $added;
        $result['present'] = $present;

        $indent   = $this->detectIndent($bodyNoManaged);
        $newBody  = $added === []
            ? $bodyNoManaged                                   // nothing to add — just the cleaned body
            : $this->insertManagedBlock($bodyNoManaged, $added, $backend, $indent);

        $updated  = substr($original, 0, $bracePos + 1) . $newBody . substr($original, $closePos);
        $changed  = $updated !== $original;
        $result['changed'] = $changed;

        if ($dryRun) {
            return [...$result, 'ok' => true, 'dry_run' => true, 'contents' => $updated];
        }
        if (!$changed) {
            return [...$result, 'ok' => true]; // already current
        }

        // Atomic write (temp + rename) so a live `include` never sees a partial file.
        $tmp = $file . '.hkm.tmp';
        if (@file_put_contents($tmp, $updated) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            return [...$result, 'message' => "failed to write {$file} (need sudo?)"];
        }

        return [...$result, 'ok' => true];
    }

    /** Find the `}` matching the `{` at $open, or null if unbalanced. */
    private function matchingBrace(string $s, int $open): ?int
    {
        $depth = 0;
        $len   = strlen($s);
        for ($i = $open; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                if (--$depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /** Remove a previous `# >>> … >>>` … `# <<< … <<<` managed sub-block. */
    private function stripManaged(string $body): string
    {
        $pattern = '/[ \t]*' . preg_quote(self::BEGIN, '/') . '.*?' . preg_quote(self::END, '/') . "[ \t]*\r?\n?/s";

        return (string) preg_replace($pattern, '', $body);
    }

    /**
     * The hostnames already keyed in the map body (excluding `default`), as a
     * lookup set. A map line is `<host>   <backend>;`.
     *
     * @return array<string, true>
     */
    private function existingHosts(string $body): array
    {
        $hosts = [];
        foreach (explode("\n", $body) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (preg_match('/^(\S+)\s+\S+\s*;/', $line, $m) && strtolower($m[1]) !== 'default') {
                $hosts[strtolower($m[1])] = true;
            }
        }

        return $hosts;
    }

    /** Lowercase, de-duplicate, drop empties/wildcards from the incoming domains. @param list<string> $domains @return list<string> */
    private function normaliseDomains(array $domains): array
    {
        $seen = [];
        foreach ($domains as $d) {
            $d = strtolower(trim($d));
            if ($d !== '' && !str_contains($d, '*') && !isset($seen[$d])) {
                $seen[$d] = true;
            }
        }

        return array_keys($seen);
    }

    /** The leading whitespace used by existing map entries (fallback: 8 spaces). */
    private function detectIndent(string $body): string
    {
        foreach (explode("\n", $body) as $line) {
            if (trim($line) !== '' && preg_match('/^(\s+)\S/', $line, $m)) {
                return $m[1];
            }
        }

        return str_repeat(' ', 8);
    }

    /**
     * Insert the managed sub-block (the missing domains) just before the `default`
     * line, or before the closing brace when there is no `default`.
     *
     * @param list<string> $added
     */
    private function insertManagedBlock(string $body, array $added, string $backend, string $indent): string
    {
        // Column-align the backend like the user's block: pad to the longest host.
        $width = max(array_map('strlen', $added));
        $lines = [$indent . self::BEGIN];
        foreach ($added as $host) {
            $pad     = str_repeat(' ', max(1, $width - strlen($host) + 4));
            $lines[] = $indent . $host . $pad . $backend . ';';
        }
        $lines[] = $indent . self::END;
        $block   = implode("\n", $lines) . "\n";

        // Prefer to sit right above the `default …;` line.
        $out = preg_replace(
            '/^([ \t]*default\s+\S+\s*;.*)$/m',
            $block . '$1',
            $body,
            1,
            $count,
        );
        if ($count === 1 && $out !== null) {
            return $out;
        }

        // No default line — append before the (already-excluded) closing brace,
        // i.e. at the end of the body, keeping a trailing newline.
        return rtrim($body, "\n") . "\n" . $block;
    }
}
