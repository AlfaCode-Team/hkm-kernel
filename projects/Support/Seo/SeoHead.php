<?php

declare(strict_types=1);

namespace Project\Support\Seo;

use Plugins\SiteSEO\Type;

/**
 * Assembles a COMPLETE <head> SEO block — everything a search engine needs on a
 * page, in one render:
 *
 *   <title> + <meta name="description">      — the snippet text
 *   <link rel="canonical">                    — the ONE true URL (dedupes ?utm=, /amp, etc.)
 *   <meta name="robots">                      — index/follow, max-image-preview, …
 *   <link rel="alternate" hreflang=…>         — language/region variants (+ x-default)
 *   Open Graph / Twitter tags                 — social share cards (from a Type)
 *   JSON-LD @graph                            — Google rich results (from a RichGraph)
 *
 * Canonical and hreflang are the two most-requested "more" tags: the canonical
 * tells Google which URL to index when the same content is reachable many ways;
 * hreflang tells it which language version to show which user. Both take a path
 * or absolute URL — relative paths are resolved against the current host.
 *
 *   echo SeoHead::for($base)
 *       ->title($title)->description($desc)
 *       ->canonical($url)
 *       ->robots(maxImagePreview: 'large')
 *       ->hreflang('en', $url)->hreflang('fr', $frUrl)->xDefault($url)
 *       ->openGraph($ogType)        // a Plugins\SiteSEO\Type
 *       ->graph($richGraph);        // a Project\Support\Seo\RichGraph
 */
final class SeoHead
{
    private ?string $title = null;
    private ?string $description = null;
    private ?string $canonical = null;
    private string $robots = 'index, follow';
    private ?string $ogTags = null;
    private ?string $jsonLd = null;

    /** @var list<array{0: string, 1: string}> hreflang => href */
    private array $alternates = [];

    public function __construct(private readonly string $baseUrl)
    {
    }

    public static function for(string $baseUrl): self
    {
        return new self(rtrim($baseUrl, '/'));
    }

    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /** The canonical URL for this page (path or absolute). */
    public function canonical(string $url): self
    {
        $this->canonical = $this->absolute($url);

        return $this;
    }

    /**
     * The robots directive. Defaults to index/follow; flip $index/$follow for
     * staging or thin pages, and set the preview hints Google honours.
     */
    public function robots(
        bool $index = true,
        bool $follow = true,
        ?string $maxImagePreview = 'large',
        ?int $maxSnippet = null,
    ): self {
        $parts = [$index ? 'index' : 'noindex', $follow ? 'follow' : 'nofollow'];

        if ($maxImagePreview !== null) {
            $parts[] = 'max-image-preview:' . $maxImagePreview;
        }
        if ($maxSnippet !== null) {
            $parts[] = 'max-snippet:' . $maxSnippet;
        }

        $this->robots = implode(', ', $parts);

        return $this;
    }

    /** Mark the page noindex,nofollow in one call. */
    public function noindex(): self
    {
        return $this->robots(index: false, follow: false, maxImagePreview: null);
    }

    /** Add a language/region alternate (hreflang). */
    public function hreflang(string $lang, string $url): self
    {
        $this->alternates[] = [$lang, $this->absolute($url)];

        return $this;
    }

    /** The x-default hreflang (fallback for unmatched languages). */
    public function xDefault(string $url): self
    {
        return $this->hreflang('x-default', $url);
    }

    /**
     * Attach a prebuilt Open Graph / Twitter document.
     *
     * SiteSEO's Type renders a "Primary Meta Tags" block — <title>, meta title
     * and meta description — ahead of its og:/twitter: tags, for callers that
     * use it on its own. This class writes its own title and description, so
     * embedding that string whole put two <title> elements and two descriptions
     * in every head, and left the search engine to pick one. Only the og: and
     * twitter: tags are kept.
     */
    public function openGraph(Type $type): self
    {
        $this->ogTags = trim((string) preg_replace(
            '~^[ \t]*(?:<!-- Primary Meta Tags -->|<title>[^<]*</title>|<meta name="(?:title|description)"[^>]*>)[ \t]*\R?~mu',
            '',
            (string) $type,
        ));

        return $this;
    }

    /** Attach a prebuilt Schema.org JSON-LD rich graph. */
    public function graph(RichGraph $graph): self
    {
        $this->jsonLd = (string) $graph;

        return $this;
    }

    /** Render the full <head> SEO block. */
    public function render(): string
    {
        $lines = [];

        if ($this->title !== null) {
            $lines[] = '<title>' . $this->esc($this->title) . '</title>';
        }
        if ($this->description !== null) {
            $lines[] = '<meta name="description" content="' . $this->esc($this->description) . '">';
        }
        if ($this->canonical !== null) {
            $lines[] = '<link rel="canonical" href="' . $this->esc($this->canonical) . '">';
        }

        $lines[] = '<meta name="robots" content="' . $this->esc($this->robots) . '">';

        foreach ($this->alternates as [$lang, $href]) {
            $lines[] = '<link rel="alternate" hreflang="' . $this->esc($lang)
                . '" href="' . $this->esc($href) . '">';
        }

        if ($this->ogTags !== null) {
            $lines[] = $this->ogTags;
        }
        if ($this->jsonLd !== null) {
            $lines[] = $this->jsonLd;
        }

        return implode("\n", $lines);
    }

    public function __toString(): string
    {
        return $this->render();
    }

    private function absolute(string $pathOrUrl): string
    {
        if ($pathOrUrl === '') {
            return $this->baseUrl . '/';
        }
        if (str_starts_with($pathOrUrl, 'http://') || str_starts_with($pathOrUrl, 'https://')) {
            return $pathOrUrl;
        }

        return $this->baseUrl . '/' . ltrim($pathOrUrl, '/');
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
