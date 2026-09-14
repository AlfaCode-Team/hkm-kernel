<?php

declare(strict_types=1);

namespace Project\Support\Seo;

use Plugins\SiteSEO\Schema;
use Plugins\SiteSEO\Schema\Thing;

/**
 * Builds a connected Schema.org JSON-LD "@graph" for Google rich results.
 *
 * Rich results (article cards, product snippets with price/rating stars,
 * breadcrumbs, sitelinks search box, FAQ accordions) come from STRUCTURED DATA,
 * not Open Graph. Google strongly prefers ONE `<script type="application/ld+json">`
 * containing a single object with an `@graph` array, where every node has a
 * stable `@id` and nodes reference each other by `{"@id": "…"}` — a recursive
 * graph rather than many disconnected blobs.
 *
 * This builder wires those cross-references for you off a base URL:
 *
 *   #organization ←── publisher ── #website ←── isPartOf ── {url}#webpage
 *        ▲                                                       ▲
 *        └────────── publisher ── {url}#article ── mainEntityOfPage ┘
 *                                      │
 *                                 author → Person
 *
 * Every reference is just the deterministic `@id` string, so you can add nodes in
 * ANY order — a node may reference another that is added later.
 *
 * Usage (an article page):
 *
 *   echo RichGraph::for('https://shop.example.com')
 *       ->organization('PSP Shop', logo: '/img/logo.png',
 *                      sameAs: ['https://twitter.com/pspshop'])
 *       ->website(searchUrl: '/search?q={search_term_string}')   // sitelinks searchbox
 *       ->webPage($url, $title)
 *       ->breadcrumb([['Home','/'], ['Blog','/blog'], [$title, $url]])
 *       ->article($url, $title, $excerpt, image: '/img/blog/x.jpg',
 *                 datePublished: '2026-06-18', dateModified: '2026-06-19',
 *                 authorName: 'Hakeem', authorUrl: '/team/hakeem');
 */
final class RichGraph
{
    /** @var list<Thing> */
    private array $nodes = [];

    public function __construct(private readonly string $baseUrl)
    {
    }

    public static function for(string $baseUrl): self
    {
        return new self(rtrim($baseUrl, '/'));
    }

    // ---- Site-wide nodes (same on every page) -------------------------------

    /**
     * The publishing Organization — drives the knowledge-panel logo and is the
     * `publisher` every other node points at.
     *
     * @param list<string> $sameAs Social / canonical profile URLs.
     */
    public function organization(string $name, ?string $logo = null, array $sameAs = []): self
    {
        $data = [
            '@id'  => $this->baseUrl . '/#organization',
            'name' => $name,
            'url'  => $this->baseUrl . '/',
        ];

        if ($logo !== null) {
            $data['logo'] = $this->imageNode($logo, $this->baseUrl . '/#logo');
            $data['image'] = ['@id' => $this->baseUrl . '/#logo'];
        }
        if ($sameAs !== []) {
            $data['sameAs'] = $sameAs;
        }

        return $this->push('Organization', $data);
    }

    /**
     * The WebSite node. When $searchUrl is given it adds a SearchAction so Google
     * may show the "sitelinks search box". Use the literal token
     * `{search_term_string}` where the query goes.
     */
    public function website(?string $name = null, ?string $searchUrl = null): self
    {
        $data = [
            '@id'       => $this->baseUrl . '/#website',
            'url'       => $this->baseUrl . '/',
            'name'      => $name ?? '',
            'publisher' => ['@id' => $this->baseUrl . '/#organization'],
        ];

        if ($searchUrl !== null) {
            $data['potentialAction'] = [
                '@type'       => 'SearchAction',
                'target'      => [
                    '@type'       => 'EntryPoint',
                    'urlTemplate' => $this->absolute($searchUrl),
                ],
                'query-input' => 'required name=search_term_string',
            ];
        }

        return $this->push('WebSite', $data);
    }

    // ---- Per-page nodes -----------------------------------------------------

    /** The WebPage node for a specific URL — the hub the page's content links to. */
    public function webPage(string $url, string $name, ?string $description = null): self
    {
        $data = [
            '@id'      => $this->pageId($url),
            'url'      => $this->absolute($url),
            'name'     => $name,
            'isPartOf' => ['@id' => $this->baseUrl . '/#website'],
        ];

        if ($description !== null) {
            $data['description'] = $description;
        }

        return $this->push('WebPage', $data);
    }

