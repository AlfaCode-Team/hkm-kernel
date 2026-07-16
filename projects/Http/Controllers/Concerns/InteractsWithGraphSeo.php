<?php

declare(strict_types=1);

namespace Project\Http\Controllers\Concerns;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\SiteSEO\Types\Article;
use Project\Support\Seo\RichGraph;
use Project\Support\Seo\SeoHead;

/**
 * The full-fat SEO trait: everything in {@see InteractsWithSeo} (sitemap, Open
 * Graph, robots.txt, structured images) PLUS the Schema.org rich-graph and a
 * one-call <head> assembler ({@see SeoHead}) with canonical / robots / hreflang.
 *
 * Use this on any page controller that wants the works. A typical action:
 *
 *   public function show(string $slug): Response
 *   {
 *       $url = $this->siteBaseUrl() . '/blog/' . $slug;
 *
 *       $head = $this->seoHead()
 *           ->title($post->title)->description($post->excerpt)
 *           ->canonical($url)                          // dedupe ?utm=, /amp, …
 *           ->hreflang('en', $url)->xDefault($url)     // language variants
 *           ->openGraph(
 *               $this->openGraph('article', $post->title)
 *                   ->description($post->excerpt)->url($url)
 *                   ->image($this->ogImage($post->cover, 1200, 630))
 *                   ->twitterLargeImage()
 *           )
 *           ->graph(
 *               $this->graph()
 *                   ->organization('PSP Shop', logo: '/img/logo.png')
 *                   ->website('PSP Shop', searchUrl: '/search?q={search_term_string}')
 *                   ->webPage($url, $post->title)
 *                   ->breadcrumb([['Home','/'], ['Blog','/blog'], [$post->title, $url]])
 *                   ->blogPosting($url, $post->title, $post->excerpt, image: $post->cover,
 *                                 datePublished: $post->date, authorName: $post->author)
 *           );
 *
 *       return Response::html("<!doctype html><html><head>{$head}</head>…");
 *   }
 */
trait InteractsWithGraphSeo
{
    use InteractsWithSeo;

    /**
     * A Schema.org rich-results graph bound to the current host.
     *
     * Alias of {@see InteractsWithSeo::richGraph()} with the name that matches
     * this trait — both return the same host-aware {@see RichGraph}.
     */
    protected function graph(?Request $request = null): RichGraph
    {
        return $this->richGraph($request);
    }

    /**
     * A complete <head> SEO assembler bound to the current host — attach OG,
     * JSON-LD, canonical, robots and hreflang, then cast to string in the page.
     */
    protected function seoHead(?Request $request = null): SeoHead
    {
        return SeoHead::for($this->siteBaseUrl($request));
    }

    /**
     * ONE call → the full rendered <head> SEO block: <title>, description,
     * canonical, robots, hreflang, Open Graph + Twitter card (structured
     * 1200×630 image) and the connected Schema.org @graph (Organization →
     * WebSite → WebPage → BreadcrumbList → typed content node).
     *
     * This is the simple, args-only surface for page controllers — no builder
     * chaining. Pageflow pages pass the result under the reserved `seoHead`
     * prop and the stock layout (plugins/Pageflow/resources/layouts/app.php)
     * renders it into the HTML shell:
     *
     *   return $this->pageflow->render($this->request, 'Shop/Product', 'project', props: [
     *       'sku'     => $sku,
     *       'seoHead' => $this->seoFor(
     *           title:       $name,
     *           description: $desc,
     *           path:        "/spa/product/{$sku}",
     *           image:       "/img/product/{$sku}.jpg",
     *           type:        'product',
     *           data:        ['sku' => $sku, 'brand' => 'PSP',
     *                         'offer' => ['price' => 24.0, 'currency' => 'USD']],
     *       ),
     *   ]);
     *
     * @param string  $title       Page title (also og:title / graph headline).
     * @param string  $description Snippet text ('' = omit the meta).
     * @param string  $path        Canonical path or absolute URL ('' = current request path).
     * @param ?string $image       Cover image (path or URL) — og:image + graph image.
     * @param string  $type        website | article | blogPosting | newsArticle |
     *                             product | book | course | realEstate | profile.
     * @param array<string,mixed> $data Extra named args forwarded to the matching
     *                             {@see RichGraph} node (e.g. article: datePublished,
     *                             dateModified, authorName, authorUrl, tags; product:
     *                             sku, brand, offer, rating, review; book: authorName,
     *                             isbn, numberOfPages, publisher, …). Article types
     *                             also feed the OG tags (author/section/tags/dates).
     *                             `faq` => ['Question?' => 'Answer.', …] adds an
     *                             FAQPage node on any type.
     * @param list<array{0:string,1:string}> $breadcrumbs [[label, path], …];
     *                             [] = auto "Home → $title".
     * @param array<string,string> $hreflang lang => URL variants; the first one
     *                             doubles as x-default.
     * @param ?string $siteName    Defaults to env('APP_NAME'). Suffixes the <title>,
     *                             names the Organization/WebSite nodes and og:site_name.
     * @param ?string $logo        Organization logo (path or URL).
     * @param list<string> $sameAs Organization social-profile URLs.
     * @param ?string $searchUrl   Sitelinks-searchbox target, e.g. '/search?q={search_term_string}'.
     * @param bool    $index       false → noindex,nofollow (staging / thin pages).
     */
    /**
     * The lean companion to {@see seoFor()} for PRIVATE pages (admin consoles,
     * account/profile pages, token landings): a correct <title> plus
     * `noindex, nofollow` — and nothing else. Building OG cards or a JSON-LD
     * graph for a page engines are told to ignore is pure wasted CPU/bytes, so
     * this skips all of it. Same Pageflow XHR fast-path as seoFor().
     *
     *   'seoHead' => $this->seoPrivate('Users', request: $request),
     */
    protected function seoPrivate(string $title, ?Request $request = null): string
    {
        $siteName = (string) (env('APP_NAME') ?: '');
        $tabTitle = $siteName !== '' ? "{$title} · {$siteName}" : $title;

        // Pageflow XHR navigation: no head is rendered, but the client syncs the
        // tab title from this prop (plain text, no markup — see ui/react/App.tsx).
        if ((string) ($this->resolveRequest($request)->header('X-Pageflow') ?? '') !== '') {
            return $tabTitle;
        }

        return $this->seoHead($request)
            ->title($tabTitle)
            ->noindex()
            ->render();
    }

