<?php
/**
 * Pageflow root layout — the HTML shell for a FULL page load.
 *
 * Point PAGEFLOW_ROOT_VIEW at this file (absolute path), e.g. in .env:
 *   PAGEFLOW_ROOT_VIEW="/var/www/app/plugins/Pageflow/resources/views/app.php"
 *
 * The PageflowResponder renders this via ob_start() with an ISOLATED scope —
 * only these variables are available (no globals, no config(), no kernel()):
 *
 *   @var \Plugins\Pageflow\Http\PageflowPage $FLOW_PAGE    The page object.
 *   @var string                              $FLOW_CSRF    HMAC CSRF token ('' if none).
 *   @var string                              $FLOW_APP_ID  Root element id (default 'app').
 *   @var string                              $FLOW_SURFACE The `hkm ui` surface to boot.
 *   @var string|null                         $FLOW_VITE_ENTRY Optional Vite entry override.
 *
 * BOOT MODE: this layout ships in LEGACY mode. It publishes the page object as
 * the `window.initialPage` global ($FLOW_PAGE->renderScript() in <head>) and
 * mounts an OLD Pageflow bundle into a BARE <div id="{APP_ID}"> in the body.
 * The CURRENT (Inertia v2) client instead boots from the root element's
 * `data-page` attribute — to switch, drop the legacy <script> and use
 * $FLOW_PAGE->mount($FLOW_APP_ID) for the body element (see the notes below).
 *
 * How the client boots:
 *   - legacy  (this file): read window.initialPage, mount into <div id="app">.
 *   - current (mount()):   read <div id="app" data-page="{json}"> via
 *                          el.dataset.page (see ui/react/createPageflowApp.tsx),
 *                          then swap components on later navigations.
 *
 * The client reads the CSRF token from <meta name="csrf-token"> and echoes it
 * back in the X-CSRF-Token header on every POST/PUT/DELETE (see ui/core/csrf.ts).
 * That meta tag below is what makes forms work.
 *
 * ── ASSETS: ViteManifest + `hkm ui` surfaces ────────────────────────────────
 * Assets are emitted by the ViteManifest plugin's vite() helper, keyed by the
 * SURFACE (the `hkm ui` buildable app: "admin", "project", …). Each surface's
 * entry is src/surfaces/<surface>/index.tsx (the manifest key is relative to the
 * vite root, frontend/). vite() picks dev-server URLs + HMR when the surface is
 * "hot", or hashed prod <link>/<script> from manifest-<surface>.json otherwise —
 * so this one layout serves every surface with zero hardcoded bundle paths.
 *
 * The surface is passed by the controller to PageflowResponder::render(...) and
 * arrives here as $FLOW_SURFACE. It falls back to the `surface` shared prop, then
 * VITE_SURFACE, then "admin".
 */

// Shared props (set server-side via pageflow_share(...)) ride on the page object.
$props     = $FLOW_PAGE->props;
$appName   = htmlspecialchars((string) ($props['appName'] ?? 'Pageflow App'), ENT_QUOTES, 'UTF-8');
$pageTitle = htmlspecialchars((string) ($props['title'] ?? $appName), ENT_QUOTES, 'UTF-8');
$locale    = htmlspecialchars((string) ($props['locale'] ?? 'en'), ENT_QUOTES, 'UTF-8');

/*
 * ── RICH SEO HEAD — the reserved `seoHead` prop ────────────────────────────
 * A controller builds the COMPLETE SEO block (<title>, meta description,
 * canonical, robots, hreflang, Open Graph + Twitter card, Schema.org JSON-LD
 * @graph) with ONE call — InteractsWithGraphSeo::seoFor(title:, description:,
 * path:, image:, type:, data:) — and passes the rendered string as the
 * `seoHead` page prop. When present it OWNS the <title> (the default one below
 * is skipped). It is pre-rendered, host-aware, escaped HTML from the Project
 * layer — echo it RAW; never re-escape it.
 *
 * Crawlers always take the full-page-load path, so this is all they need. The
 * prop is STRIPPED from the page object this shell boots the client with (see
 * $bootPage below): the block contains a literal </script> (its JSON-LD tag),
 * which would terminate the inline window.initialPage script early — and the
 * client has no use for server-rendered head HTML anyway.
 */
