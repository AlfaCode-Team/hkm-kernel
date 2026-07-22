<?php

declare(strict_types=1);

namespace Plugins\Pageflow\API\Contracts;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\Pageflow\Http\PageflowResponder;

/**
 * Contributes shared props to every Pageflow render — the GDA replacement for
 * the legacy `do_action('pageflow_share')` hook.
 *
 * The project binds ONE implementation into the CoreContainer; PageflowStage
 * invokes it (after.load) on every request that has the Pageflow responder in
 * scope, letting it call $responder->share()/mergeShared() with request-derived
 * data (auth user, flash, CSRF token, locale, …).
 */
interface PageflowSharerContract
{
    public function share(Request $request, PageflowResponder $responder): void;
}
