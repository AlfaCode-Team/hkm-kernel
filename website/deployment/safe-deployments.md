---
outline: [2, 3]
---

# Safe Deployments

This page covers the practices and checklists for safely deploying database schema changes, queued jobs with signing, and rolling back when things go wrong.

## Pre-Deployment Checklist

### Configuration & Secrets

- [ ] All required environment variables are set (run `hkm install --check`)
- [ ] Database credentials are in a secrets manager, not the codebase
- [ ] `APP_KEY` is set and consistent across all servers (used by CSRF layer and signatures)
- [ ] TLS certificates are valid and not expiring soon

### Database

- [ ] Recent, tested backup exists and is restorable
- [ ] Backup is stored in a secure, offsite location
- [ ] Backup retention policy is defined (e.g., keep 30 days)
- [ ] All migrations have been tested on a staging database identical to production
- [ ] Destructive migrations (drops, renames) are in a separate deployment window
- [ ] Migration SQL has been reviewed by at least one team member

### Code & Infrastructure

- [ ] All tests pass locally and in CI (`phpunit`, type checking)
- [ ] Branch has been reviewed via pull request
- [ ] Application has been tested on staging with production-like data volume
- [ ] Rollback plan is documented and tested
- [ ] Deployment runbook is up to date
- [ ] On-call team is notified of the upcoming deployment
- [ ] Monitoring dashboards are set up (see [Production](/deployment/production#monitoring))

### Performance

- [ ] Performance impact of schema changes has been estimated
- [ ] Any new indexes are in place before heavy queries run
- [ ] Queries that may cause lock contention have been identified
- [ ] Deployment window is scheduled during low-traffic periods if needed

## Schema Migrations

### Testing Migrations

Always preview SQL before applying:

```bash
# Staging database
APP_ENV=production DATABASE_HOST=staging.internal \
    hkm cli migrate:run --pretend

# Review the output carefully
```

Then apply on staging and verify the application works:

```bash
# Apply
hkm cli migrate:run

# Test the application
curl -s https://staging.internal/api/health | jq

# Monitor for errors
tail -f var/logs/app.log
```

### Expand-Contract Pattern for Destructive Changes

When removing a column or table that the application code still references, use the expand-contract pattern:

1. **Expand** — Add the new schema (new column, new table) while the old code still uses the old structure
2. **Deploy** — Ship code that reads from the new location and writes to both old and new
3. **Contract** — In a future deployment, remove the old column/table

Example: renaming `users.name` to `users.full_name`:

**Migration 1 (Expand):**

```php
// In migration
$table->addColumn('full_name', 'VARCHAR(255)');
```

**Code Update (Deploy):**

```php
// Read the new column, write to both
public function update(User $user): void
{
    $this->db->execute(
        'UPDATE users SET full_name = ?, name = ? WHERE id = ?',
        [$user->fullName, $user->fullName, $user->id]
    );
}
```

**Migration 2 (Contract):**

```php
// In a future deployment
$table->dropColumn('name');
```

### Handling Large Tables

For tables with millions of rows, avoid long-running ALTER TABLE statements:

1. **Create** a new table with the desired schema
2. **Copy** rows in batches with a background job
3. **Redirect** the application to use the new table
4. **Drop** the old table after verification

```php
// Migration: create the new table (raw SQL through the schema builder; MySQL syntax shown)
public function up(SchemaBuilderInterface $schema): void
{
    $schema->raw('CREATE TABLE users_new LIKE users');
}

// Background job: copy in keyset-paginated batches through DatabasePort
$lastId = 0;
do {
    $rows = $this->db->query(
        'SELECT * FROM users WHERE id > :last ORDER BY id LIMIT 1000',
        ['last' => $lastId],
    );
    foreach ($rows as $row) {
        $this->db->upsert('users_new', $row, conflictColumns: ['id']);  // idempotent on retry
        $lastId = (int) $row['id'];
    }
    usleep(200_000);  // ease lock contention
} while ($rows !== []);

// Final migration: swap
public function up(SchemaBuilderInterface $schema): void
{
    $schema->raw('RENAME TABLE users TO users_old, users_new TO users');
}
```

### Staging Destructive Migrations Separately

A single pending DROP statement blocks the entire batch. Isolate destructive changes in their own migration file:

```bash
# Create a new migration
hkm cli make:migration drop_unused_column

# Mark it as destructive (in your deploy docs)
# Run separately from expansions
hkm cli migrate:run --file=drop_unused_column
```

## Job Queue Signing & Rollout

The job queue is an input channel. Anyone who can write to it is calling into your application. Signing jobs prevents tampering.

### Enabling Signatures (Producer-First)

Job signatures are **off by default** because enabling them is a **two-sided deployment**:

1. **Producer must sign** before putting jobs in the queue
2. **Worker must verify** signatures when dequeuing

If the producer has not updated yet, the worker rejects every unsigned job as invalid.

**Rollout sequence:**

1. **Deploy producer first** (with signature generation)
   - Producer stamps jobs with HMAC: `jobId | jobClass | queue | data`
   - Workers still accept unsigned jobs (verification is not yet enabled)

2. **Verify producer is working**
   - Spot-check a few jobs in the queue — they should have a signature

3. **Deploy worker** (with signature verification)
   - Worker validates every dequeued job's signature
   - Unsigned jobs are rejected and dead-lettered

Set `JOB_SIGNING_SECRET` in `.env` after the producer deployment:

```bash
export JOB_SIGNING_SECRET=$(openssl rand -hex 32)
```

If verification is enabled before the producer is ready, jobs back up in the queue as invalid and must be flushed.

## Cache & Manifest Invalidation

### PHP-FPM (BOOT_CACHE)

After deployment, clear the manifest cache so the next request recompiles:

```bash
# After git push, before restarting services
rm -rf var/cache/manifests/
```

**Why:** BOOT_CACHE skips recompilation when `module.json` and config files are unchanged. If you deploy new code without clearing the cache, old manifests may remain in memory.

### Application Cache

If you're caching data (queries, API responses), decide whether to clear it:

```php
// In your deploy script
if ($this->cache instanceof CachePort) {
    $this->cache->flush(); // aggressive: clears everything
    // or
    $this->cache->deletePattern('query:*'); // targeted
}
```

Most applications can survive stale cache for a few minutes. Use targeted invalidation to minimize disruption.

## Rollback Strategy

### When to Rollback

Rollback immediately if you see:

- **Error rate spikes** above 1% (anything over baseline + 0.5%)
- **Response latency jumps** by more than 100ms at p95
- **Database connections hang** (connection pool exhaustion)
- **Authentication failures** for all users (auth bug, signing issue)
- **Cascading failures** in dependent services

Give real bugs 5 minutes to surface. Transient glitches resolve themselves; bugs don't.

### Rollback Execution

#### Code Rollback

1. **Revert the commit:**
   ```bash
   git revert HEAD
   git push origin main
   ```

2. **Clear the cache:**
   ```bash
   rm -rf var/cache/manifests/
   ```

3. **Restart the application:**
   ```bash
   # PHP-FPM
   systemctl reload php8.4-fpm

   # OpenSwoole
   systemctl restart app
   ```

4. **Verify:**
   ```bash
   curl https://example.com/health
   # Should return 200 OK
   ```

#### Database Rollback

**If you just deployed migrations:**

1. **Restore from the pre-deployment backup:**
   ```bash
   mysql example_db < backup-2025-01-15-02-00.sql
   ```

2. **Verify the restore worked:**
   ```bash
   mysql example_db -e "SELECT COUNT(*) FROM users;"
   ```

3. **Restart the application** (it may have cached schema info)

4. **Do NOT re-run migrations** — the old code cannot understand the new schema

**If you rolled back code but need to undo a migration:**

Use the migration tool's rollback command:

```bash
hkm cli migrate:rollback

# Or rollback specific steps
hkm cli migrate:rollback --steps=2
```

**Never:**

- Manually edit migration files after they've been applied
- Delete migration files from disk (they're immutable records)
- Run migrations in a partial or half-state (migrations should be atomic)

### Post-Rollback

After rolling back:

1. **Check monitoring** — error rate should return to baseline
2. **Gather logs** — save the error logs for review
3. **Notify stakeholders** — let the team know the rollback is complete
4. **Schedule a postmortem** — understand what happened and how to prevent it
5. **Fix the bug** on a branch and re-test before re-deploying

## Deployment Windows

### High-Traffic Applications

Schedule migrations during low-traffic windows:

```bash
# E.g., 2 AM on Tuesday
0 2 * * 2 deploy.sh
```

For truly global applications with no low-traffic window, deploy during business hours and use the expand-contract pattern to minimize blocking time.

### Schema Changes on Large Tables

Test the migration time on a copy of production data:

```bash
# Clone production database
mysqldump production_db > prod_clone.sql
mysql staging_db < prod_clone.sql

# Run the migration and measure time
time hkm cli migrate:run
```

If it takes > 30 seconds, consider batching or a background job approach instead.

## Monitoring During & After Deployment

### Real-Time Monitoring

Keep a terminal open watching the error log:

```bash
tail -f var/logs/errors.log
```

And monitor request latency from your observability tool (DataDog, New Relic, etc.).

### Key Signals

- **Request latency** (p50, p95, p99) — should not jump
- **Error rate** (4xx, 5xx) — should stay baseline
- **Database connections** — should not max out
- **Memory usage** — especially under OpenSwoole (workers should not grow monotonically)
- **Cache hit rate** — if you cleared cache, it will be low for a few minutes

### Alerting

Configure alerts for:

```
error_rate > 1% for 5 minutes → page on-call engineer
latency_p95 > baseline + 100ms for 10 minutes → notify Slack
db_connection_pool_used > 90% → notify immediately
```

## Deployment Runbook Template

Keep a runbook for your application:

```markdown
# Deployment Runbook

## Pre-Deployment (run on dev machine)

- [ ] `git pull origin main`
- [ ] `phpunit --testsuite=Feature` (all tests pass)
- [ ] `phpstan` (type checking clean)
- [ ] Database backup created: `mysqldump ... > backup.sql`

## Deploy to Production

1. SSH to production:
   ```bash
   ssh deploy@prod.internal
   cd /var/www/app
   ```

2. Update code:
   ```bash
   git fetch origin
   git checkout main
   git pull
   ```

3. Install dependencies:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```

4. Run migrations (if any):
   ```bash
   hkm cli migrate:run --pretend  # preview first
   hkm cli migrate:run
   ```

5. Clear cache:
   ```bash
   rm -rf var/cache/manifests/
   ```

6. Restart:
   ```bash
   systemctl reload php8.4-fpm
   # or
   systemctl restart app
   ```

7. Verify:
   ```bash
   curl https://example.com/health
   ```

## Rollback (if needed)

```bash
git revert HEAD
git push origin main
rm -rf var/cache/manifests/
systemctl reload php8.4-fpm
```

Monitor error rate for 5 minutes.
```

## Health Checks & Smoke Tests

After every deployment, verify:

```bash
#!/bin/bash
set -e

echo "Checking health endpoint..."
curl -f https://example.com/health || exit 1

echo "Checking API endpoints..."
curl -f https://example.com/api/me -H "Authorization: Bearer $TOKEN" || exit 1

echo "Checking database..."
curl -f https://example.com/api/invoices -H "Authorization: Bearer $TOKEN" || exit 1

echo "Deployment verified ✓"
```

Run this immediately after deploy and add it to your CI/CD pipeline.

## Common Pitfalls

::: danger Do not deploy schema changes and code that depends on them simultaneously

If schema migration fails partway through, the code still tries to use the old structure and crashes.

**Correct:** Deploy schema first, verify it worked, then deploy code that uses it.

:::

::: danger Do not clear application cache without understanding the cost

```php
// ✗ This will spike database load as all cached queries re-run
$cache->flush();

// ✓ This is more targeted and graceful
$cache->deletePattern('invoice:*'); // Only clear invoices
```

:::

::: danger Do not trust an auto-rollback

Automation is useful, but the decision to rollback is human and should be quick, not automatic.

**Instead:** Alert immediately, let the on-call engineer decide in the first 5 minutes.

:::

::: danger Do not skip testing migrations on staging

A migration that works on 100 rows might deadlock on a 100M-row table.

**Always:** Test on production-sized data.

:::

## Source

- [docs/guides/SAFE_DEPLOYMENTS_GUIDE.md](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/SAFE_DEPLOYMENTS_GUIDE.md)
- [src/Kernel/Pipelines/Worker/JobPayload.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Pipelines/Worker/JobPayload.php)
- [modules/let-migrate](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/modules/let-migrate)
