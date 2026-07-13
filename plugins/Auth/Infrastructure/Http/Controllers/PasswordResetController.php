<?php

declare(strict_types=1);

namespace Plugins\Auth\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\MailPort;
use Plugins\Auth\Application\Ports\PasswordBroker;
use Project\Http\Controllers\ApiController;

/**
 * PasswordResetController — the old __DEV__ OTP forgot-password flow.
 *
 *   POST /auth/password/forgot     { email }              → 200 {} ALWAYS
 *   POST /auth/password/verify-otp { email, otp }         → 200 { resetToken } | 400
 *   POST /auth/password/reset      { email, token, password } → 200 {} | 400/422
 *
 * The forgot endpoint responds identically whether or not the account exists
 * (enumeration-safe). The 6-digit OTP is emailed via the OPTIONAL MailPort —
 * when no mailer is bound the flow still works for clients that receive the
 * code out-of-band (and the Array/Log transports capture it in dev).
 */
final class PasswordResetController extends ApiController
{
    private const GENERIC_FORGOT_MESSAGE =
        "If that email is registered, you'll receive a 6-digit code shortly. Check your inbox and spam folder.";

    public function __construct(
        private readonly PasswordBroker $broker,
        private readonly ?MailPort $mail = null,
    ) {
    }

    public function forgot(): Response
    {
        $email = mb_strtolower(trim((string) $this->resolveRequest()->input('email')));
        if ($email === '') {
            return $this->unprocessable(['email' => 'An email address is required.']);
        }

        $sent = $this->broker->sendOtp($email);

        if ($sent !== null && $this->mail !== null) {
            try {
                $this->mail->send(
                    $sent['email'],
                    'Your password reset code',
                    'auth::password-otp',
                    ['otp' => $sent['otp'], 'expiresMinutes' => 10],
                );
            } catch (\Throwable) {
                // Non-fatal — never leak delivery problems to the caller.
            }
        }

        // Always 200 — never reveals whether an account exists.
        return $this->ok(['message' => self::GENERIC_FORGOT_MESSAGE]);
    }

    public function verifyOtp(): Response
    {
        $request = $this->resolveRequest();
        $email   = mb_strtolower(trim((string) $request->input('email')));
        $otp     = trim((string) $request->input('otp'));

        if ($email === '' || !preg_match('/^\d{6}$/', $otp)) {
            return $this->unprocessable([
                'email' => $email === '' ? 'An email address is required.' : '',
                'otp'   => 'The code must be exactly 6 digits.',
            ]);
        }

        $token = $this->broker->verifyOtp($email, $otp);
        if ($token === null) {
            return Response::json(['error' => [
                'code'    => 'auth.password.otp_invalid',
                'message' => "That code doesn't match or has expired. Codes are valid for 10 minutes — request a fresh one.",
            ]], 400);
        }

        return $this->ok(['resetToken' => $token]);
    }

    public function reset(): Response
    {
        $request  = $this->resolveRequest();
        $email    = mb_strtolower(trim((string) $request->input('email')));
        $token    = trim((string) $request->input('token'));
        $password = (string) $request->input('password');

        if ($email === '' || $token === '' || \strlen($password) < 8) {
            return $this->unprocessable([
                'email'    => $email === '' ? 'An email address is required.' : '',
                'token'    => $token === '' ? 'Your reset session is missing — request a new code.' : '',
                'password' => \strlen($password) < 8 ? 'Password must be at least 8 characters.' : '',
            ]);
        }

        if ($this->broker->reset($email, $token, $password) !== PasswordBroker::PASSWORD_RESET) {
            return Response::json(['error' => [
                'code'    => 'auth.password.reset_invalid',
                'message' => 'This reset session has already been used or has expired. Please start again.',
            ]], 400);
        }

        return $this->ok(['message' => 'Your password has been updated. You can now sign in.']);
    }
}
