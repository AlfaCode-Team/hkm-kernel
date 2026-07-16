<?php
/**
 * OAuth2 consent screen.
 *
 * @var string             $csrf       Session CSRF token.
 * @var string             $clientName Requesting application's display name.
 * @var array<int,string>  $scopes     Requested scopes.
 * @var string             $authzId    Opaque reference to the server-stored request.
 */
$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php /* Consent screens are transactional auth surfaces — never indexable. */ ?>
    <meta name="robots" content="noindex, nofollow">
    <title>Authorize <?= $e($clientName) ?></title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f5f5f7; margin: 0; padding: 2rem; }
        .card { max-width: 420px; margin: 4rem auto; background: #fff; border-radius: 12px;
                box-shadow: 0 2px 16px rgba(0,0,0,.08); padding: 2rem; }
        h1 { font-size: 1.25rem; margin: 0 0 .5rem; }
        p { color: #555; }
        ul { background: #fafafa; border-radius: 8px; padding: 1rem 1rem 1rem 2rem; }
        li { margin: .25rem 0; }
        .actions { display: flex; gap: .75rem; margin-top: 1.5rem; }
        button { flex: 1; padding: .75rem; border: 0; border-radius: 8px; font-size: 1rem; cursor: pointer; }
        .approve { background: #2563eb; color: #fff; }
        .deny { background: #e5e7eb; color: #111; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Authorize <?= $e($clientName) ?></h1>
        <p><strong><?= $e($clientName) ?></strong> is requesting access to your account.</p>

        <?php if (!empty($scopes)): ?>
            <p>It will be able to:</p>
            <ul>
                <?php foreach ($scopes as $scope): ?>
                    <li><?= $e($scope) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <form method="post" action="/oauth/authorize">
            <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
            <input type="hidden" name="authz_id" value="<?= $e($authzId) ?>">
            <div class="actions">
                <button class="deny" type="submit" name="action" value="deny">Deny</button>
                <button class="approve" type="submit" name="action" value="approve">Allow</button>
            </div>
        </form>
    </div>
</body>
</html>
