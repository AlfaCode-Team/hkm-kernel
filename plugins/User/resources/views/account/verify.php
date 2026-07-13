<?php
/**
 * Email-verification page (user::account/verify). POSTs the token to
 * /ajx/users/verify (UserController@verifyEmailByToken — unauthenticated).
 *
 * The token is prefilled from the ?token= query string when the user arrives
 * via the emailed link; otherwise they can paste it in manually.
 *
 * @var string $csrf
 * @var string $token
 */
$token = (string) ($token ?? '');
?>
<div class="card">
    <h2>Verify your email</h2>
    <p class="muted">
        Paste the verification token from your email below, or follow the link we
        sent you. Submits to <code>POST /ajx/users/verify</code>.
    </p>

    <div id="verify-result" style="display:none"></div>

    <form id="verify-form" novalidate>
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf ?? '', ENT_QUOTES, 'UTF-8') ?>">

        <label for="token">Verification token</label>
        <input id="token" name="token" autocomplete="off" required
               value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
        <div class="field-error" data-for="token"></div>

        <div class="actions">
            <button class="btn btn-primary" type="submit">Verify email</button>
            <a class="btn" href="/users">Cancel</a>
        </div>
    </form>
</div>

<script>
(function () {
    const form   = document.getElementById('verify-form');
    const result = document.getElementById('verify-result');

    function clearErrors() {
        form.querySelectorAll('.field-error').forEach(el => el.textContent = '');
    }
    function showErrors(fields) {
        for (const [name, msg] of Object.entries(fields || {})) {
            const el = form.querySelector('.field-error[data-for="' + name + '"]');
            if (el) el.textContent = Array.isArray(msg) ? msg.join(' ') : msg;
        }
    }

    async function submit(token) {
        clearErrors();
        result.style.display = 'none';
        try {
            const data = await window.UserApp.request('POST', '/verify', { token });
            result.className = 'alert ok';
            // "already_verified" is only returned for a valid token (proof of
            // inbox control), so it is safe to show the distinct message.
            result.textContent = data && data.status === 'already_verified'
                ? (data.message || 'Your email is already verified — you can sign in.')
                : 'Your email has been verified. You can now sign in.';
            result.style.display = 'block';
            form.querySelector('button[type="submit"]').disabled = true;
        } catch (e) {
            showErrors(e.fields);
            window.UserApp.flash(e.message, 'error');
        }
    }

    form.addEventListener('submit', (ev) => {
        ev.preventDefault();
        const token = form.token.value.trim();
        if (token) submit(token);
    });
})();
</script>
