<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Tests\Fixtures\Essential;

/** Something an ESSENTIAL module binds for every request — a session, a cookie jar. */
interface AmbientContract
{
    public function marker(): string;
}
