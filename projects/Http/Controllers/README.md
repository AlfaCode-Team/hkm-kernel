# `Project\Http\Controllers` — Base Controllers & Concerns

> Namespace `Project\Http\Controllers\` → `projects/Http/Controllers/`.

Optional base classes + traits that give controllers a consistent response
envelope and ergonomic access to request-scoped infrastructure (session, cookies,
CSRF, storage, project context, auth, SEO).

They live in the **PROJECT layer, not the kernel** — view rendering, cookies and
sessions are plugin concerns, and the kernel must not couple to a plugin. The
ONLY kernel↔controller seam is the `RequestAware` contract.

Controllers still obey the Five Access Rules: **3 lines maximum** — build a DTO,
call a published service contract, translate the result to a `Response`. These
helpers exist so that translation stays one line, not so logic can move in.

---

## `RequestAware` — actions take route params only

Both bases implement `Kernel\Http\Contracts\RequestAware` (`setRequest(Request): static`,
provided by the `HasRequest` trait). `ExecuteStage` checks `instanceof RequestAware`
and, when true, calls `setRequest($request)` with the container-bearing request and
invokes the action as `$method(...$routeParams)` — **without `$request`**.

```php
final class CartController extends ApiController         // RequestAware
{
    public function show(string $id): Response           // route param only — no $request
    {
        $this->queueCookie('last_viewed', $id);          // request injected by the kernel
        return $this->okOrNotFound($this->cart->find($id)?->toArray());
    }
}
```

Plain controllers (not extending these bases) keep the classic
`$method($request, ...$params)` signature — fully backward compatible. Inside a
`RequestAware` controller the raw request is `$this->request`; every helper also
accepts an explicit `?Request` override as its last argument.

---

## `ApiController` — JSON endpoints

`abstract class ApiController implements RequestAware`, composing
`InteractsWithCsrf` (which pulls in `HasRequest` + `InteractsWithCookies`) and
`InteractsWithSession`. Pure kernel-typed surface — no plugin or vendor coupling.

Every success is `{"data": …}`; every failure is the kernel error envelope
`{"error": {"code","message"[,"fields"]}}`.

| Helper | Result |
|---|---|
| `ok($data = null, $status = 200)` | `200 {"data": …}` |
| `created($data, ?$location)` | `201` + `Location` header |
| `accepted($data = null)` | `202` — queued/async work |
| `noContent()` | `204` |
| `paginated($items, $total, $page, $perPage)` | `200 {"data": …, "meta": {total, page, per_page, pages}}` |
| `okOrNotFound($data, $message)` | `200` when non-null, `404` when null |
| `notFound($message)` / `forbidden($message)` | `404` / `403` |
| `unprocessable(array $errors, $message)` | `422` + field errors |
| `identity(?Request)` | attached `Identity`, or `Identity::guest()` |

## `ViewController` — HTML endpoints

`abstract class ViewController implements RequestAware`, same traits, plus an
injected `Plugins\View\API\Contracts\ViewRendererContract`:

```php
final class HomeController extends ViewController
{
    protected const API_BASE = '/api';        // exposed to templates as $apiBase

