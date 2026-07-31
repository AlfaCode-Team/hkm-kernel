<?php
/**
 * OAuth2 admin dashboard (server-rendered). Self-contained HTML/CSS/JS — its
 * JavaScript calls the /oauth/admin/* JSON API same-origin, authenticated by the
 * browser session cookie. Mirrors the React /admin console's layout.
 *
 * @var string $userId Authenticated admin user id.
 * @var string $email  Authenticated admin email (best-effort).
 */
$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>OAuth2 Admin</title>
    <style>
        :root {
            --bg:#f6f7f9; --card:#fff; --fg:#0f172a; --muted:#64748b; --border:#e2e8f0;
            --primary:#4f46e5; --primary-fg:#fff; --danger:#dc2626; --ok:#059669;
            --radius:12px; --shadow:0 1px 3px rgba(15,23,42,.06),0 1px 2px rgba(15,23,42,.04);
        }
        @media (prefers-color-scheme: dark) {
            :root { --bg:#0b1120; --card:#0f172a; --fg:#e2e8f0; --muted:#94a3b8; --border:#1e293b;
                    --primary:#6366f1; --danger:#f87171; --ok:#34d399; }
        }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--bg); color:var(--fg);
               font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif; }
        .wrap { max-width:1080px; margin:0 auto; padding:2.5rem 1.5rem; }
        code { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.85em; }
        header { display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; flex-wrap:wrap; }
        .brand { display:flex; gap:.75rem; align-items:flex-start; }
        .logo { width:40px; height:40px; border-radius:12px; display:flex; align-items:center; justify-content:center;
                background:color-mix(in srgb,var(--primary) 15%,transparent); font-size:1.25rem; }
        h1 { margin:0; font-size:1.5rem; letter-spacing:-.01em; }
        .sub { margin:.25rem 0 0; color:var(--muted); font-size:.9rem; }
        .statusbar { margin:1.25rem 0; display:flex; gap:1.5rem; flex-wrap:wrap; padding:.75rem 1rem;
                     background:color-mix(in srgb,var(--muted) 8%,transparent); border:1px solid var(--border);
                     border-radius:var(--radius); font-size:.8rem; color:var(--muted); align-items:center; }
        .tabs { display:flex; gap:.25rem; border-bottom:1px solid var(--border); margin-bottom:1.25rem; }
        .tab { padding:.6rem 1rem; border:0; background:none; color:var(--muted); font-size:.9rem; font-weight:600;
               cursor:pointer; border-bottom:2px solid transparent; }
        .tab.active { color:var(--fg); border-bottom-color:var(--primary); }
        .card { background:var(--card); border:1px solid var(--border); border-radius:var(--radius);
                box-shadow:var(--shadow); overflow:hidden; }
        .card-head { display:flex; justify-content:space-between; align-items:center; gap:1rem;
                     padding:1.1rem 1.25rem; border-bottom:1px solid var(--border); }
        .card-head h2 { margin:0; font-size:1rem; }
        .card-head p { margin:.15rem 0 0; color:var(--muted); font-size:.8rem; }
        .card-body { padding:1.25rem; }
        table { width:100%; border-collapse:collapse; font-size:.85rem; }
        th { text-align:left; color:var(--muted); font-weight:600; font-size:.72rem; text-transform:uppercase;
             letter-spacing:.04em; padding:.5rem .6rem; }
        td { padding:.6rem .6rem; border-top:1px solid var(--border); vertical-align:middle; }
        .mono { font-family:ui-monospace,Menlo,monospace; font-size:.78rem; }
        .trunc { max-width:11rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .muted { color:var(--muted); }
        .badge { display:inline-flex; align-items:center; padding:.1rem .5rem; border-radius:999px;
                 font-size:.72rem; font-weight:600; border:1px solid transparent; }
        .badge.primary { background:color-mix(in srgb,var(--primary) 14%,transparent); color:var(--primary); }
        .badge.grey { background:color-mix(in srgb,var(--muted) 16%,transparent); color:var(--muted); }
        .badge.ok { background:color-mix(in srgb,var(--ok) 14%,transparent); color:var(--ok); }
        .badge.danger { background:color-mix(in srgb,var(--danger) 14%,transparent); color:var(--danger); }
        button.btn { font:inherit; cursor:pointer; border-radius:8px; border:1px solid var(--border);
                     background:var(--card); color:var(--fg); padding:.45rem .8rem; font-size:.82rem; font-weight:600; }
        button.btn:hover { background:color-mix(in srgb,var(--muted) 8%,transparent); }
        button.btn.primary { background:var(--primary); color:var(--primary-fg); border-color:transparent; }
        button.btn.primary:hover { filter:brightness(1.05); }
        button.btn.sm { padding:.3rem .55rem; font-size:.78rem; }
        button.link { border:0; background:none; cursor:pointer; font:inherit; font-size:.8rem; font-weight:600;
                      color:var(--primary); padding:.2rem .35rem; }
        button.link.danger { color:var(--danger); }
        .row-form { display:flex; gap:.75rem; align-items:flex-end; flex-wrap:wrap; padding:1rem;
                    border:1px solid var(--border); border-radius:10px; margin-bottom:1rem; }
        .field { display:flex; flex-direction:column; gap:.3rem; }
        .field label { font-size:.72rem; font-weight:600; color:var(--muted); text-transform:uppercase; letter-spacing:.03em; }
        .field input { font:inherit; padding:.5rem .65rem; border:1px solid var(--border); border-radius:8px;
                       background:var(--bg); color:var(--fg); }
        .list-row { display:flex; justify-content:space-between; align-items:center; padding:.6rem .9rem;
                    border-top:1px solid var(--border); font-size:.85rem; }
        .empty { padding:2rem; text-align:center; color:var(--muted); font-size:.85rem; }
        .banner { padding:1rem 1.25rem; border-radius:var(--radius); margin-bottom:1.25rem; font-size:.85rem;
                  background:color-mix(in srgb,#f59e0b 12%,transparent); border:1px solid color-mix(in srgb,#f59e0b 40%,transparent);
                  color:#b45309; display:none; }
        /* modal */
        .modal-bg { position:fixed; inset:0; background:rgba(2,6,23,.55); display:none; align-items:center;
                    justify-content:center; padding:1rem; z-index:50; }
        .modal { background:var(--card); border:1px solid var(--border); border-radius:var(--radius);
                 width:100%; max-width:460px; box-shadow:0 10px 40px rgba(2,6,23,.35); }
        .modal-head { padding:1.1rem 1.25rem .25rem; }
        .modal-head h3 { margin:0; font-size:1.05rem; }
        .modal-head p { margin:.25rem 0 0; color:var(--muted); font-size:.8rem; }
        .modal-body { padding:1rem 1.25rem; display:flex; flex-direction:column; gap:.9rem; }
        .modal-foot { padding:.5rem 1.25rem 1.25rem; display:flex; justify-content:flex-end; gap:.5rem; }
        .switch { display:flex; justify-content:space-between; align-items:center; padding:.75rem;
                  border:1px solid var(--border); border-radius:8px; }
        /* toast */
        #toasts { position:fixed; top:1rem; right:1rem; display:flex; flex-direction:column; gap:.5rem; z-index:100; }
        .toast { background:var(--card); border:1px solid var(--border); border-left:3px solid var(--primary);
                 border-radius:8px; padding:.6rem .9rem; font-size:.82rem; box-shadow:var(--shadow); max-width:320px;
                 animation:slide .2s ease; }
        .toast.ok { border-left-color:var(--ok); } .toast.err { border-left-color:var(--danger); }
        .toast small { display:block; color:var(--muted); font-family:ui-monospace,monospace; word-break:break-all; margin-top:.2rem; }
        @keyframes slide { from{opacity:0;transform:translateX(10px)} to{opacity:1;transform:none} }
        .spin { animation:spin 1s linear infinite; display:inline-block; }
        @keyframes spin { to { transform:rotate(360deg) } }
    </style>
</head>
<body>
<div id="toasts"></div>
<div class="wrap">
    <header>
        <div class="brand">
            <div class="logo">🛡</div>
            <div>
                <h1>OAuth2 Admin</h1>
                <p class="sub">Tenant-wide administration — all clients, scopes and authorized grants.</p>
            </div>
        </div>
        <div class="muted" style="font-size:.8rem;text-align:right">
            signed in as <strong><?= $e($email !== '' ? $email : $userId) ?></strong><br>
            <a href="/logout" class="mono" style="color:var(--muted)">logout</a>
        </div>
    </header>

    <div class="statusbar">
        <span>host <code id="host"></code></span>
        <span>user <code><?= $e($userId) ?></code></span>
        <button class="btn sm" onclick="reloadAll()">↻ Reload all</button>
    </div>

    <div class="banner" id="banner"></div>

    <div class="tabs">
        <button class="tab active" data-tab="clients" onclick="showTab('clients')">Clients</button>
        <button class="tab" data-tab="scopes" onclick="showTab('scopes')">Scopes</button>
        <button class="tab" data-tab="grants" onclick="showTab('grants')">Grants</button>
    </div>

    <!-- Clients -->
    <section data-panel="clients" class="card">
        <div class="card-head">
            <div><h2>Clients</h2><p>Every OAuth client registered in this tenant.</p></div>
            <div style="display:flex;gap:.5rem">
                <button class="btn primary sm" onclick="openCreate()">+ New client</button>
                <button class="btn sm" onclick="loadClients()">↻</button>
            </div>
        </div>
        <div class="card-body">
            <table>
                <thead><tr><th>Name</th><th>client_id</th><th>Owner</th><th>Type</th><th>Scopes</th><th>Status</th><th></th></tr></thead>
                <tbody id="clients-body"><tr><td colspan="7" class="empty">Loading…</td></tr></tbody>
            </table>
        </div>
    </section>

    <!-- Scopes -->
    <section data-panel="scopes" class="card" style="display:none">
        <div class="card-head">
            <div><h2>Scope catalogue</h2><p>Grantable scopes shown on consent and validated at /authorize.</p></div>
            <button class="btn sm" onclick="loadScopes()">↻</button>
        </div>
        <div class="card-body">
            <div class="row-form">
                <div class="field" style="width:9rem"><label>scope id</label><input id="scope-id" placeholder="read"></div>
                <div class="field" style="flex:1"><label>description</label><input id="scope-desc" placeholder="Read your data"></div>
                <button class="btn primary" onclick="addScope()">+ Add</button>
            </div>
            <div class="card" style="box-shadow:none"><div id="scopes-body"><div class="empty">Loading…</div></div></div>
        </div>
    </section>

    <!-- Grants -->
    <section data-panel="grants" class="card" style="display:none">
        <div class="card-head">
            <div><h2>Authorized grants</h2><p>Every active refresh-token grant across all users.</p></div>
            <button class="btn sm" onclick="loadGrants()">↻</button>
        </div>
        <div class="card-body">
            <table>
                <thead><tr><th>grant id</th><th>client</th><th>user</th><th>scopes</th><th>expires</th><th></th></tr></thead>
                <tbody id="grants-body"><tr><td colspan="6" class="empty">Loading…</td></tr></tbody>
            </table>
        </div>
    </section>
</div>

<!-- Create client modal -->
<div class="modal-bg" id="create-bg">
    <div class="modal">
        <div class="modal-head"><h3>Register OAuth client</h3><p>Created in the current tenant. Confidential secrets are shown once.</p></div>
        <div class="modal-body">
            <div class="field"><label>Name</label><input id="nc-name" value="Admin-created client"></div>
            <div class="field"><label>redirect_uri</label><input id="nc-redirect" class="mono"></div>
            <div class="field"><label>Scopes (space-separated)</label><input id="nc-scopes" class="mono" value="">
                <span class="muted" style="font-size:.72rem">Every scope must exist in the catalogue.</span></div>
            <div class="field"><label>Grant types</label>
                <div id="nc-grants" style="display:grid;grid-template-columns:1fr 1fr;gap:.35rem;font-size:.82rem">
                    <label><input type="checkbox" value="authorization_code" checked> authorization_code</label>
                    <label><input type="checkbox" value="refresh_token" checked> refresh_token</label>
                    <label><input type="checkbox" value="client_credentials"> client_credentials</label>
                    <label><input type="checkbox" value="password"> password</label>
                    <label style="grid-column:1/-1"><input type="checkbox" value="urn:ietf:params:oauth:grant-type:device_code"> device_code</label>
                </div>
            </div>
            <label class="switch"><span>Public client (PKCE) — off = confidential</span><input type="checkbox" id="nc-public" checked></label>
        </div>
        <div class="modal-foot">
            <button class="btn" onclick="closeCreate()">Cancel</button>
            <button class="btn primary" id="nc-submit" onclick="createClient()">Create client</button>
        </div>
    </div>
</div>

<script>
const RETURN = encodeURIComponent('/oauth/admin');
document.getElementById('host').textContent = location.host;

function toast(msg, type, detail) {
    const el = document.createElement('div');
    el.className = 'toast ' + (type || '');
    el.innerHTML = '<span></span>';
    el.querySelector('span').textContent = msg;
    if (detail) { const s = document.createElement('small'); s.textContent = detail; el.appendChild(s); }
    document.getElementById('toasts').appendChild(el);
    setTimeout(() => el.remove(), 6000);
}

async function api(path, opts) {
    opts = opts || {};
    const headers = { 'Accept': 'application/json' };
    if (opts.body) headers['Content-Type'] = 'application/json';
    const res = await fetch(path, { credentials: 'same-origin', headers, ...opts });
    if (res.status === 401) { location.href = '/login?return=' + RETURN; throw new Error('unauthorized'); }
    if (res.status === 403) { document.getElementById('banner').style.display = 'block';
        document.getElementById('banner').textContent = '403 — your account is not an OAuth2 admin. Add user id ' +
            <?= json_encode($userId) ?> + ' to OAUTH_ADMIN_USERS in the backend .env.'; }
    const text = await res.text();
    let body = null; try { body = text ? JSON.parse(text) : null; } catch (_) { body = text; }
    if (!res.ok) {
        const msg = (body && body.error && (body.error.message || body.error)) || (body && body.message) || ('HTTP ' + res.status);
        const err = new Error(msg); err.status = res.status; throw err;
    }
    return body;
}
const pick = (body, key) => (body && body.data && body.data[key]) || (body && body[key]) || [];

function esc(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

// ── tabs ──
function showTab(name) {
    document.querySelectorAll('[data-tab]').forEach(t => t.classList.toggle('active', t.dataset.tab === name));
    document.querySelectorAll('[data-panel]').forEach(p => p.style.display = p.dataset.panel === name ? '' : 'none');
}

// ── clients ──
function ownerCell(c) {
    const o = c.owner;
    if (!o && !c.owner_id) return '<span class="muted">—</span>';
    const name = (o && (o.full_name || o.username)) || c.owner_id || '—';
    const email = o && o.email;
    const avatar = o && o.avatar_url;
    const initials = (((o && (o.full_name || o.username || o.email)) || c.owner_id || '?') + '').slice(0, 2).toUpperCase();
    const av = avatar
        ? `<img src="${esc(avatar)}" alt="" style="width:28px;height:28px;border-radius:50%;object-fit:cover;flex:0 0 auto">`
        : `<span class="muted" style="width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.65rem;font-weight:700;flex:0 0 auto;background:color-mix(in srgb,var(--muted) 18%,transparent)">${esc(initials)}</span>`;
    return `<div style="display:flex;align-items:center;gap:.5rem">${av}<div style="min-width:0;line-height:1.15">
        <div style="font-weight:600;font-size:.8rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(name)}</div>
        ${email ? `<div class="muted" style="font-size:.72rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(email)}</div>` : ''}
    </div></div>`;
}
async function loadClients() {
    const body = document.getElementById('clients-body');
    try {
        const clients = pick(await api('/oauth/admin/clients'), 'clients');
        if (!clients.length) { body.innerHTML = '<tr><td colspan="7" class="empty">No clients yet.</td></tr>'; return; }
        body.innerHTML = clients.map(c => `
            <tr>
                <td><strong>${esc(c.name)}</strong></td>
                <td class="mono trunc" title="${esc(c.id)}">${esc(c.id)}</td>
                <td>${ownerCell(c)}</td>
                <td><span class="badge ${c.confidential ? 'primary' : 'grey'}">${c.confidential ? 'confidential' : 'public'}</span></td>
                <td class="muted trunc">${esc((c.scopes || []).join(' ') || '—')}</td>
                <td>${c.revoked ? '<span class="badge danger">revoked</span>' : '<span class="badge ok">active</span>'}</td>
                <td style="text-align:right;white-space:nowrap">
                    ${c.confidential ? `<button class="link" onclick="rotateClient('${esc(c.id)}')">rotate</button>` : ''}
                    ${!c.revoked ? `<button class="link danger" onclick="revokeClient('${esc(c.id)}','${esc(c.name)}')">revoke</button>` : ''}
                </td>
            </tr>`).join('');
    } catch (e) { if (e.status !== 401) toast(e.message, 'err'); body.innerHTML = '<tr><td colspan="7" class="empty">—</td></tr>'; }
}

async function rotateClient(id) {
    try {
        const r = await api('/oauth/admin/clients/' + encodeURIComponent(id) + '/rotate', { method: 'POST' });
        const secret = (r.data || r).client_secret;
        try { await navigator.clipboard.writeText(secret); } catch (_) {}
        toast('Secret rotated — copied', 'ok', secret);
    } catch (e) { if (e.status !== 401) toast(e.message, 'err'); }
}
async function revokeClient(id, name) {
    if (!confirm('Revoke client "' + name + '"?')) return;
    try { await api('/oauth/admin/clients/' + encodeURIComponent(id), { method: 'DELETE' }); toast('Revoked "' + name + '"', 'ok'); loadClients(); }
    catch (e) { if (e.status !== 401) toast(e.message, 'err'); }
}

function openCreate() { document.getElementById('nc-redirect').value = location.origin + '/oauth/callback';
    document.getElementById('create-bg').style.display = 'flex'; }
function closeCreate() { document.getElementById('create-bg').style.display = 'none'; }
async function createClient() {
    const btn = document.getElementById('nc-submit'); btn.disabled = true; btn.textContent = 'Creating…';
    const name = document.getElementById('nc-name').value.trim();
    const redirect = document.getElementById('nc-redirect').value.trim();
    const scopes = document.getElementById('nc-scopes').value.trim();
    const grants = Array.from(document.querySelectorAll('#nc-grants input:checked')).map(i => i.value);
    const isPublic = document.getElementById('nc-public').checked;
    try {
        const r = await api('/oauth/admin/clients', { method: 'POST', body: JSON.stringify({
            name, redirect_uris: redirect ? [redirect] : [], scopes: scopes ? scopes.split(/\s+/) : [],
            grant_types: grants, public: isPublic }) });
        const c = r.data || r;
        if (c.client_secret) { try { await navigator.clipboard.writeText(c.client_secret); } catch (_) {} toast('client_secret copied (shown once)', 'ok', c.client_secret); }
        toast('Created "' + name + '"', 'ok');
        closeCreate(); loadClients();
    } catch (e) { if (e.status !== 401) toast(e.message, 'err'); }
    finally { btn.disabled = false; btn.textContent = 'Create client'; }
}

// ── scopes ──
async function loadScopes() {
    const el = document.getElementById('scopes-body');
    try {
        const scopes = pick(await api('/oauth/admin/scopes'), 'scopes');
        if (!scopes.length) { el.innerHTML = '<div class="empty">No scopes registered.</div>'; return; }
        el.innerHTML = scopes.map(s => `<div class="list-row">
            <span><code>${esc(s.id)}</code> <span class="muted">— ${esc(s.description || 'no description')}</span></span>
            <button class="link danger" onclick="delScope('${esc(s.id)}')">delete</button></div>`).join('');
    } catch (e) { if (e.status !== 401) toast(e.message, 'err'); el.innerHTML = '<div class="empty">—</div>'; }
}
async function addScope() {
    const id = document.getElementById('scope-id').value.trim();
    const desc = document.getElementById('scope-desc').value.trim();
    if (!id) return;
    try { await api('/oauth/admin/scopes', { method: 'POST', body: JSON.stringify({ id, description: desc }) });
        document.getElementById('scope-id').value = ''; document.getElementById('scope-desc').value = '';
        toast('Added scope "' + id + '"', 'ok'); loadScopes(); }
    catch (e) { if (e.status !== 401) toast(e.message, 'err'); }
}
async function delScope(id) {
    try { await api('/oauth/admin/scopes/' + encodeURIComponent(id), { method: 'DELETE' }); toast('Deleted "' + id + '"', 'ok'); loadScopes(); }
    catch (e) { if (e.status !== 401) toast(e.message, 'err'); }
}

// ── grants ──
async function loadGrants() {
    const body = document.getElementById('grants-body');
    try {
        const tokens = pick(await api('/oauth/admin/authorized-tokens'), 'authorized_tokens');
        if (!tokens.length) { body.innerHTML = '<tr><td colspan="6" class="empty">No active grants.</td></tr>'; return; }
        body.innerHTML = tokens.map(t => `<tr>
            <td class="mono trunc" title="${esc(t.id)}">${esc(t.id)}</td>
            <td class="mono">${esc(t.client_id)}</td>
            <td class="mono">${esc(t.user_id)}</td>
            <td class="muted">${esc((t.scopes || []).join(' ') || '—')}</td>
            <td class="muted">${esc(t.expires_at)}</td>
            <td style="text-align:right"><button class="link danger" onclick="revokeGrant('${esc(t.id)}')">revoke</button></td></tr>`).join('');
    } catch (e) { if (e.status !== 401) toast(e.message, 'err'); body.innerHTML = '<tr><td colspan="6" class="empty">—</td></tr>'; }
}
async function revokeGrant(id) {
    try { await api('/oauth/admin/authorized-tokens/' + encodeURIComponent(id), { method: 'DELETE' }); toast('Grant revoked', 'ok'); loadGrants(); }
    catch (e) { if (e.status !== 401) toast(e.message, 'err'); }
}

function reloadAll() { loadClients(); loadScopes(); loadGrants(); }
reloadAll();
</script>
</body>
</html>
