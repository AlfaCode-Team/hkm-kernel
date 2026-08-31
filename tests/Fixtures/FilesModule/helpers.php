<?php

declare(strict_types=1);

// A GLOBAL function — the thing no class autoloader can ever reach, and the
// whole reason CompileModuleFilesStage exists.
if (!function_exists('psp_test_declared_helper')) {
    function psp_test_declared_helper(): string
    {
        return 'declared';
    }
}
