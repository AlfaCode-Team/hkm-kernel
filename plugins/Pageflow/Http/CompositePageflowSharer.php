<?php

declare(strict_types=1);

namespace Plugins\Pageflow\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\Pageflow\API\Contracts\PageflowSharerContract;

/**
 * Runs several PageflowSharerContract contributors in order, so shares can come
 * from independent sources (auth, flash, cart, …) instead of one class.
 *
 * Bind this as the PageflowSharerContract in the project; PageflowStage
 * invokes it once per render and it fans out to each contributor. Later
 * contributors override earlier ones on a key collision (last wins).
 */
final class CompositePageflowSharer implements PageflowSharerContract
{
    /** @var list<PageflowSharerContract> */
    private array $sharers;

    public function __construct(PageflowSharerContract ...$sharers)
    {
        $this->sharers = array_values($sharers);
    }

    /** Append a contributor (returns $this for fluent wiring). */
    public function add(PageflowSharerContract $sharer): self
    {
        $this->sharers[] = $sharer;
        return $this;
    }

    public function share(Request $request, PageflowResponder $responder): void
    {
        foreach ($this->sharers as $sharer) {
            $sharer->share($request, $responder);
        }
    }
}
