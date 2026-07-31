<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Migrate;

use AlfacodeTeam\PhpIoCli\Depends\Colors;

/**
 * migrate:list — rich migration status view.
 *
 * Shows all migrations with: status, batch number, time since application,
 * and an optional file-age column derived from the filename timestamp.
 *
 * Difference from migrate:status:
 *   - migrate:status  → compact, operational (what to run next)
 *   - migrate:list    → informational audit view (full history at a glance)
 *
 * Usage:
 *   migrate:list
 *   migrate:list --pending    show only unapplied migrations
 *   migrate:list --applied    show only applied migrations
 *   migrate:list --batch=3    show only batch 3
 */
final class MigrateListCommand extends LetMigrateCommand
{
    protected function configure(): void
    {
        $this->name        = 'migrate:list';
        $this->description = 'List all migrations with status, batch, and file age';

        $this->addOption('pending', 'p', 'Show only unapplied migrations');
        $this->addOption('applied', 'a', 'Show only applied migrations');
        $this->addOption('batch',   'b', 'Filter by batch number', acceptsValue: true);
    }

    protected function handle(): int
    {
        $this->section('Database Migrations — List');

        $status     = $this->service()->status();
        $filterBatch = $this->option('batch') !== null ? (int) $this->option('batch') : null;

        if (empty($status)) {
            $this->muted('No migration files discovered in configured paths.');
            return self::SUCCESS;
        }

        // Apply filters
        $filtered = $status;

        if ($this->hasOption('pending')) {
            $filtered = array_filter($filtered, static fn($s) => $s['status'] === 'pending');
        } elseif ($this->hasOption('applied')) {
            $filtered = array_filter($filtered, static fn($s) => $s['status'] === 'applied');
        }

        if ($filterBatch !== null) {
            $filtered = array_filter($filtered, static fn($s) => ($s['batch'] ?? null) === $filterBatch);
        }

        if (empty($filtered)) {
            $this->muted('No migrations match the applied filters.');
            return self::SUCCESS;
        }

        // Build table rows
        $rows = [];
        foreach ($filtered as $filename => $info) {
            $isApplied = $info['status'] === 'applied';

            $statusLabel = $isApplied
                ? Colors::wrap('✔ Applied', Colors::GREEN)
                : Colors::wrap('⟳ Pending', Colors::YELLOW);

            $batch   = $info['batch'] !== null ? (string) $info['batch'] : '—';
            $fileAge = $this->extractFileAge($filename);

            $rows[] = [
                $statusLabel,
                $filename,
                $batch,
                $fileAge,
            ];
        }

        $this->table()
            ->headers(['Status', 'Migration', 'Batch', 'File Date'])
            ->rows($rows)
            ->style('box')
            ->render();

        // Summary line
        $applied = count(array_filter($status, static fn($s) => $s['status'] === 'applied'));
        $pending = count($status) - $applied;
        $shown   = count($rows);
        $total   = count($status);

        $this->newLine();
        $this->muted(
            "Showing {$shown} of {$total} migrations — "
            . Colors::wrap("{$applied} applied", Colors::GREEN)
            . ' · '
            . Colors::wrap("{$pending} pending", Colors::YELLOW)
        );

        return self::SUCCESS;
    }

    /**
     * Extract the human-readable date from the migration filename.
     *
     * Filename format: YYYY_MM_DD_NNNNNN_name.php
     * Returns:         YYYY-MM-DD
     */
    private function extractFileAge(string $filename): string
    {
        if (preg_match('/^(\d{4})_(\d{2})_(\d{2})_\d+_/', $filename, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }

        return '—';
    }
}
