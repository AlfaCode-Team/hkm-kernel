<?php
/**
 * OTP password-reset email (ported from the old __DEV__ flow, brand-neutral).
 * Data: string $otp, int $expiresMinutes
 */
$otp = htmlspecialchars((string) ($otp ?? ''), ENT_QUOTES, 'UTF-8');
$expiresMinutes = (int) ($expiresMinutes ?? 10);
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:20px;background:#f6f7f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
  <div style="max-width:480px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(17,24,39,.08);">
    <div style="padding:32px 28px;">
      <h2 style="margin:0 0 8px;font-size:20px;color:#111827;font-weight:700;">Password Reset Code</h2>
      <p style="color:#6B7280;margin:0 0 28px;font-size:15px;line-height:1.5;">
        Use the code below to reset your password. It expires in <strong><?= $expiresMinutes ?> minutes</strong>.
      </p>
      <div style="background:#f3f4f6;border-radius:12px;padding:24px;text-align:center;margin-bottom:28px;">
        <span style="font-size:40px;font-weight:800;letter-spacing:12px;color:#111827;font-variant-numeric:tabular-nums;"><?= $otp ?></span>
      </div>
      <p style="color:#9CA3AF;font-size:13px;margin:0;line-height:1.6;">
        If you didn&apos;t request a password reset, you can safely ignore this email.
        Your password will not be changed.
      </p>
    </div>
  </div>
</body>
</html>
