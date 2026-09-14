<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

/**
 * DriverAware — a DatabasePort that can name the SQL dialect behind it.
 *
 * WHY THIS IS A SEPARATE INTERFACE AND NOT A DatabasePort METHOD
 * -------------------------------------------------------------
 * `DatabasePort` is implemented by the real adapters AND by roughly fifteen test
 * fakes spread across the plugin repositories. Adding an abstract method to the
 * port would break every one of them at once, for a capability most of them have
 * no opinion about. So it is an OPTIONAL capability, checked with `instanceof` —
 * the same pattern the kernel already uses for
 * {@see \AlfacodeTeam\PhpServicePlatform\Kernel\Http\Contracts\RequestAware} and
 * {@see \AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\HttpStatusAware}.
 *
 * WHAT IT IS FOR
 * --------------
 * Portable SQL generation. The framework's own rules forbid hand-writing
 * `ON DUPLICATE KEY` / `ON CONFLICT` and forbid driver-specific functions in a
 * repository — which is only actionable if something can find out WHICH driver is
 * underneath. Until now nothing typed against the port could: `driver()` existed
 * on the concrete adapter, and the documentation told repositories to call it,
 * but the interface they are required to depend on never declared it.
 *
 * A CONSUMER MUST STILL WORK WITHOUT IT. Check `instanceof`, and fall back to
 * configuration (`DB_DRIVER`) when the bound port does not implement this. A
 * query builder that hard-required it would be unusable against every in-memory
 * fake in the test suites.
 */
interface DriverAware
{
    /**
     * The SQL dialect behind this port, lower-case: 'mysql', 'pgsql', 'sqlite',
     * 'sqlsrv'.
     *
     * This names the DIALECT, not the connection — two connections to different
     * MySQL hosts both answer 'mysql'. Callers use it to choose SQL, so it must
     * be stable for the lifetime of the port.
     */
    public function driver(): string;
}
