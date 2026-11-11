<?php

declare(strict_types=1);

namespace Tests\Fixtures\LabelsModule;

use AlfacodeTeam\PhpServicePlatform\Kernel\Routing\Labels\AuditLabelSink;

final class RecordingAuditSink implements AuditLabelSink
{
    public function record(string $action, array $meta): void
    {
        Recorder::$audits[] = ['action' => $action, 'meta' => $meta];
    }
}
