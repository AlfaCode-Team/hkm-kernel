# `Project\Support\Seo` — SEO, Sitemap & Rich-Result Toolkit

> Namespace `Project\Support\Seo\` → `projects/Support/Seo/`.

Project-layer, **DI-free** helpers for building sitemaps, `<head>` metadata and
Schema.org JSON-LD. They autoload directly, so **no module load is required** to
build a sitemap, an Open Graph block or a rich-results graph.

Only NETWORK actions (sitemap ping, IndexNow submission) go through the
**SiteSEO plugin** (`Plugins\SiteSEO`, solves `seo.management`) because outbound
HTTP must travel via `HttpClientPort`. A route doing that declares
`"requires": ["seo.management"]`.

| Need | Where |
|---|---|
| Build sitemap XML / JSON-LD / `<head>` | these classes — no module needed |
| Ping search engines, submit IndexNow | `SeoServiceContract` (SiteSEO plugin) |
| Type/OpenGraph primitives (`Type`, `Image`, `Schema`, `RobotsTxtEditor`) | SiteSEO plugin (used by `SeoHead`/`RichGraph`) |

Controller ergonomics live in `Project\Http\Controllers\Concerns\InteractsWithSeo`
and `InteractsWithGraphSeo` — see [`../../Http/Controllers/README.md`](../../Http/Controllers/README.md).

---

## Class map

| Class | Role |
|---|---|
| `RouteCatalog` | Reads the compiled route manifest → the site's public static GET paths |
| `SitemapGenerator` | Small/route-derived sitemaps (in-memory, one `<urlset>` + index) |
| `SitemapStreamWriter` | Enterprise: streams an `iterable` to split child files + index, **O(1) memory** |
| `SitemapUrlProvider` (interface) | Expands ONE dynamic route pattern (`/blog/{slug}`) lazily from the DB |
| `SitemapSource` | Stitches static routes + providers; reports uncovered dynamic routes |
| `RichGraph` | Schema.org JSON-LD `@graph` builder — nodes cross-linked by `@id` |
| `SeoHead` | One-call `<head>`: title, description, canonical, robots, hreflang, OG, JSON-LD |
| `IndexNowKey` | IndexNow key value object (value, key-file contents, keyLocation) |

---

## `RouteCatalog` — what pages actually exist

```php
use Project\Support\Seo\RouteCatalog;

$catalog = RouteCatalog::fromManifest();                    // default manifest path
$catalog = RouteCatalog::fromManifest($path);               // explicit route-manifest.php

$paths = $catalog->publicPaths();                           // ['/', '/about', '/pricing', …]
$paths = $catalog->publicPaths(
    excludePrefixes: ['/internal'],
    excludePaths:    ['/health'],
);

// A sitemap describes ONE host. Pass it to include that host's domain-grouped
// pages alongside the shared ones; the default (null) is shared-only.
$paths = $catalog->publicPaths(domain: 'africavoting.local');

$catalog->all();                                            // raw manifest, keyed "METHOD /path"
```

`publicPaths()` keeps only entries that are **GET**, **static** (no `{param}` —
those cannot be enumerated from a manifest), **not auth-gated**, and not under
the default excluded prefixes/paths (API, SEO endpoints, …). Results are
deduped, so a project route overriding a plugin route appears once.

**Domain groups.** A route may be grouped under a host, and its manifest key is
then `METHOD@host /path`. `publicPaths()` skips every grouped route by default,
so an existing single-host sitemap is byte-identical. Passing `domain:` expands
that host into its candidate groups (exact, wildcard, bare subdomain) and
includes those pages plus the shared ones.

Dynamic routes are deliberately dropped here — feed them through a
`SitemapUrlProvider` instead.

---

## Sitemaps

### `SitemapGenerator` — small / route-derived sets

Builds ONE `<urlset>` in memory (keep it ≤ ~30k URLs).

```php
use Project\Support\Seo\SitemapGenerator;

