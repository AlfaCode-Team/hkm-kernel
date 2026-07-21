<?php

namespace Plugins\Auth\Application\Ports;

interface Driver
{
    /**
     * Determine if the current user is authenticated.
     *
     * @return bool
     */
    public function check(): bool;

    /**
     * Determine if the current user is a guest.
     *
     * @return bool
     */
    public function guest(): bool;

    /**
     * Get the currently authenticated user.
     *
     * @return Authenticatable|null
     */
    public function user(): ?Authenticatable;

    /**
     * Attempt to authenticate a user using the given credentials.
     *
     * @param  array  $credentials
     * @param  bool  $remember
     * @return bool
     */
    public function attempt(array $credentials = [], bool $remember = false): bool;

    /**
     * Get the ID for the currently authenticated user.
     *
     * @return int|string|null
     */
    public function id(): int|string|null;

    /**
     * Validate a user's credentials.
     *
     * @param  array  $credentials
     * @return bool
     */
    public function validate(array $credentials = []): bool;

    /**
     * Determine if the guard has a user instance.
     *
     * @return bool
     */
    public function hasUser(): bool;

    /**
     * Set the current user.
     *
     * @param  Authenticatable  $user
     * @return static
     */
    public function setUser(Authenticatable $user): static;
}
