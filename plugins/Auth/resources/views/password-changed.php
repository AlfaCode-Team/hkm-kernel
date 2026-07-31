<?php
/**
 * Password-changed notification (brand-neutral), sent AFTER a successful reset.
 *
 * This is the account-takeover tripwire: if the reset was not the account owner,
 * this mail is the only signal they get. It therefore states plainly what
 * happened, when, and what to do — and never contains a token or a login link.
 *
 * Data: string $email, string $changedAt, string $ip
 */
$email     = htmlspecialchars((string) ($email ?? ''), ENT_QUOTES, 'UTF-8');
$changedAt = htmlspecialchars((string) ($changedAt ?? ''), ENT_QUOTES, 'UTF-8');
$ip        = htmlspecialchars((string) ($ip ?? ''), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:20px;background:#f6f7f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
  <div style="max-width:480px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(17,24,39,.08);">
    <div style="padding:32px 28px;">
      <h2 style="margin:0 0 8px;font-size:20px;color:#111827;font-weight:700;">Your password was changed</h2>
      <p style="color:#6B7280;margin:0 0 24px;font-size:15px;line-height:1.5;">
        The password for <strong><?= $email ?></strong> was just reset. You were signed
        out on every other device as a precaution.
      </p>

      <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;background:#f3f4f6;border-radius:12px;padding:16px 18px;margin-bottom:24px;">
        <tr>
          <td style="color:#6B7280;font-size:13px;padding:2px 0;">When</td>
          <td style="color:#111827;font-size:13px;text-align:right;padding:2px 0;"><?= $changedAt ?></td>
        </tr>
        <?php if ($ip !== '') { ?>
        <tr>
          <td style="color:#6B7280;font-size:13px;padding:2px 0;">From IP</td>
          <td style="color:#111827;font-size:13px;text-align:right;padding:2px 0;"><?= $ip ?></td>
        </tr>
        <?php } ?>
      </table>

      <p style="color:#111827;font-size:14px;margin:0 0 8px;font-weight:600;">Didn&apos;t do this?</p>
      <p style="color:#9CA3AF;font-size:13px;margin:0;line-height:1.6;">
        Someone else may have access to your email inbox. Reset your password again
        immediately, then secure the email account itself. If you cannot sign in,
        contact support straight away.
      </p>
    </div>
  </div>
</body>
</html>
