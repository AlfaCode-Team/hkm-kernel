<?php

declare(strict_types=1);

namespace Plugins\Pageflow\Http;

/**
 * The page object exchanged with the Pageflow client.
 *
 * Serialized into JSON for XHR navigations, or embedded in the initial HTML
 * document's data-page attribute on a full page load.
 *
 *   { "component": "Users/Index", "props": {...}, "url": "/users", "version": "abc" }
 */
final readonly class PageflowPage
{
    /** @param array<string,mixed> $props */
    public function __construct(
        public string $component,
        public array $props,
        public string $url,
        public string $version,
        public bool $clearHistory = false,
        public bool $encryptHistory = false,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'component'      => $this->component,
            'props'          => $this->props,
            'url'            => $this->url,
            'version'        => $this->version,
            // Spec-complete for the Pageflow (Inertia v2) client — it reads these
            // to decide whether to wipe / encrypt browser-history state.
            'clearHistory'   => $this->clearHistory,
            'encryptHistory' => $this->encryptHistory,
        ];
    }

    public function toJson(): string
    {
        
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** HTML-escaped JSON for embedding in the root element's data-page attribute. */
    public function dataPageAttribute(): string
    {
        return htmlspecialchars($this->toJson(), ENT_QUOTES, 'UTF-8');
    }

    /**
     * The Pageflow mount point the client boots from.
     *
     * The current (Inertia v2) client reads the page object from the root
     * element's `data-page` attribute — see createPageflowApp():
     *   JSON.parse(el.dataset.page)
     * so the object MUST live there, not on window.initialPage.
     */
    public function mount(string $appId = 'app'): string
    {
        return sprintf(
            '<div id="%s" data-page="%s"></div>',
            htmlspecialchars($appId, ENT_QUOTES, 'UTF-8'),
            $this->dataPageAttribute(),
        );
    }

    /**
     * @deprecated The legacy client booted from window.initialPage; the current
     *   Pageflow client boots from the data-page attribute (see mount()). Kept
     *   only for backward compatibility with old bundles.
     */
    public function renderScript(): string
    {
        return '<script>window.initialPage = ' . $this->toJson() . ';</script>';
    }
}