    /**
     * A BreadcrumbList — renders the breadcrumb trail in the search result.
     *
     * @param list<array{0: string, 1: string}> $items [label, path] pairs, root → current.
     */
    public function breadcrumb(array $items): self
    {
        $elements = [];
        $position = 1;

        foreach ($items as [$label, $path]) {
            $elements[] = [
                '@type'    => 'ListItem',
                'position' => $position++,
                'name'     => $label,
                'item'     => $this->absolute($path),
            ];
        }

        return $this->push('BreadcrumbList', [
            '@id'             => $this->absolute('') . '#breadcrumb',
            'itemListElement' => $elements,
        ]);
    }

    /**
     * An Article node, fully wired to the page, organization and author.
     *
     * With no $authorName the author is the publishing Organization: Google
     * recommends an author on every article, and a site whose documents are
     * contributed rather than bylined has no Person to name.
     *
     * @param list<string> $tags
     * @param ?string      $inLanguage BCP 47 code of the CONTENT (e.g. 'fr'),
     *                                 which can differ from the page UI language.
     */
    public function article(
        string $url,
        string $headline,
        ?string $description = null,
        ?string $image = null,
        ?string $datePublished = null,
        ?string $dateModified = null,
        ?string $authorName = null,
        ?string $authorUrl = null,
        array $tags = [],
        ?string $inLanguage = null,
    ): self {
        return $this->articleLike('Article', $url, $headline, $description, $image, $datePublished, $dateModified, $authorName, $authorUrl, $tags, $inLanguage);
    }

    /** A NewsArticle node (Article subtype Google treats as news). */
    public function newsArticle(
        string $url,
        string $headline,
        ?string $description = null,
        ?string $image = null,
        ?string $datePublished = null,
        ?string $dateModified = null,
        ?string $authorName = null,
        ?string $authorUrl = null,
        array $tags = [],
        ?string $inLanguage = null,
    ): self {
        return $this->articleLike('NewsArticle', $url, $headline, $description, $image, $datePublished, $dateModified, $authorName, $authorUrl, $tags, $inLanguage);
    }

    /** A BlogPosting node (Article subtype for blog posts). */
    public function blogPosting(
        string $url,
        string $headline,
        ?string $description = null,
        ?string $image = null,
        ?string $datePublished = null,
        ?string $dateModified = null,
        ?string $authorName = null,
        ?string $authorUrl = null,
        array $tags = [],
        ?string $inLanguage = null,
    ): self {
        return $this->articleLike('BlogPosting', $url, $headline, $description, $image, $datePublished, $dateModified, $authorName, $authorUrl, $tags, $inLanguage);
    }

    /** @param list<string> $tags */
    private function articleLike(
        string $type,
        string $url,
        string $headline,
        ?string $description,
        ?string $image,
        ?string $datePublished,
        ?string $dateModified,
        ?string $authorName,
        ?string $authorUrl,
        array $tags,
        ?string $inLanguage = null,
    ): self {
        $data = [
            '@id'              => $this->absolute($url) . '#article',
            'headline'         => $headline,
            'mainEntityOfPage' => ['@id' => $this->pageId($url)],
            'isPartOf'         => ['@id' => $this->pageId($url)],
            'publisher'        => ['@id' => $this->baseUrl . '/#organization'],
        ];

        if ($description !== null) {
            $data['description'] = $description;
        }
        if ($image !== null) {
            $data['image'] = $this->imageNode($image, $this->absolute($url) . '#primaryimage');
        }
        if ($datePublished !== null) {
            $data['datePublished'] = $datePublished;
        }
        $data['dateModified'] = $dateModified ?? $datePublished ?? '';
        if ($data['dateModified'] === '') {
            unset($data['dateModified']);
        }
        if ($authorName !== null) {
            $author = ['@type' => 'Person', 'name' => $authorName];
            if ($authorUrl !== null) {
                $author['url'] = $this->absolute($authorUrl);
            }
            $data['author'] = $author;
        } else {
            $data['author'] = ['@id' => $this->baseUrl . '/#organization'];
        }
        if ($tags !== []) {
            $data['keywords'] = implode(', ', $tags);
        }
        if ($inLanguage !== null && $inLanguage !== '') {
            $data['inLanguage'] = $inLanguage;
        }

        return $this->push($type, $data);
    }