    public function index(): Response
    {
        return $this->view('home', ['title' => 'Welcome'], layout: 'layouts/app');
    }
}
```

| Helper | Result |
|---|---|
| `view($view, $data = [], ?$layout, $status = 200)` | HTML response; injects `$data['csrf']` (from `InteractsWithCsrf`) and `$data['apiBase']` (`static::API_BASE`) |
| `viewNotFound($view, $data = [], ?$layout)` | the same render at status `404` |
| `redirect($url, $status = 302)` | redirect |
| `back(?$referer, $fallback = '/')` | redirect to referer with a safe fallback |

A route using `ViewController` must load the View plugin —
`"requires": ["view.rendering"]` on the route, or the plugin's own module route.

---

## Concerns

All traits compose on one controller: they share `HasRequest`, which is flattened
once so there is no trait conflict.

### `HasRequest`
Holds `$request` + `setRequest(Request): static`; `resolveRequest(?Request)`
returns the explicit override or the injected request, throwing `KernelException`
when neither exists. Every other concern builds on it.

### `InteractsWithSession` — `SessionPort`
`session()`, `sessionGet()`, `sessionPut()`, `sessionHas()`, `sessionPull()`,
`sessionForget()`, `flash()`, `csrfToken()`, `regenerateSession()` (call right
after login — session-fixation defence), `invalidateSession()` (logout).
Read helpers **no-op / return the default** when the Session plugin is absent.

### `InteractsWithCookies` — `CookieJar`
`cookie()` (read the RAW request cookie), `queueCookie()`, `rememberCookie()`,
`forgetCookie()`, `hasQueuedCookie()`, `decryptCookie()`, `cookieJar()`.
Defaults come from `config/cookie.php` + `COOKIE_*` env. Never call
`CookieJar::applyTo()` yourself — `QueuedCookiesStage` flushes the jar.

### `InteractsWithCsrf` — kernel `CsrfTokenLayer`
Mints tokens bound to a per-client binding cookie (`CSRF_BIND_COOKIE`, default
`csrf_bind`), stored **raw** (unencrypted) and `httpOnly` so the layer's
header-time read matches. `_csrfToken()` returns the HMAC token built from
`APP_KEY` + binding + `CSRF_LIFETIME` (default `LIFETIME = 43200`s) + the
optional `$csrfAction` scope; it returns `''` fail-closed when `APP_KEY` is
missing. `ViewController::view()` injects it as `$data['csrf']`.

> The binding cookie MUST be listed in the Cookie plugin's `encrypt_exempt`, and
> `CSRF_LIFETIME` MUST match the `CsrfTokenLayer` lifetime in `withSecurity([...])`.
> See `docs/ai-context/21_CSRF.md`.

### `InteractsWithProject` — `DomainContext`
Reads the context off the request (`Request::attribute('domain')` — never the
container): `project()`, `requireProject()`, `projectName()`, `projectPath()`,
`projectFace()`, `projectHost()`, `isAdmin()`, `isApi()`, `isProject()`,
`isPublic()`, `isPlatformOnly()`, `projectFeatures()`, `hasFeature()`,
`feature()`. Read helpers degrade to `null`/`false`/`[]` when no context is
attached (CLI/worker); `requireProject()` throws `KernelException`.

### `InteractsWithStorage` — `StoragePort`
`storage()`, `storageAvailable()`, `storeUpload()` (random name),
`storeUploadAs()` (keeps the client name), `storeBase64()`, `storeContents()`,
`readFile()`, `fileExists()`, `fileUrl()` (signed temporary URL), `deleteFile()`,
`copyFile()`, `moveFile()`. Read helpers return `null`/`false` when Storage is
absent; **write helpers throw** — a missing backing store is a real fault. A
route using these declares `"requires": ["storage.local"]`.

### `InteractsWithAuth` — lightweight `Identity` projection
`guard()`, `identity()`, `authCheck()`, `authId()`, `tokenCan($scope)`. Read-only
view of who the caller is; no user record is loaded.

### `InteractsWithAuthManager` — full `AuthManager`
`authManager()`, `auth(?string $guard)` (a `GuardAccessor`), `authUser(?string $guard)`
(an `Authenticatable`). Use this when you need the actual user model, named
guards, or `attempt()/login()/logout()` — not just the identity projection.

### `InteractsWithSeo`
`siteBaseUrl()`, `routeCatalog()`, `sitemap()`, `sitemapFromRoutes()`,
`openGraph($type, ?$title)`, `ogImage($url, $w, $h, $alt)`, `richGraph()`,
`robots($encoding)`.

### `InteractsWithGraphSeo`
Composes `InteractsWithSeo` and adds `graph()`, `seoHead()`, `seoFor(...)` and
`seoPrivate($title)` (a `noindex` head for authenticated pages). See
[`../../Support/Seo/README.md`](../../Support/Seo/README.md).

---

## Rules

```
✓ Extend ApiController for JSON, ViewController for HTML — one envelope shape per surface.
✓ RequestAware actions take ROUTE PARAMS only; reach the request via $this->request.
✓ Call regenerateSession() after login and invalidateSession() on logout.
✓ Declare the plugins a controller's helpers need in the route's requires[] (view.rendering, storage.local, …).
✗ Adding $request to a RequestAware action signature — it receives route params only.
✗ Business logic in a controller — 3 lines: DTO → service contract → Response.
✗ Calling CookieJar::applyTo() manually — QueuedCookiesStage already flushes queued cookies.
✗ Coupling the kernel to these bases — RequestAware is the only sanctioned seam.
✗ Injecting a Repository into a controller — controllers talk to published service contracts only.
```