$xml = SitemapGenerator::for('https://example.com')
    ->named('pages')                 // child sitemap name (default "pages")
    ->indexedAs('sitemap.xml')       // index filename (default "sitemap.xml")
    ->fromRoutes($catalog, priority: '0.8', changeFreq: 'weekly')
    ->add('/launch', priority: '1.0', changeFreq: 'daily', lastMod: '2026-08-01')
    ->addMany(['/a', '/b'])
    ->toXml();                       // or ->save($directory) → index path

$count = $generator->count();
```

### `SitemapStreamWriter` — millions of URLs, flat memory

No DOM, no array buffer: writes straight to file handles, auto-splitting at
`maxPerFile` and writing the index for you.

```php
use Project\Support\Seo\SitemapStreamWriter;

$writer = new SitemapStreamWriter(
    baseUrl:    'https://example.com',
    maxPerFile: 50000,          // sitemap protocol maximum
    gzip:       false,          // true → .xml.gz children
    indexName:  'sitemap.xml',
);

$result = $writer->write($publicDir, $urls);
// ['index' => '/…/sitemap.xml', 'sitemaps' => ['/…/sitemap-1.xml', …], 'urls' => 1_240_337]

// Or stream one urlset straight to the HTTP response, no buffering:
return Response::stream(fn() => $writer->echoStream($urls));
```

Each `$urls` item is a path/URL string **or**
`['loc'|'path' => …, 'lastmod' => …, 'changefreq' => …, 'priority' => …]`.
`echoStream()` flushes every 1000 rows so memory stays flat and bytes reach the
client immediately.

### `SitemapUrlProvider` + `SitemapSource` — dynamic routes

Implement one provider per dynamic route pattern; yield from a **keyset cursor**
so the DB read is also O(1) in memory.

```php
final class BlogSitemapProvider implements SitemapUrlProvider
{
    public function pattern(): string { return '/blog/{slug}'; }

    public function urls(): iterable
    {
        $lastId = 0;
        while ($rows = $this->db->query(
            'SELECT id, slug, updated_at FROM posts
             WHERE id > :last AND published = 1 ORDER BY id LIMIT 5000',
            ['last' => $lastId]
        )) {
            foreach ($rows as $r) {
                $lastId = (int) $r['id'];
                yield ['loc' => '/blog/' . $r['slug'], 'lastmod' => $r['updated_at']];
            }
        }
    }
}

$source = new SitemapSource($catalog, [new BlogSitemapProvider($db)]);

$writer->write($dir, $source->all());       // static pages, then every provider — lazily

$source->dynamic();                         // provider URLs only (Generator)
$source->dynamicRoutes();                   // '{param}' routes found in the manifest
$source->coveredPatterns();                 // patterns a provider claims
$source->uncoveredDynamicRoutes();          // ← guard: dynamic routes with NO provider
```

`uncoveredDynamicRoutes()` is the omission guard — assert on it in a test or log
it in the sitemap command so a new dynamic route cannot silently vanish from the
sitemap.

---

## `RichGraph` — Schema.org JSON-LD `@graph`

One graph per page. Nodes are created with helper methods and cross-linked by
`@id`, which is what Google's rich-result parsers expect — not a pile of
disconnected blobs.

```php
use Project\Support\Seo\RichGraph;

$graph = RichGraph::for('https://example.com')
    ->organization('Example Ltd', logo: '/img/logo.png', sameAs: ['https://x.com/example'])
    ->website(name: 'Example', searchUrl: '/search?q={search_term_string}')
    ->webPage('/blog/hello', 'Hello world', 'An introduction')
    ->breadcrumb([['name' => 'Blog', 'url' => '/blog'], ['name' => 'Hello', 'url' => '/blog/hello']])
    ->blogPosting(
        url: '/blog/hello', headline: 'Hello world',
        description: 'An introduction', image: '/img/hero.jpg',
        datePublished: '2026-08-01', dateModified: '2026-08-05',
        authorName: 'Sam Doe', authorUrl: '/authors/sam', tags: ['php', 'gda'],
    );

