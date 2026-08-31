<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground;

/**
 * The Pageflow page object a route produced: `{component, props, url, version}`.
 *
 * Assert on THIS, never on rendered HTML. The server's contract with the client
 * is the page object; the markup around it belongs to a React component that a
 * designer may rewrite this afternoon. A test asserting on markup breaks on a
 * class-name change and passes when the props are wrong — exactly backwards.
 *
 * Obtained two ways, both of which land here:
 *   - XHR ({@see BootedGround::pageflow()}) — the JSON body, verbatim.
 *   - Full page load — parsed back out of the HTML shell, from the root
 *     element's `data-page` attribute or the legacy `window.initialPage`.
 */
final class PageflowResponse
{
    private function __construct(
        private readonly GroundResponse $response,
        /** @var array<string, mixed> */
        private readonly array $page,
        /** True when this came from an HTML shell rather than the JSON body. */
        public readonly bool $fromHtml,
    ) {}

    public static function fromJson(GroundResponse $response): self
    {
        return new self($response, $response->json(), fromHtml: false);
    }

    /**
     * Parse the page object back out of a full-load HTML document.
     *
     * Both boot mechanisms are handled because both ship: the current client
     * reads `JSON.parse(el.dataset.page)`, while the legacy layout still writes
     * `window.initialPage`. A ground that understood only one would report an
     * empty page for an application using the other.
     */
    public static function fromHtml(GroundResponse $response): self
    {
        $body = $response->body();

        if (preg_match('/data-page="([^"]*)"/s', $body, $matches) === 1) {
            $decoded = json_decode(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'), true);
            if (\is_array($decoded)) {
                return new self($response, $decoded, fromHtml: true);
            }
        }

        if (preg_match('/window\.initialPage\s*=\s*(\{.*?\});/s', $body, $matches) === 1) {
            $decoded = json_decode($matches[1], true);
            if (\is_array($decoded)) {
                return new self($response, $decoded, fromHtml: true);
            }
        }

        return new self($response, [], fromHtml: true);
    }

    public function raw(): GroundResponse
    {
        return $this->response;
    }

    public function status(): int
    {
        return $this->response->status();
    }

    /** True when a page object was actually found. */
    public function isPage(): bool
    {
        return isset($this->page['component']) && \is_string($this->page['component']);
    }

    public function component(): string
    {
        return (string) ($this->page['component'] ?? '');
    }

    /** @return array<string, mixed> */
    public function props(): array
    {
        $props = $this->page['props'] ?? [];

        return \is_array($props) ? $props : [];
    }

    public function url(): string
    {
        return (string) ($this->page['url'] ?? '');
    }

    public function version(): string
    {
        return (string) ($this->page['version'] ?? '');
    }

    /** Dotted read into props: `$page->prop('user.email')`. */
    public function prop(string $path, mixed $default = null): mixed
    {
        $value = $this->props();

        foreach (explode('.', $path) as $segment) {
            if (!\is_array($value) || !\array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function hasProp(string $path): bool
    {
        return $this->prop($path, $this) !== $this;
    }

    /** @return list<string> top-level prop names, for a readable failure */
    public function propNames(): array
    {
        return array_keys($this->props());
    }

    /**
     * Write the page object to a JSON file for a component test to import.
     *
     * This is the seam between the two halves of a UI test. A React test needs
     * props; hand-written mock props drift from the server the moment someone
     * renames a field, and the test keeps passing while the page breaks. Dumping
     * what the SERVER actually produced means the component is rendered against
     * real data, and a server-side rename fails the component test.
     *
     *     $this->ground()->pageflow('/auth/login')->writeFixture('ui/__fixtures__/login.json');
     *
     * Commit the fixture. `make:ui-test` scaffolds the vitest side that reads it.
     */
    public function writeFixture(string $path): self
    {
        $dir = \dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Ground: could not create the fixture directory [{$dir}].");
        }

        file_put_contents($path, json_encode(
            $this->page,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n");

        return $this;
    }

    /** A one-block summary for a failure message. */
    public function describe(): string
    {
        if (!$this->isPage()) {
            return "No Pageflow page object in the response.\n" . $this->response->describe();
        }

        return 'component: ' . $this->component() . "\n"
            . 'url:       ' . $this->url() . "\n"
            . 'props:     ' . ($this->propNames() === [] ? '(none)' : implode(', ', $this->propNames())) . "\n"
            . $this->response->describe();
    }

    public function __toString(): string
    {
        return $this->describe();
    }
}
