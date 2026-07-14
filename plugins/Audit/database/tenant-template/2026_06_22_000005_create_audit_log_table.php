<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * Audit — central `audit_log` (CONTROL PLANE), append-only.
 *
 * Attributable trail for security/compliance: login, tenant.switch,
 * tenant.create, member.invite, user.registered, feedback.submitted, etc. Lives
 * central so cross-tenant admin actions are captured in one place. Owned by the
 * Audit plugin (solves audit.trail); User/Feedback/Tenancy write it ONLY through
 * AuditServiceContract. Stores identifiers + structured meta only — no
 * passwords/tokens/PII payloads.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('audit_log', static function ($t) {
            $t->id();
            $t->char('event_id', 31);
            $t->char('user_id', 31)->nullable();
            $t->char('tenant_id', 31)->nullable();
            $t->string('action', 64)->comment('login|tenant.switch|tenant.create|member.invite|...');
            $t->string('ip', 45)->nullable();
            $t->json('meta')->nullable();
            $t->timestamp('occurred_at')->default('CURRENT_TIMESTAMP');

            $t->unique(['event_id'], 'uniq_event_id');
            $t->index(['tenant_id', 'occurred_at'], 'idx_tenant_time');
            $t->index(['user_id', 'occurred_at'], 'idx_user_time');
            $t->index(['action', 'occurred_at'], 'idx_action_time');

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
            $t->collation('utf8mb4_0900_ai_ci');
            $t->rowFormat('DYNAMIC');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('audit_log');
    }
};
