<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Tests;

use AlfacodeTeam\Ground\PluginGroundTestCase;
use AlfacodeTeam\Ground\Tests\Fixtures\Sample\SignedJob;

/**
 * The CLI and job-dispatch halves of the harness.
 *
 * Both shipped untested. That is the wrong way round for this package in
 * particular: the reason `$ground->cli()` exists at all is that commands are the
 * surface plugin authors most often ship unexercised, because running one
 * usually means running the whole binary against a real database.
 */
final class CliTest extends PluginGroundTestCase
{
    protected function plugin(): string
    {
        return Fixtures\Sample\Provider::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        SignedJob::reset();
    }

    // ── Registration ──────────────────────────────────────────────────────────

    public function testACommandRegisteredInBootIsVisibleToTheHarness(): void
    {
        $this->assertCommandRegistered('sample:report');
    }

    public function testCommandNamesAreListedForAReadableFailure(): void
    {
        self::assertContains('sample:report', $this->ground()->commandNames());
    }

    public function testCommandsReportsTheRegisteredCLASSES(): void
    {
        self::assertContains(Fixtures\Sample\SampleCommand::class, $this->ground()->commands());
    }

    public function testAnUnregisteredCommandIsNotClaimed(): void
    {
        self::assertFalse($this->ground()->hasCommand('sample:nope'));
    }

    // ── Running ───────────────────────────────────────────────────────────────

    public function testACommandRunsThroughTheRealPipelineAndItsOutputIsCaptured(): void
    {
        $this->ground()->db()->willReturn(['id' => 1], ['id' => 2]);

        $result = $this->ground()->cli('sample:report');

        $this->assertCommandSucceeds($result);
        $this->assertCommandOutputs($result, 'samples visible: 2');
    }

    /**
     * The point of running a command through the pipeline rather than newing it
     * up: CliPipeline resolves it from the CoreContainer, so its constructor
     * dependencies are the ground's fake ports.
     */
    public function testACommandReceivesTheGroundsFakePortsByInjection(): void
    {
        $this->ground()->db()->onQuery('samples', ['id' => 7]);

        $this->assertCommandOutputs($this->ground()->cli('sample:report'), 'samples visible: 1');
        self::assertTrue($this->ground()->db()->ran('select * from samples'));
    }

    public function testANonZeroExitIsReportedAsAFailure(): void
    {
        $result = $this->ground()->cli('sample:report', ['--fail']);

        $this->assertCommandFails($result);
        self::assertTrue($result->failed());
        self::assertFalse($result->succeeded());
    }

    public function testOutputIsReadableLineByLine(): void
    {
        $result = $this->ground()->cli('sample:report');

        self::assertNotSame([], $result->lines());
        self::assertStringContainsString('samples visible', $result->output());
    }

    // ── Jobs ──────────────────────────────────────────────────────────────────

    /**
     * The regression guard for the harness's own job signing.
     *
     * `runJob()` used to stamp `hash_hmac('sha256', json_encode($data), SECRET)`
     * while the kernel signs `jobId|jobClass|queue|maxAttempts|canonical(data)`,
     * so a handler that verified its own envelope could never see a valid one.
     * Nothing failed, because the worker only verifies when a signing secret is
     * configured and the ground configures none.
     */
    public function testRunJobBuildsAPayloadTheKERNELWouldCallSigned(): void
    {
        $this->ground()->runJob(SignedJob::class, ['id' => 1]);

        self::assertTrue(
            SignedJob::$sawValidSignature,
            'runJob() must sign through JobPayload::sign(), not a hand-rolled HMAC.',
        );
    }

    /** The same, for a payload that travelled through the queue and the worker. */
    public function testAPayloadPushedAndDrainedIsStillSigned(): void
    {
        $this->ground()->pushAndWork(SignedJob::class, ['id' => 2], 'sample-signed');

        self::assertTrue(
            SignedJob::$sawValidSignature,
            'FakeQueue::push() must sign through JobPayload::sign(), not a hand-rolled HMAC.',
        );
        $this->assertJobAcked();
    }

    /** A released payload keeps a signature that still verifies. */
    public function testTheSignatureSurvivesARetry(): void
    {
        $queue = $this->ground()->queue();
        $queue->push(SignedJob::class, ['id' => 3], 'sample-signed');

        $payload = $queue->pop('sample-signed');
        self::assertNotNull($payload);
        $queue->release($payload);

        $requeued = $queue->pop('sample-signed');
        self::assertNotNull($requeued);
        self::assertSame(1, $requeued->attempts(), 'A release must advance the attempt counter.');
        self::assertTrue(
            $requeued->isSignatureValid(\AlfacodeTeam\Ground\Fakes\FakeQueue::SECRET),
            'attempts is excluded from the signed material, so a retry must still verify.',
        );
    }
}
