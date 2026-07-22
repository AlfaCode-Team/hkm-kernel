# Safe Deployments Guide

Complete guide for safely deploying database migrations with enterprise safeguards.

---

## Pre-Deployment Checklist

### 1. Configuration
- [ ] Environment-specific config exists for your target environment
- [ ] Database credentials loaded from secrets manager, NOT config files
- [ ] All required env vars defined in `.env` file
- [ ] Configuration validated: `php cli migrate:status --config=...`

### 2. Backup
- [ ] Recent backup created manually or automatic backup enabled
- [ ] Backup tested and verified restorable
- [ ] Backup stored in secure, offsite location
- [ ] Backup retention policy defined

### 3. Approval
- [ ] Migrations reviewed by team
- [ ] Approval request created in system
- [ ] Authorized approver notified
- [ ] Rollback plan documented

### 4. Testing
- [ ] Migrations tested on identical schema (staging)
- [ ] Dry-run shows expected SQL: `--pretend` flag
- [ ] Performance impact estimated
- [ ] Potential lock contention identified

---

## Environment-Specific Usage

### Local Development
```bash
# Auto-detects APP_ENV=local
php app/cli/run.php migrate:status

# Or explicit config
php app/cli/run.php migrate:status --config=config/environments/local.php

# Features:
# ✅ No safeguards (fast iteration)
# ✅ In-memory SQLite for isolation
# ✅ Transactional: false (speed)
```

### Testing
```bash
# Set APP_ENV=testing
APP_ENV=testing php app/cli/run.php migrate:status

# Features:
# ✅ In-memory SQLite (isolation)
# ✅ Always transactional (safety)
# ✅ Fastest feedback loop
```

### Staging
```bash
# Set APP_ENV=staging
APP_ENV=staging php app/cli/run.php migrate:status

# Features:
# ✅ Real database
# ✅ Always previews first (--pretend=true)
# ✅ Deployment lock enabled
# ✅ Backup before run
```

### Production
```bash
# Set APP_ENV=production
APP_ENV=production php app/cli/run.php migrate:status

# Features:
# 🔴 ALWAYS previews first (--pretend=true)
# 🔴 REQUIRES deployment lock
# 🔴 REQUIRES backup
# 🔴 REQUIRES approval
# 🔴 Limited concurrent approvals
```

---

## Safe Deployment Workflow

### Step 1: Create Approval Request
```bash
# In production, create approval before running
php app/cli/run.php migrate:create-approval \
  --env=production \
  --reason="Add users.email_verified_at column"

# Output: Approval ID: approval_a1b2c3d4e5f6g7h8
```

### Step 2: Get SQL Preview
```bash
# Always preview first in production
php app/cli/run.php migrate:run \
  --config=config/environments/production.php \
  --pretend

# Review the SQL before approval
```

### Step 3: Request Approval
```bash
# Send approval request to authorized reviewer
php app/cli/run.php migrate:request-approval \
  --approval-id=approval_a1b2c3d4e5f6g7h8 \
  --reviewer=devops-team@company.com \
  --reason="Production schema update"

# Reviewer receives notification and approves/rejects
```

### Step 4: Acquire Lock
```bash
# System automatically acquires deployment lock before running
# Other deployments are blocked (configurable timeout)
php app/cli/run.php migrate:run \
  --config=config/environments/production.php \
  --approval=approval_a1b2c3d4e5f6g7h8

# Waits for lock, creates backup, runs migrations
# Lock auto-releases after 5 minutes or manual release
```

### Step 5: Verify Result
```bash
# Check final status
php app/cli/run.php migrate:status \
  --config=config/environments/production.php

# View audit log
php app/cli/commands:audit-log \
  --command=migrate:run \
  --recent=10
```

---

## Emergency Procedures

### If Migration Locks Up
```bash
# Check active locks
php app/cli/run.php deployment:locks

# Force release lock (CAREFUL: only if migration truly failed)
php app/cli/run.php deployment:lock-release \
  --force \
  --reason="Migration failed, manual release"

# Then investigate the migration failure
```

### If Migration Partially Failed
```bash
# Check status - shows which migrations succeeded
php app/cli/run.php migrate:status

# Option 1: Restore from backup
# Use your database backup tool to restore

# Option 2: Fix and retry
# Edit migration to fix the issue, then re-run
php app/cli/run.php migrate:run --config=...
```

