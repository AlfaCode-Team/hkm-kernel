<?php
/**
 * Feedback demo (user::account/feedback).
 *
 * Demonstrates CRUD over the feedback resource:
 *   CREATE  POST  /ajx/feedback
 *   READ    GET   /ajx/feedback            (admin triage list)
 *   READ    GET   /ajx/feedback/{id}       (self or feedback:manage)
 *   UPDATE  PATCH /ajx/feedback/{id}       (feedback:manage — status)
 *
 * Same-site session cookie + CSRF header on unsafe requests.
 */
?>
<div class="card" style="margin-bottom:1.5rem">
    <h2>Submit feedback</h2>
    <p class="muted">POST <code>/ajx/feedback</code></p>
    <form id="create-form">
        <label>Category</label>
        <select name="category">
            <option value="">(none)</option>
            <option>search_browsing</option><option>messaging</option><option>payments</option>
            <option>hosting</option><option>app_performance</option><option>feature_request</option><option>other</option>
        </select>
        <label>Rating (1–5, optional)</label><input name="rating" type="number" min="1" max="5">
        <label>Message</label><input name="message" placeholder="Tell us what you think">
        <div class="field-error" data-err="create"></div>
        <div class="actions"><button class="btn btn-primary">Send feedback</button></div>
    </form>
</div>

<div class="card">
    <div style="display:flex;align-items:center;gap:1rem">
        <h2 style="margin:0">Triage <span class="muted">(admin)</span></h2>
        <button class="btn btn-sm" id="reload" style="margin-left:auto">Reload</button>
    </div>
    <p class="muted">GET <code>/ajx/feedback</code> · PATCH <code>/ajx/feedback/{id}</code></p>
    <table>
        <thead><tr><th>ID</th><th>User</th><th>Category</th><th>Rating</th><th>Status</th><th></th></tr></thead>
        <tbody id="rows"><tr><td colspan="6" class="muted">Loading…</td></tr></tbody>
    </table>
</div>

<script>
(function () {
    const SAFE = { GET:1, HEAD:1, OPTIONS:1 };
    function csrf() { const m = document.querySelector('meta[name="csrf-token"]'); return m ? m.content : ''; }
    async function api(method, path, body) {
        const h = { 'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest' };
        if (body !== undefined) h['Content-Type'] = 'application/json';
        if (!SAFE[method]) h['X-CSRF-Token'] = csrf();
        const res = await fetch(path, { method, headers:h, credentials:'same-origin',
            body: body !== undefined ? JSON.stringify(body) : undefined });
        const t = await res.text(); const d = t ? JSON.parse(t) : null;
        if (!res.ok) { const e = new Error((d&&d.error&&d.error.message)||('HTTP '+res.status)); e.status=res.status; e.fields=(d&&d.error&&d.error.fields)||{}; throw e; }
        return d;
    }
    const flash = (m, t='ok') => window.UserApp && window.UserApp.flash(m, t);
    const NEXT = { received:'acknowledged', acknowledged:'resolved' };

    // CREATE
    const form = document.getElementById('create-form');
    form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const payload = {
            category: form.elements.category.value || null,
            rating:   form.elements.rating.value ? Number(form.elements.rating.value) : null,
            message:  form.elements.message.value,
        };
        try {
            await api('POST', '/ajx/feedback', payload);
            form.reset(); flash('Feedback submitted'); load();
        } catch (e) {
            form.querySelector('[data-err="create"]').textContent = Object.values(e.fields)[0] || e.message;
            flash(e.message, 'error');
        }
    });

    // READ (list) + UPDATE (advance status)
    const rows = document.getElementById('rows');
    async function load() {
        rows.innerHTML = '<tr><td colspan="6" class="muted">Loading…</td></tr>';
        try {
            const res = await api('GET', '/ajx/feedback');
            const items = res.data || [];
            if (!items.length) { rows.innerHTML = '<tr><td colspan="6" class="muted">No feedback.</td></tr>'; return; }
            rows.innerHTML = '';
            for (const f of items) {
                const tr = document.createElement('tr');
                tr.innerHTML =
                    '<td class="muted">' + f.feedbackId.slice(0, 8) + '…</td>' +
                    '<td>' + f.userId + '</td>' +
                    '<td>' + (f.category || '—') + '</td>' +
                    '<td>' + (f.rating ?? '—') + '</td>' +
                    '<td><span class="badge' + (f.status === 'resolved' ? ' active' : '') + '">' + f.status + '</span></td>';
                const act = document.createElement('td');
                if (NEXT[f.status]) {
                    const b = document.createElement('button');
                    b.className = 'btn btn-sm'; b.textContent = '→ ' + NEXT[f.status];
                    b.addEventListener('click', () => advance(f.feedbackId, NEXT[f.status]));
                    act.appendChild(b);
                }
                tr.appendChild(act); rows.appendChild(tr);
            }
        } catch (e) {
            rows.innerHTML = '<tr><td colspan="6" class="alert error">' + e.message + '</td></tr>';
        }
    }
    async function advance(id, status) {
        try { await api('PATCH', '/ajx/feedback/' + encodeURIComponent(id), { status }); flash('Status → ' + status); load(); }
        catch (e) { flash(e.message, 'error'); }
    }

    document.getElementById('reload').addEventListener('click', load);
    load();
})();
</script>