    /**
     * A Product node with price (Offer), rating stars (AggregateRating) and an
     * optional review — the trifecta for a product rich snippet.
     *
     * @param array{price?: float|string, currency?: string, availability?: string, url?: string} $offer
     * @param array{rating: float|string, count: int|string}|null                                  $rating
     * @param array{author: string, rating: float|string, body?: string}|null                      $review
     */
    public function product(
        string $url,
        string $name,
        ?string $description = null,
        ?string $image = null,
        ?string $sku = null,
        ?string $brand = null,
        array $offer = [],
        ?array $rating = null,
        ?array $review = null,
    ): self {
        $data = [
            '@id'  => $this->absolute($url) . '#product',
            'name' => $name,
        ];

        if ($description !== null) {
            $data['description'] = $description;
        }
        if ($image !== null) {
            $data['image'] = $this->absolute($image);
        }
        if ($sku !== null) {
            $data['sku'] = $sku;
        }
        if ($brand !== null) {
            $data['brand'] = ['@type' => 'Brand', 'name' => $brand];
        }
        if ($offer !== []) {
            $data['offers'] = array_filter([
                '@type'         => 'Offer',
                'price'         => isset($offer['price']) ? (string) $offer['price'] : null,
                'priceCurrency' => $offer['currency'] ?? 'USD',
                'availability'  => 'https://schema.org/' . ($offer['availability'] ?? 'InStock'),
                'url'           => isset($offer['url']) ? $this->absolute($offer['url']) : $this->absolute($url),
            ], static fn($v) => $v !== null);
        }
        if ($rating !== null) {
            $data['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => (string) $rating['rating'],
                'reviewCount' => (string) $rating['count'],
            ];
        }
        if ($review !== null) {
            $data['review'] = array_filter([
                '@type'        => 'Review',
                'author'       => ['@type' => 'Person', 'name' => $review['author']],
                'reviewRating' => ['@type' => 'Rating', 'ratingValue' => (string) $review['rating']],
                'reviewBody'   => $review['body'] ?? null,
            ], static fn($v) => $v !== null);
        }

        return $this->push('Product', $data);
    }

    /**
     * A Book node — eligible for the Google "book" rich result (and knowledge
     * panel). Carries author, ISBN, page count, language, publisher and an Offer.
     *
     * @param array{price?: float|string, currency?: string, availability?: string, url?: string} $offer
     * @param array{rating: float|string, count: int|string}|null                                  $rating
     */
    public function book(
        string $url,
        string $name,
        ?string $authorName = null,
        ?string $isbn = null,
        ?int $numberOfPages = null,
        ?string $publisher = null,
        ?string $inLanguage = null,
        ?string $datePublished = null,
        ?string $image = null,
        ?string $description = null,
        ?string $bookFormat = null,
        array $offer = [],
        ?array $rating = null,
    ): self {
        $data = [
            '@id'              => $this->absolute($url) . '#book',
            'name'             => $name,
            'mainEntityOfPage' => ['@id' => $this->pageId($url)],
        ];

        if ($authorName !== null) {
            $data['author'] = ['@type' => 'Person', 'name' => $authorName];
        }
        if ($isbn !== null) {
            $data['isbn'] = $isbn;
        }
        if ($numberOfPages !== null) {
            $data['numberOfPages'] = $numberOfPages;
        }
        if ($publisher !== null) {
            $data['publisher'] = ['@type' => 'Organization', 'name' => $publisher];
        }
        if ($inLanguage !== null) {
            $data['inLanguage'] = $inLanguage;
        }
        if ($datePublished !== null) {
            $data['datePublished'] = $datePublished;
        }
        if ($image !== null) {
            $data['image'] = $this->absolute($image);
        }
        if ($description !== null) {
            $data['description'] = $description;
        }
        if ($bookFormat !== null) {
            $data['bookFormat'] = 'https://schema.org/' . $bookFormat;   // e.g. Paperback, EBook
        }
        if ($offer !== []) {
            $data['offers'] = $this->offerNode($offer, $url);
        }
        if ($rating !== null) {
            $data['aggregateRating'] = $this->ratingNode($rating);
        }

        return $this->push('Book', $data);
    }

