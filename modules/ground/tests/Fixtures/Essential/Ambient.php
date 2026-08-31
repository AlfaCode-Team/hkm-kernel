<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Tests\Fixtures\Essential;

final class Ambient implements AmbientContract
{
    public function marker(): string
    {
        return 'ambient';
    }
}
