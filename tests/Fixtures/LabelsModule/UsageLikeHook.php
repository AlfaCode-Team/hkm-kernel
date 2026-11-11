<?php

declare(strict_types=1);

namespace Tests\Fixtures\LabelsModule;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\{Request, Response};
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;

/** A global after.execute hook that publishes the `usage` attribute, as a metering plugin does. */
final class UsageLikeHook implements HttpStageContract
{
    public function handle(Request $request, callable $next): Response
    {
        $sink = match (true) {
            Recorder::$usageIsCountable   => new CountableNotASink(),
            Recorder::$usageIsPlainObject => new \stdClass(),
            default                       => new FakeAccumulator(),
        };

        return $next($request->withAttribute('usage', $sink));
    }
}