    /**
     * A Course node — the education / syllabus rich result. Pass the syllabus as
     * an ordered list of section titles; each becomes a Syllabus part.
     *
     * @param list<string>                                                              $syllabus
     * @param array{mode?: string, startDate?: string, endDate?: string}|null           $instance
     */
    public function course(
        string $url,
        string $name,
        string $description,
        ?string $providerName = null,
        array $syllabus = [],
        ?string $educationalLevel = null,
        ?array $instance = null,
    ): self {
        $data = [
            '@id'              => $this->absolute($url) . '#course',
            'name'             => $name,
            'description'      => $description,
            'mainEntityOfPage' => ['@id' => $this->pageId($url)],
        ];

        if ($providerName !== null) {
            $data['provider'] = [
                '@type' => 'Organization',
                'name'  => $providerName,
                'sameAs' => $this->baseUrl . '/',
            ];
        }
        if ($educationalLevel !== null) {
            $data['educationalLevel'] = $educationalLevel;
        }
        if ($instance !== null) {
            $data['hasCourseInstance'] = array_filter([
                '@type'       => 'CourseInstance',
                'courseMode'  => $instance['mode'] ?? 'online',
                'startDate'   => $instance['startDate'] ?? null,
                'endDate'     => $instance['endDate'] ?? null,
            ], static fn($v) => $v !== null);
        }
        if ($syllabus !== []) {
            $sections = [];
            $position = 1;
            foreach ($syllabus as $section) {
                $sections[] = [
                    '@type'    => 'Syllabus',
                    'position' => $position++,
                    'name'     => $section,
                ];
            }
            $data['syllabusSections'] = $sections;
        }

        return $this->push('Course', $data);
    }

    /**
     * A house / apartment FOR RENT — modelled as an Apartment with a lease Offer.
     *
     * @param array{price: float|string, currency?: string, period?: string, url?: string} $rent  monthly rent
     * @param array{street?: string, locality?: string, region?: string, postalCode?: string, country?: string}|null $address
     * @param array{value: float|int, unit?: string}|null $floorSize  e.g. ['value' => 90, 'unit' => 'MTK'] (m²)
     */
    public function realEstate(
        string $url,
        string $name,
        array $rent,
        ?string $description = null,
        ?string $image = null,
        ?array $address = null,
        ?float $numberOfRooms = null,
        ?array $floorSize = null,
        ?string $residenceType = 'Apartment',
    ): self {
        $data = [
            '@id'              => $this->absolute($url) . '#residence',
            'name'             => $name,
            'mainEntityOfPage' => ['@id' => $this->pageId($url)],
        ];

        if ($description !== null) {
            $data['description'] = $description;
        }
        if ($image !== null) {
            $data['image'] = $this->absolute($image);
        }
        if ($numberOfRooms !== null) {
            $data['numberOfRooms'] = $numberOfRooms;
        }
        if ($address !== null) {
            $data['address'] = array_filter([
                '@type'           => 'PostalAddress',
                'streetAddress'   => $address['street'] ?? null,
                'addressLocality' => $address['locality'] ?? null,
                'addressRegion'   => $address['region'] ?? null,
                'postalCode'      => $address['postalCode'] ?? null,
                'addressCountry'  => $address['country'] ?? null,
            ], static fn($v) => $v !== null);
        }
        if ($floorSize !== null) {
            $data['floorSize'] = [
                '@type'    => 'QuantitativeValue',
                'value'    => $floorSize['value'],
                'unitCode' => $floorSize['unit'] ?? 'MTK',
            ];
        }

        // The lease offer: price PER period, businessFunction = LeaseOut.
        $data['offers'] = array_filter([
            '@type'             => 'Offer',
            'businessFunction'  => 'https://schema.org/LeaseOut',
            'availability'      => 'https://schema.org/InStock',
            'url'               => isset($rent['url']) ? $this->absolute($rent['url']) : $this->absolute($url),
            'priceSpecification' => [
                '@type'         => 'UnitPriceSpecification',
                'price'         => (string) $rent['price'],
                'priceCurrency' => $rent['currency'] ?? 'USD',
                'unitText'      => $rent['period'] ?? 'MONTH',
            ],
        ], static fn($v) => $v !== null);

        return $this->push($residenceType ?? 'Apartment', $data);
    }