### If You Need to Rollback
```bash
# Check rollback plan
php app/cli/run.php migrate:rollback \
  --config=config/environments/production.php \
  --preview  # See what will rollback

# Execute rollback (requires approval)
php app/cli/run.php migrate:rollback \
  --config=config/environments/production.php \
  --approval=approval_xyz
```

---

## Command Reference

### Status Commands
```bash
# Show all migrations and their status
migrate:status

# Show only pending migrations
migrate:status --pending

# Show only applied migrations
migrate:status --applied

# Export as JSON for tooling
migrate:status --json
```

### Deployment Commands
```bash
# Create approval request (production only)
migrate:create-approval

# Request approval from reviewer
migrate:request-approval

# Run approved migrations with lock
migrate:run

# Dry-run: preview SQL without applying
migrate:run --pretend

# Force run (skips safety guards - DANGEROUS)
migrate:run --force
```

### Backup Commands
```bash
# Create manual backup
backup:create

# List all backups
backup:list

# Restore from backup
backup:restore --id=backup_xyz

# Cleanup old backups
backup:cleanup
```

### Audit Commands
```bash
# View command execution history
commands:audit-log

# View migrations run history
migrations:audit-log

# View approvals and rejections
approvals:audit-log
```

### Lock Commands
```bash
# View active deployment locks
deployment:locks

# Release a lock (use carefully!)
deployment:lock-release --force
```

---

## Secrets Management

### Environment Variables
```bash
# For local/staging development
export DB_PASSWORD="your-database-password"
php app/cli/run.php migrate:status
```

### AWS Secrets Manager
```bash
# Configure AWS
export AWS_REGION=us-east-1
export AWS_ACCESS_KEY_ID=...
export AWS_SECRET_ACCESS_KEY=...

# Configure SecretsManager
export SECRETS_PROVIDER=aws

# Secrets are loaded at runtime
php app/cli/run.php migrate:status
```

### HashiCorp Vault
```bash
# Configure Vault
export VAULT_ADDR=https://vault.company.com
export VAULT_TOKEN=...

# Configure SecretsManager
export SECRETS_PROVIDER=vault

# Secrets are loaded at runtime
php app/cli/run.php migrate:status
```

---

## Troubleshooting

### "Configuration file not found"
```bash
# Solution: Set APP_ENV
export APP_ENV=production
php app/cli/run.php migrate:status

# Or use explicit config
php app/cli/run.php migrate:status --config=config/environments/production.php
```

### "Deployment locked by another process"
```bash
# Solution: Wait for lock to expire (5 min default) or force release
php app/cli/run.php deployment:locks  # See who holds lock

# Wait, then retry
sleep 300
php app/cli/run.php migrate:run --config=...

# Or force release if you're sure the other process failed
php app/cli/run.php deployment:lock-release --force
```

### "Backup failed: permission denied"
```bash
# Solution: Check storage/backups directory permissions
ls -la storage/backups/

# Fix permissions
chmod 755 storage/backups/
chmod 644 storage/backups/*.sql
```

### "Database connection refused"
```bash
# Solution: Verify database credentials
export DB_HOST=your-db-host
export DB_NAME=your-db-name
export DB_USERNAME=your-user
export DB_PASSWORD=your-pass

# Test connection
php app/cli/run.php migrate:status

# If still failing, check database server is running
```

---

## Best Practices

✅ **DO:**
- Always preview with `--pretend` before production run
- Request approval before production deployments
- Create backups before production migrations
- Use deployment locks to prevent concurrent runs
- Review audit logs after each deployment
- Test migrations on staging first
- Keep audit logs for compliance
- Use secrets manager, NOT config files

❌ **DON'T:**
- Run migrations directly in production without approval
- Use `--force` to skip safety checks
- Store passwords in config files
- Run multiple migrations concurrently
- Skip backups in production
- Delete migrations after they've been applied
- Commit secrets to version control
- Ignore deployment lock timeouts

---

## Support & Runbooks

See the following for more information:

- **Enterprise Analysis:** `ENTERPRISE_ANALYSIS.md`
- **Implementation Guide:** `ENTERPRISE_IMPLEMENTATION_GUIDE.md`
- **Commands Reference:** `COMMANDS_ARCHITECTURE_GUIDE.md`

For emergencies, contact the DevOps team with the following info:
1. What migration failed and on which environment
2. Full error message from `migrate:status`
3. Time the failure occurred
4. Recent backup ID (from `backup:list`)