    protected function seoFor(
        string $title,
        string $description = '',
        string $path = '',
        ?string $image = null,
        string $type = 'website',
        array $data = [],
        array $breadcrumbs = [],
        array $hreflang = [],
        ?string $siteName = null,
        ?string $logo = null,
        array $sameAs = [],
        ?string $searchUrl = null,
        string $locale = 'en_US',
        bool $index = true,
        ?Request $request = null,
    ): string {
        $siteName ??= (string) (env('APP_NAME') ?: '');
        $tabTitle = $siteName !== '' ? "{$title} · {$siteName}" : $title;

        // Pageflow XHR navigation: the head block is only rendered by the HTML
        // shell on a FULL page load, so skip ALL the OG/graph work and return
        // just the plain tab title — the client syncs document.title from it
        // (see ui/react/App.tsx). Crawlers always take the full-load path.
        $req = $this->resolveRequest($request);
        if ((string) ($req->header('X-Pageflow') ?? '') !== '') {
            return $tabTitle;
        }

        $base = $this->siteBaseUrl($request);

        $url = match (true) {
            $path === '' => $base . $req->path(),
            str_starts_with($path, 'http://'),
            str_starts_with($path, 'https://') => $path,
            default => $base . '/' . ltrim($path, '/'),
        };

        // ── Open Graph / Twitter ────────────────────────────────────────────
        $ogType = match ($type) {
            'article', 'blogPosting', 'newsArticle' => 'article',
            'book'    => 'book',
            'profile' => 'profile',
            default   => 'website',
        };

        $og = $this->openGraph($ogType, $title)
            ->url($url)
            ->locale($locale)
            ->twitterLargeImage();

        if ($description !== '') {
            $og->description($description);
        }
        if ($siteName !== '') {
            $og->siteName($siteName);
        }
        if ($image !== null && $image !== '') {
            $og->image($this->ogImage($image, 1200, 630, $title, $request));
        }
        if ($og instanceof Article) {
            if (($data['authorUrl'] ?? '') !== '') {
                $og->author((string) $data['authorUrl']);
            }
            if (($data['section'] ?? '') !== '') {
                $og->section((string) $data['section']);
            }
            foreach ((array) ($data['tags'] ?? []) as $tag) {
                $og->tag((string) $tag);
            }
            if (($data['datePublished'] ?? '') !== '') {
                $og->publishedAt(new \DateTime((string) $data['datePublished']));
            }
            if (($data['dateModified'] ?? '') !== '') {
                $og->modifiedAt(new \DateTime((string) $data['dateModified']));
            }
        }

        // ── Schema.org @graph — the shared spine every page carries ────────
        $desc  = $description !== '' ? $description : null;
        $graph = $this->graph($request)
            ->organization($siteName !== '' ? $siteName : $base, logo: $logo, sameAs: $sameAs)
            ->website($siteName !== '' ? $siteName : null, searchUrl: $searchUrl)
            ->webPage($url, $title, $desc)
            ->breadcrumb($breadcrumbs !== [] ? $breadcrumbs : [['Home', '/'], [$title, $url]]);

        // The typed content node. `section`/`faq` are consumed above/below and
        // the explicit params are stripped so $data can't collide with them.
        $rest = array_diff_key($data, array_flip([
            'url', 'headline', 'name', 'description', 'image', 'section', 'faq',
        ]));

        match ($type) {
            'website', 'profile' => null,
            'article', 'blogPosting', 'newsArticle'
                         => $graph->{$type}(...$rest, url: $url, headline: $title, description: $desc, image: $image),
            'product'    => $graph->product(...$rest, url: $url, name: $title, description: $desc, image: $image),
            'book'       => $graph->book(...$rest, url: $url, name: $title, description: $desc, image: $image),
            'course'     => $graph->course(...$rest, url: $url, name: $title, description: $desc ?? ''),
            'realEstate' => $graph->realEstate(...$rest, url: $url, name: $title, description: $desc, image: $image),
            default      => throw new \InvalidArgumentException(
                "Unsupported seoFor() type [{$type}]. Use website, article, blogPosting, "
                . 'newsArticle, product, book, course, realEstate or profile.'
            ),
        };

        if (($faq = (array) ($data['faq'] ?? [])) !== []) {
            $graph->faq($faq);
        }

        // ── Assemble the head ───────────────────────────────────────────────
        $head = $this->seoHead($request)
            ->title($tabTitle)
            ->canonical($url)
            ->openGraph($og)
            ->graph($graph);

        if ($description !== '') {
            $head->description($description);
        }

        $index ? $head->robots(maxImagePreview: 'large') : $head->noindex();

        foreach ($hreflang as $lang => $href) {
            $head->hreflang((string) $lang, (string) $href);
        }
        if ($hreflang !== []) {
            $head->xDefault((string) reset($hreflang));
        }

        return $head->render();
    }
}