    /**
     * A pageant edition — an Event whose contestants are Person performers.
     *
     * Eligible for Google's Event rich result (name, date, location, offers).
     * Each contestant becomes a first-class Person node in the graph, referenced
     * from the event's `performer` array by `@id` — a true recursive graph:
     *
     *   Event ── performer ──► Person(contestant) … each its own node
     *
     * @param array{name?: string, url?: string}|null                                          $location  Place; pass ['url'=>…] for a virtual event.
     * @param list<array{name: string, url?: string, image?: string, nationality?: string}>    $contestants
     * @param array{price?: float|string, currency?: string, url?: string}                     $offer     tickets
     */
    public function pageantEdition(
        string $url,
        string $name,
        ?string $startDate = null,
        ?string $endDate = null,
        ?array $location = null,
        ?string $organizer = null,
        ?string $image = null,
        ?string $description = null,
        array $contestants = [],
        array $offer = [],
        string $status = 'EventScheduled',
    ): self {
        return $this->eventNode(
            'Event', $url, $name, $startDate, $endDate, $location, $organizer, $image,
            $description, $contestants, $offer, $status, 'contestant',
            'https://en.wikipedia.org/wiki/Beauty_pageant',
        );
    }

    /**
     * An award edition / ceremony — an Event. Nominees or honorees are Person
     * performers; the award itself rides on `about`.
     *
     * @param array{name?: string, url?: string}|null                                       $location
     * @param list<array{name: string, url?: string, image?: string, nationality?: string}> $nominees
     * @param array{price?: float|string, currency?: string, url?: string}                  $offer
     */
    public function awardEdition(
        string $url,
        string $name,
        ?string $awardName = null,
        ?string $startDate = null,
        ?array $location = null,
        ?string $organizer = null,
        ?string $image = null,
        ?string $description = null,
        array $nominees = [],
        array $offer = [],
        string $status = 'EventScheduled',
    ): self {
        $self = $this->eventNode(
            'Event', $url, $name, $startDate, null, $location, $organizer, $image,
            $description, $nominees, $offer, $status, 'nominee',
            'https://schema.org/Event',
        );

        if ($awardName !== null) {
            // Attach the award as `about` on the event node just pushed.
            $last = $this->nodes[array_key_last($this->nodes)];
            $last->about = ['@type' => 'Thing', 'name' => $awardName];
        }

        return $self;
    }

    /**
     * A standalone contestant / person profile node — use on a contestant page.
     */
    public function contestant(
        string $url,
        string $name,
        ?string $image = null,
        ?string $nationality = null,
        ?string $description = null,
    ): self {
        $this->pushPerson($this->absolute($url) . '#person', [
            'name'        => $name,
            'url'         => $url,
            'image'       => $image,
            'nationality' => $nationality,
            'description' => $description,
        ]);

        // Link the person as the page's main entity.
        return $this;
    }

    /**
     * Shared Event builder. Pushes each performer as its own Person node and
     * references them from the event by @id.
     *
     * @param array{name?: string, url?: string}|null                                       $location
     * @param list<array{name: string, url?: string, image?: string, nationality?: string}> $performers
     * @param array{price?: float|string, currency?: string, url?: string}                  $offer
     */
    private function eventNode(
        string $type,
        string $url,
        string $name,
        ?string $startDate,
        ?string $endDate,
        ?array $location,
        ?string $organizer,
        ?string $image,
        ?string $description,
        array $performers,
        array $offer,
        string $status,
        string $performerIdPrefix,
        ?string $additionalType,
    ): self {
        $data = [
            '@id'              => $this->absolute($url) . '#event',
            'name'             => $name,
            'eventStatus'      => 'https://schema.org/' . $status,
            'mainEntityOfPage' => ['@id' => $this->pageId($url)],
        ];

        if ($additionalType !== null) {
            $data['additionalType'] = $additionalType;
        }
        if ($description !== null) {
            $data['description'] = $description;
        }
        if ($image !== null) {
            $data['image'] = $this->absolute($image);
        }
        if ($startDate !== null) {
            $data['startDate'] = $startDate;
        }
        if ($endDate !== null) {
            $data['endDate'] = $endDate;
        }
        if ($organizer !== null) {
            $data['organizer'] = ['@type' => 'Organization', 'name' => $organizer, 'url' => $this->baseUrl . '/'];
        }
        if ($location !== null) {
            $data += $this->locationFields($location);
        }
        if ($offer !== []) {
            $data['offers'] = $this->offerNode($offer, $url);
        }

        // Performers (contestants / nominees) become their own Person nodes.
        if ($performers !== []) {
            $refs = [];
            $n = 1;
            foreach ($performers as $person) {
                $pid = $this->absolute($url) . '#' . $performerIdPrefix . '-' . $n++;
                $this->pushPerson($pid, $person);
                $refs[] = ['@id' => $pid];
            }
            $data['performer'] = $refs;
        }

        return $this->push($type, $data);
    }

