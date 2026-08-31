<?php

declare(strict_types=1);

// Mirrors hkm-plugin-authorization: helpers live at Engine/functions.php and are
// declared ONLY in the plugin's composer.json autoload.files.
if (!function_exists('psp_test_composer_helper')) {
    function psp_test_composer_helper(): string
    {
        return 'composer';
    }
}
