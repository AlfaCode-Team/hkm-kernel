<?php
/**
 * User UI layout (resolved as user::layouts/app).
 *
 * Receives:
 *   @var string $title    Page title.
 *   @var string $apiBase  Base path of the JSON API (e.g. /ajx/users).
 *   @var string $csrf     CSRF token (HMAC, bound to the session cookie).
 *   @var string $view     Rendered child-view HTML (injected by the renderer).
 */
$title   = $title   ?? 'Users';
$apiBase = $apiBase ?? '/ajx/users';
$csrf    = $csrf    ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> · User</title>
    <style>
        :root { --bg:#0f172a; --card:#1e293b; --line:#334155; --fg:#e2e8f0; --muted:#94a3b8;
                --accent:#6366f1; --danger:#ef4444; --ok:#22c55e; }
        * { box-sizing:border-box; }
        body { margin:0; font:15px/1.5 system-ui,sans-serif; background:var(--bg); color:var(--fg); }
        header { display:flex; align-items:center; gap:1rem; padding:1rem 1.5rem;
                 border-bottom:1px solid var(--line); background:var(--card); }
        header h1 { font-size:1.1rem; margin:0; }
        header nav { display:flex; gap:.75rem; margin-left:auto; }
        a { color:var(--accent); text-decoration:none; }
        a:hover { text-decoration:underline; }
        main { max-width:880px; margin:2rem auto; padding:0 1.5rem; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:1.5rem; }
        h2 { margin-top:0; }
        table { width:100%; border-collapse:collapse; }
        th,td { text-align:left; padding:.6rem .5rem; border-bottom:1px solid var(--line); }
        th { color:var(--muted); font-weight:600; font-size:.8rem; text-transform:uppercase; letter-spacing:.04em; }
        .btn { display:inline-block; padding:.5rem .9rem; border-radius:8px; border:1px solid var(--line);
               background:transparent; color:var(--fg); cursor:pointer; font:inherit; }
        .btn:hover { border-color:var(--accent); }
        .btn-primary { background:var(--accent); border-color:var(--accent); color:#fff; }
        .btn-danger { color:var(--danger); border-color:var(--danger); }
        .btn-sm { padding:.3rem .6rem; font-size:.85rem; }
        label { display:block; margin:.9rem 0 .3rem; color:var(--muted); font-size:.85rem; }
        input { width:100%; padding:.6rem .7rem; border-radius:8px; border:1px solid var(--line);
                background:var(--bg); color:var(--fg); font:inherit; }
        .field-error { color:var(--danger); font-size:.8rem; margin-top:.25rem; min-height:1em; }
        .actions { margin-top:1.25rem; display:flex; gap:.6rem; }
        .badge { padding:.15rem .5rem; border-radius:999px; font-size:.75rem; border:1px solid var(--line); }
        .badge.active { color:var(--ok); border-color:var(--ok); }
        #flash { margin-bottom:1rem; }
        .alert { padding:.7rem 1rem; border-radius:8px; border:1px solid; }
        .alert.error { color:var(--danger); border-color:var(--danger); background:rgba(239,68,68,.08); }
        .alert.ok { color:var(--ok); border-color:var(--ok); background:rgba(34,197,94,.08); }
        .muted { color:var(--muted); }
    </style>
</head>
<body>
    <header>
        <h1>User Management</h1>
        <nav>
            <a href="/users">All users</a>
            <a href="/users/create">Create</a>
            <a href="/account/settings">Settings</a>
        </nav>
    </header>

    <main>
        <div id="flash"></div>
        <?= $view ?? '' ?>
    </main>

    <script>
    // Shared AJAX client for the user API. Same-site: the browser sends the
    // session cookie automatically (credentials:'same-origin'), so there is no
    // bearer token. Every UNSAFE request (POST/PUT/PATCH/DELETE) carries the
    // CSRF token from the <meta> tag in the X-CSRF-Token header.
    window.UserApp = (function () {
        const apiBase = <?= json_encode($apiBase, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const SAFE = { GET: 1, HEAD: 1, OPTIONS: 1 };

        function csrf() {
            const m = document.querySelector('meta[name="csrf-token"]');
            return m ? m.getAttribute('content') || '' : '';
        }

        function headers(method, json) {
            const h = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
            if (json) h['Content-Type'] = 'application/json';
            if (!SAFE[method]) h['X-CSRF-Token'] = csrf();
            return h;
        }

        async function request(method, path, body) {
            const res = await fetch(apiBase + path, {
                method,
                headers: headers(method, body !== undefined),
                body: body !== undefined ? JSON.stringify(body) : undefined,
                credentials: 'same-origin', // send the session cookie
            });
            const text = await res.text();
            const data = text ? JSON.parse(text) : null;
            if (!res.ok) {
                const err = new Error((data && data.error && data.error.message) || ('HTTP ' + res.status));
                err.status = res.status;
                err.fields = (data && data.error && data.error.fields) || {};
                throw err;
            }
            return data;
        }

        function flash(message, type = 'ok') {
            const el = document.getElementById('flash');
            if (!el) return;
            el.innerHTML = '<div class="alert ' + type + '"></div>';
            el.firstChild.textContent = message;
        }

        return {
            list:     () => request('GET', ''),
            get:      (id) => request('GET', '/' + encodeURIComponent(id)),
            create:   (payload) => request('POST', '', payload),
            update:   (id, payload) => request('PUT', '/' + encodeURIComponent(id), payload),
            remove:   (id) => request('DELETE', '/' + encodeURIComponent(id)),
            csrf, flash,
        };
    })();
    </script>
</body>
</html>
