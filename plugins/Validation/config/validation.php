<?php

declare(strict_types=1);

use Plugins\Validation\Rules\CommonRules;
use Plugins\Validation\Rules\FinancialRules;

/**
 * Validation configuration — the CodeIgniter `Config\Validation` equivalent.
 *
 * The plugin's Provider reads this at boot and wires it into the Validator:
 *   - `rulesets` : rule-provider CLASSES; every public method becomes a rule
 *                  (Validator::extendWith). This is the CI `$ruleSets` array.
 *   - `groups`   : reusable named {rules, messages} sets addressable via
 *                  Validator::group('name', $data) (CI rule groups).
 *
 * Add your own rule classes / groups here — no core edits, no per-request cost
 * (everything registers ONCE at boot).
 */
return [
    // CI $ruleSets — classes whose public methods are custom rules.
    'rulesets' => [
        CommonRules::class,
        FinancialRules::class,
    ],

    // CI rule groups — name => ['rules' => [...], 'messages' => [...]].
    'groups' => [
        // 'login' => [
        //     'rules' => [
        //         'email'    => 'required|email',
        //         'password' => 'required|string|min:8',
        //     ],
        //     'messages' => [
        //         'email.required' => 'We need your email to sign you in.',
        //     ],
        // ],
    ],
];