echo $graph;                 // <script type="application/ld+json"> … </script>
$graph->toArray();           // raw JSON-LD structure
$graph->toSchema();          // SiteSEO Schema instance (single node stays flat)
```

Node builders:

| Method | Emits |
|---|---|
| `organization($name, $logo, $sameAs)` | `Organization` — publisher/author anchor |
| `website($name, $searchUrl)` | `WebSite` (+ `SearchAction` sitelinks searchbox) |
| `webPage($url, $name, $description)` | `WebPage` — the page node other nodes hang off |
| `breadcrumb($items)` | `BreadcrumbList` |
| `article(…)` / `newsArticle(…)` / `blogPosting(…)` | `Article` and its subtypes, wired to page + org + author |
| `product($url, $name, …, $offer, $rating, $review)` | `Product` + `Offer` + `AggregateRating` + `Review` |
| `book(…)`, `course(…)`, `realEstate(…)` | `Book`, `Course`, real-estate listing |
| `pageantEdition(…)`, `awardEdition(…)`, `contestant(…)` | `Event` + `Person` performers (contestants / nominees) |
| `faq($qa)` | `FAQPage` from a `question => answer` map |
| `node($type, $data)` | any node type there is no helper for |

Every builder returns `$this`, so a page is one fluent chain.

---

## `SeoHead` — the whole `<head>` in one call

```php
use Project\Support\Seo\SeoHead;

echo SeoHead::for('https://example.com')
    ->title('Hello world — Example')
    ->description('An introduction to the platform.')
    ->canonical('/blog/hello')
    ->robots(index: true, follow: true, maxImagePreview: 'large', maxSnippet: 160)
    ->hreflang('fr', '/fr/blog/hello')
    ->xDefault('/blog/hello')
    ->openGraph($ogType)      // Plugins\SiteSEO\Type (build it via InteractsWithSeo::openGraph())
    ->graph($graph)           // RichGraph — rendered as JSON-LD inside the head
    ->render();
```

`noindex()` is the one-call shortcut for `robots(index: false, follow: false)` —
use it on staging, private, and thin pages. `render()` and `__toString()` are
equivalent.

---

## `IndexNowKey`

```php
use Project\Support\Seo\IndexNowKey;

$key = IndexNowKey::generate();                       // 32 hex chars — publish once, keep stable
$key = IndexNowKey::fromString(env('INDEXNOW_KEY'));  // validates ^[a-zA-Z0-9-]{8,128}$

$key->value();                       // the key itself
$key->fileContents();                // body of the key file to serve
$key->path();                        // '' → canonical "/{key}.txt" at the site root
$key->location('https://example.com');   // absolute keyLocation for the API call

$key = $key->publishedAt('/seo/indexnow.txt');   // immutable — returns a NEW instance
```

Submission itself goes through the SiteSEO plugin
(`SeoServiceContract::indexNow(...)` / `indexNowChunks()` + the `seo.indexnow`
job), never from these classes.

---

## Rules

```
✓ Sitemap/OG/JSON-LD building is DI-free — no module load, no container.
✓ Network actions (ping, IndexNow) need requires:["seo.management"] → HttpClientPort.
✓ Huge sitemaps → SitemapStreamWriter over a generator (keyset DB cursor), never an array/DOM.
✓ Every dynamic route gets a SitemapUrlProvider; assert uncoveredDynamicRoutes() is empty.
✓ One JSON-LD @graph per page (RichGraph), nodes linked by @id.
✗ Raw cURL for ping/IndexNow — always the SiteSEO gateway + HttpClientPort.
✗ Buffering a whole catalogue in memory to build a sitemap or submit IndexNow.
✗ Hardcoding the host — take the base URL from the request/DomainContext (InteractsWithSeo::siteBaseUrl()).
```
