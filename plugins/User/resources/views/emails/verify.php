<?php
/**
 * Email — verify your address (user::emails/verify).
 *
 * Rendered by the MailPort when a public signup is queued. `$url` is the
 * absolute verification link (host-aware, from Request::site()); the page it
 * points at POSTs the token to /ajx/users/verify.
 *
 * @var string $url
 */
$url = (string) ($url ?? '#');
?>
<!doctype html>
<html lang="en">
<body style="margin:0;padding:24px;background:#f5f5f5;font-family:Arial,Helvetica,sans-serif;color:#222;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td align="center">
                <table role="presentation" width="480" cellpadding="0" cellspacing="0"
                       style="background:#ffffff;border-radius:8px;padding:32px;">
                    <tr><td>
                        <h1 style="margin:0 0 16px;font-size:20px;">Confirm your email</h1>
                        <p style="margin:0 0 24px;line-height:1.5;">
                            Thanks for signing up. Please confirm your email address to activate
                            your account. This link expires in 24 hours.
                        </p>
                        <p style="margin:0 0 24px;">
                            <a href="<?= htmlspecialchars($url, ENT_QUOTES) ?>"
                               style="display:inline-block;background:#2563eb;color:#ffffff;
                                      text-decoration:none;padding:12px 20px;border-radius:6px;">
                                Verify email address
                            </a>
                        </p>
                        <p style="margin:0;font-size:13px;color:#666;line-height:1.5;">
                            If the button doesn't work, copy this link into your browser:<br>
                            <a href="<?= htmlspecialchars($url, ENT_QUOTES) ?>" style="color:#2563eb;word-break:break-all;">
                                <?= htmlspecialchars($url, ENT_QUOTES) ?>
                            </a>
                        </p>
                        <p style="margin:24px 0 0;font-size:13px;color:#999;">
                            If you didn't create this account, you can safely ignore this email.
                        </p>
                    </td></tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
