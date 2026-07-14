<?php

declare(strict_types=1);

namespace Project\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Contracts\RequestAware;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\View\API\Contracts\ViewRendererContract;
use Project\Http\Controllers\Concerns\InteractsWithCsrf;
use Project\Http\Controllers\Concerns\InteractsWithSession;

/**
 * Base controller for HTML / view endpoints.
 *
 * Renders PHP templates through the View plugin's published contract
 * (view.rendering) and wraps the output in an HTML Response. The renderer is
 * INJECTED (request-scoped — it carries mutable per-request template data), so
 * a subclass must require "view.rendering" in its module.json / proj.json.
 *
 *   final class HomeController extends ViewController
 *   {
 *       // RequestAware: the action takes route params only — no $request arg.
 *       public function index(): Response
 *       {
 *           $this->queueCookie('theme', 'dark');
 *           return $this->view('welcome', ['name' => 'Hakeem'], layout: 'layouts/app');
 *       }
 *   }
 *
 * Note: this base lives in the project layer (not the kernel) precisely because
 * templating is a plugin concern — the kernel stays renderer-agnostic.
 */
abstract class ViewController implements RequestAware
{
    use InteractsWithCsrf;
    use InteractsWithSession;
    protected const API_BASE = null;
    public function __construct(
        protected readonly ViewRendererContract $renderer,
    ) {
    }

    /**
     * Render a view into an HTML Response.
     *
     * @param array<string, mixed> $data   Template variables.
     * @param string|null          $layout Optional layout template (e.g. 'layouts/app').
     */
    protected function view(string $view, array $data = [], ?string $layout = null, int $status = 200): Response
    {
        $data['apiBase'] = static::API_BASE;

        // ViewController always composes InteractsWithCsrf, so a token is always
        // available. (The previous guard checked csrfToken(), which only exists
        // when InteractsWithSession is also used — so $data['csrf'] was never set
        // and every form rendered an empty token.)
        $data['csrf'] = $this->_csrfToken();

        $options = $layout !== null ? ['layout' => $layout] : null;

        $html = $this->renderer->setData($data)->render($view, $options);

        return Response::html($html, $status);
    }

    /**
     * Render a view as a 404 page (handler for "not found" within an HTML flow).
     *
     * @param array<string, mixed> $data
     */
    protected function viewNotFound(string $view, array $data = [], ?string $layout = null): Response
    {
        return $this->view($view, $data, $layout, 404);
    }

    /** Redirect to another URL (303-friendly default for post-redirect-get is 302). */
    protected function redirect(string $url, int $status = 302): Response
    {
        return Response::redirect($url, $status);
    }

    /** Redirect back to the referer, falling back to a safe path. */
    protected function back(?string $referer, string $fallback = '/'): Response
    {
        return Response::back($referer, $fallback);
    }
}