$seoHead  = (string) ($props['seoHead'] ?? '');
$bootPage = $seoHead === '' ? $FLOW_PAGE : new \Plugins\Pageflow\Http\PageflowPage(
    component:      $FLOW_PAGE->component,
    props:          array_diff_key($props, ['seoHead' => true]),
    url:            $FLOW_PAGE->url,
    version:        $FLOW_PAGE->version,
    clearHistory:   $FLOW_PAGE->clearHistory,
    encryptHistory: $FLOW_PAGE->encryptHistory,
);

// The `hkm ui` surface to boot + its Vite entry point (manifest key, relative to
// the frontend/ vite root). Surface: render()'s $FLOW_SURFACE → `surface` shared
// prop → VITE_SURFACE env → 'admin'.
$surface = (string) (($FLOW_SURFACE ?? '') ?: ($props['surface'] ?? (env('VITE_SURFACE') ?: 'admin')));
// Entry: render()'s $FLOW_VITE_ENTRY override → `viteEntry` prop → surface convention.
$entry   = (string) (($FLOW_VITE_ENTRY ?? null) ?: ($props['viteEntry'] ?? "src/surfaces/{$surface}/index.tsx"));

// Cache-bust the fallback bundle with the same version the client checks (only
// used when ViteManifest is not enabled).
$assetVersion = rawurlencode($FLOW_PAGE->version);
?>
<!DOCTYPE html>
<html lang="<?= $locale ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <?php /* CSRF token — the Pageflow client reads this and sends it as X-CSRF-Token. */ ?>
    <?php if ($FLOW_CSRF !== ''): ?>
        <meta name="csrf-token" content="<?= htmlspecialchars($FLOW_CSRF, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>

    <?php if ($seoHead !== '' && str_contains($seoHead, '<')): ?>
        <?php /* Full SEO block from seoFor() — includes its own <title>. */ ?>
        <?= $seoHead ?>
    <?php elseif ($seoHead !== ''): ?>
        <?php /* Defensive: a plain-text seoHead (the XHR tab-title string) is
                 title-only — escape it; never echo non-markup raw. */ ?>
        <title><?= htmlspecialchars($seoHead, ENT_QUOTES, 'UTF-8') ?></title>
    <?php else: ?>
        <title><?= $pageTitle ?></title>
    <?php endif; ?>

    <?php if (function_exists('vite')): ?>
        <?php /* React Fast Refresh preamble (dev/HMR only; empty string in prod). */ ?>
        <?= vite_react_refresh($surface) ?>
        <?php /* Hashed <link>/<script> (+ preloads) in prod, or the dev-server URLs + @vite/client under HMR. Emits the surface's CSS too. */ ?>
        <?= vite($entry, $surface) ?>
    <?php else: ?>
        <?php /* Fallback when ViteManifest is not enabled: static bundle paths. */ ?>
        <link rel="stylesheet" href="/build/<?= htmlspecialchars($surface, ENT_QUOTES, 'UTF-8') ?>/index.css?v=<?= $assetVersion ?>">
        <script type="module" src="/build/<?= htmlspecialchars($surface, ENT_QUOTES, 'UTF-8') ?>/index.js?v=<?= $assetVersion ?>" defer></script>
    <?php endif; ?>

    <?php
    /*
     * LEGACY BOOT MODE — window.initialPage.
     * Publishes the page object as the `window.initialPage` global that OLD
     * Pageflow bundles boot from. The CURRENT client ignores this global and
     * boots from the root element's data-page attribute instead (see the body).
     * $bootPage is $FLOW_PAGE minus the server-only `seoHead` prop.
     */
    echo $bootPage->renderScript();
    ?>
</head>
<body>
    <?php
    /*
     * LEGACY root element — a BARE mount point (no data-page).
     * The legacy client reads window.initialPage (published in <head> above) and
     * mounts React into this empty <div id="{APP_ID}">.
     *
     * To switch to the CURRENT (Inertia v2) client, drop the legacy <script> in
     * <head> and replace this bare div with the data-page mount, which carries
     * the page object on the element itself (use $bootPage, not $FLOW_PAGE, so
     * the server-only seoHead prop stays out of the payload):
     *   echo $bootPage->mount($FLOW_APP_ID);
     *   // → <div id="app" data-page="{escaped-json}"></div>
     */
    ?>
    <div id="<?= htmlspecialchars($FLOW_APP_ID, ENT_QUOTES, 'UTF-8') ?>"></div>

    <noscript>This application requires JavaScript to run.</noscript>
</body>
</html>
