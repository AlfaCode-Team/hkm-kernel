<?php

declare(strict_types=1);

/**
 * Mail configuration. The Provider reads this to build the transport, defaults
 * and DKIM signer. All values fall back to env() so nothing is hard-coded.
 */
return [
    // smtp | sendmail | mail | array | log
    'transport' => env('MAIL_TRANSPORT', 'smtp'),

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', ''),
        'name'    => env('MAIL_FROM_NAME', ''),
    ],

    'charset' => env('MAIL_CHARSET', 'UTF-8'),
    'queue'   => env('MAIL_QUEUE', 'mail'),

    'smtp' => [
        // Comma-separated for failover, e.g. "smtp1.example.com,smtp2.example.com".
        'hosts'       => env('MAIL_SMTP_HOSTS', env('MAIL_HOST', 'localhost')),
        'port'        => (int) env('MAIL_PORT', 587),
        'encryption'  => env('MAIL_ENCRYPTION', 'tls'),        // tls | ssl | none
        'username'    => env('MAIL_USERNAME', ''),
        'password'    => env('MAIL_PASSWORD', ''),
        'auth_mode'   => env('MAIL_AUTH_MODE', 'auto'),        // auto|plain|login|cram-md5|xoauth2|none
        'oauth_token' => env('MAIL_OAUTH_TOKEN', ''),
        'helo_domain' => env('MAIL_HELO_DOMAIN', ''),
        'timeout'     => (int) env('MAIL_TIMEOUT', 30),
        'verify_peer' => filter_var(env('MAIL_VERIFY_PEER', 'true'), FILTER_VALIDATE_BOOL),
        'keep_alive'  => filter_var(env('MAIL_KEEP_ALIVE', 'false'), FILTER_VALIDATE_BOOL),
        // Security: NEVER auth over plaintext unless explicitly forced.
        'allow_insecure_auth' => filter_var(env('MAIL_ALLOW_INSECURE_AUTH', 'false'), FILTER_VALIDATE_BOOL),
    ],

    'sendmail' => [
        'binary' => env('MAIL_SENDMAIL_BINARY', '/usr/sbin/sendmail'),
    ],

    // DKIM signing — leave domain/selector/key empty to disable.
    'dkim' => [
        'domain'      => env('MAIL_DKIM_DOMAIN', ''),
        'selector'    => env('MAIL_DKIM_SELECTOR', ''),
        // PEM string OR a path to the private key file.
        'private_key' => env('MAIL_DKIM_KEY', ''),
    ],
];
