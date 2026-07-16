<?php

declare(strict_types=1);

namespace {{STUDLY}}\Application;

use {{STUDLY}}\Domain\Greeting;

/**
 * APPLICATION LAYER — a use case (application service).
 *
 * Orchestrates the domain to fulfil one piece of application behaviour. It may
 * depend on the Domain and on PORT interfaces (declared in Application/Ports/),
 * but NEVER on Infrastructure concretions — that inversion is the whole point of
 * the hexagon: the use case states WHAT it needs, adapters provide HOW.
 *
 * This example has no port dependencies, so the kernel can autowire it directly
 * into the controller. When you add persistence, inject a port interface here
 * (e.g. a GreetingRepository) and bind its adapter in app/bootstrap/app.php.
 */
final class GreetingService
{
    public function greet(string $project): Greeting
    {
        return new Greeting($project);
    }
}