    /**
     * @param array{name?: string, url?: string, street?: string, locality?: string, region?: string, postalCode?: string, country?: string} $location
     * @return array<string, mixed>
     */
    private function locationFields(array $location): array
    {
        if (!empty($location['url'])) {
            return [
                'eventAttendanceMode' => 'https://schema.org/OnlineEventAttendanceMode',
                'location'            => ['@type' => 'VirtualLocation', 'url' => $this->absolute($location['url'])],
            ];
        }

        return [
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            'location'            => array_filter([
                '@type'   => 'Place',
                'name'    => $location['name'] ?? null,
                'address' => array_filter([
                    '@type'           => 'PostalAddress',
                    'streetAddress'   => $location['street'] ?? null,
                    'addressLocality' => $location['locality'] ?? null,
                    'addressRegion'   => $location['region'] ?? null,
                    'postalCode'      => $location['postalCode'] ?? null,
                    'addressCountry'  => $location['country'] ?? null,
                ], static fn($v) => $v !== null),
            ], static fn($v) => $v !== null && $v !== []),
        ];
    }

    /**
     * Push a Person node (contestant / performer / author).
     *
     * @param array{name?: string, url?: ?string, image?: ?string, nationality?: ?string, description?: ?string} $person
     */
    private function pushPerson(string $id, array $person): void
    {
        $data = ['@id' => $id, 'name' => $person['name'] ?? ''];

        if (!empty($person['url'])) {
            $data['url'] = $this->absolute($person['url']);
        }
        if (!empty($person['image'])) {
            $data['image'] = $this->absolute($person['image']);
        }
        if (!empty($person['nationality'])) {
            $data['nationality'] = $person['nationality'];
        }
        if (!empty($person['description'])) {
            $data['description'] = $person['description'];
        }

        $this->push('Person', $data);
    }

    /**
     * An FAQPage — eligible for the expandable FAQ rich result.
     *
     * @param array<string, string> $qa question => answer
     */
    public function faq(array $qa): self
    {
        $entities = [];

        foreach ($qa as $question => $answer) {
            $entities[] = [
                '@type'          => 'Question',
                'name'           => $question,
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $answer],
            ];
        }

        return $this->push('FAQPage', [
            '@id'        => $this->absolute('') . '#faq',
            'mainEntity' => $entities,
        ]);
    }

    /** Add an arbitrary node when a helper does not exist for the type you need. */
    public function node(string $type, array $data): self
    {
        return $this->push($type, $data);
    }

    // ---- Rendering ----------------------------------------------------------

    /** The assembled graph as a Schema instance (single node stays flat). */
    public function toSchema(): Schema
    {
        return new Schema(...$this->nodes);
    }

    /** @return array<string, mixed> the raw JSON-LD structure */
    public function toArray(): array
    {
        return $this->toSchema()->jsonSerialize();
    }

    /** The full `<script type="application/ld+json">…</script>` for the page <head>. */
    public function __toString(): string
    {
        return (string) $this->toSchema();
    }

    // ---- Internals ----------------------------------------------------------

    private function push(string $type, array $data): self
    {
        $this->nodes[] = new Thing($type, $data);

        return $this;
    }

    /**
     * @param array{price?: float|string, currency?: string, availability?: string, url?: string} $offer
     * @return array<string, mixed>
     */
    private function offerNode(array $offer, string $fallbackUrl): array
    {
        return array_filter([
            '@type'         => 'Offer',
            'price'         => isset($offer['price']) ? (string) $offer['price'] : null,
            'priceCurrency' => $offer['currency'] ?? 'USD',
            'availability'  => 'https://schema.org/' . ($offer['availability'] ?? 'InStock'),
            'url'           => isset($offer['url']) ? $this->absolute($offer['url']) : $this->absolute($fallbackUrl),
        ], static fn($v) => $v !== null);
    }

    /**
     * @param array{rating: float|string, count: int|string} $rating
     * @return array<string, string>
     */
    private function ratingNode(array $rating): array
    {
        return [
            '@type'       => 'AggregateRating',
            'ratingValue' => (string) $rating['rating'],
            'reviewCount' => (string) $rating['count'],
        ];
    }

    /** @return array<string, string> an ImageObject node */
    private function imageNode(string $url, string $id): array
    {
        return [
            '@type' => 'ImageObject',
            '@id'   => $id,
            'url'   => $this->absolute($url),
        ];
    }

    private function pageId(string $url): string
    {
        return $this->absolute($url) . '#webpage';
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
}
