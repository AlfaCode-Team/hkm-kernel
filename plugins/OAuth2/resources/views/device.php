<?php
/**
 * Device verification page (RFC 8628).
 *
 * @var string $csrf
 * @var string $userCode  Prefilled / submitted user code.
 * @var string $message   Result/error message (empty on first render).
 */
$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php /* Device-code entry is a transactional auth surface — never indexable. */ ?>
    <meta name="robots" content="noindex, nofollow">
    <title>Connect a device</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f5f5f7; margin: 0; padding: 2rem; }
        .card { max-width: 420px; margin: 4rem auto; background: #fff; border-radius: 12px;
                box-shadow: 0 2px 16px rgba(0,0,0,.08); padding: 2rem; }
        h1 { font-size: 1.25rem; }
        input[type=text] { width: 100%; padding: .75rem; font-size: 1.25rem; letter-spacing: .15em;
                           text-align: center; border: 1px solid #ccc; border-radius: 8px; box-sizing: border-box; }
        .actions { display: flex; gap: .75rem; margin-top: 1.25rem; }
        button { flex: 1; padding: .75rem; border: 0; border-radius: 8px; font-size: 1rem; cursor: pointer; }
        .approve { background: #2563eb; color: #fff; }
        .deny { background: #e5e7eb; color: #111; }
        .msg { margin-top: 1rem; padding: .75rem; border-radius: 8px; background: #eef2ff; color: #1e3a8a; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Connect a device</h1>
        <p>Enter the code shown on your device.</p>

        <?php if ($message !== ''): ?>
            <div class="msg"><?= $e($message) ?></div>
        <?php endif; ?>

        <form method="post" action="/oauth/device">
            <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
            <input type="text" name="user_code" value="<?= $e($userCode) ?>" placeholder="XXXX-XXXX" autofocus>
            <div class="actions">
                <button class="deny" type="submit" name="action" value="deny">Deny</button>
                <button class="approve" type="submit" name="action" value="approve">Approve</button>
            </div>
        </form>
    </div>
</body>
</html>
