<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Tests\Fixtures\Sample\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\Ground\Tests\Fixtures\Sample\SampleServiceContract;

final class SampleController
{
    public function __construct(
        private readonly SampleServiceContract $service,
    ) {}

    public function index(Request $request): Response
    {
        return Response::json($this->service->all());
    }

    public function create(Request $request): Response
    {
        return Response::json($this->service->create((string) $request->input('title', '')), 201);
    }

    public function show(Request $request, string $id = ''): Response
    {
        return Response::json(['id' => $id]);
    }

    /**
     * A Pageflow page object, produced WITHOUT the Pageflow plugin.
     *
     * The shape is copied from Plugins\Pageflow\Http\PageflowPage::toArray()
     * and the header from PageflowResponder::jsonResponse(), so the ground's
     * parsing is exercised against the real contract. Depending on the plugin
     * here would make this package's test suite need Pageflow installed to test
     * anything at all — the live integration is verified separately.
     */
    public function page(Request $request): Response
    {
        return Response::json([
            'component'      => 'Sample/Index',
            'props'          => ['title' => 'Samples', 'user' => ['id' => 'u1']],
            'url'            => $request->path(),
            'version'        => 'test-version',
            'clearHistory'   => false,
            'encryptHistory' => false,
        ], 200, ['X-Pageflow' => 'true']);
    }

    /** The same, embedded in an HTML shell the way a full page load ships it. */
    public function pageShell(Request $request): Response
    {
        $page = json_encode([
            'component' => 'Sample/Index',
            'props'     => ['title' => 'Samples'],
            'url'       => $request->path(),
            'version'   => 'test-version',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $attribute = htmlspecialchars($page, ENT_QUOTES, 'UTF-8');

        return Response::html("<!doctype html><html><body><div id=\"app\" data-page=\"{$attribute}\"></div></body></html>");
    }
}
