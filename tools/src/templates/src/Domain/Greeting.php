<?php

declare(strict_types=1);

namespace {{STUDLY}}\Domain;

/**
 * DOMAIN LAYER — the centre of the hexagon.
 *
 * Pure business model: no framework, no database, no HTTP, no vendor SDKs — the
 * ONLY imports allowed here are other Domain types. That isolation is what lets
 * the domain be unit-tested without booting anything.
 *
 * `Greeting` is a small value object: immutable, constructed from primitives,
 * and exposing a behaviour (message()) rather than raw getters. Grow this layer
 * with Entities, ValueObjects, domain Events, and Rules as your model develops.
 */
final readonly class Greeting
{
    public function __construct(
        private string $project,
    ) {}

    public function message(): string
    {
        return "Welcome to {$this->project}";
    }
}
