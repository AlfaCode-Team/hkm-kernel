<?php

declare(strict_types=1);

namespace AlfacodeTeam\Pools\Adapter;

use AlfacodeTeam\Pools\Adapter;

/**
 * The coroutine adapter: a Swoole/OpenSwoole channel plus a lock, so a caller
 * with no idle resource PARKS and yields the scheduler instead of spinning.
 *
 * Both extensions are supported, resolved once at construction. They expose the
 * same `Coroutine\Channel` and `Coroutine\Lock` API under two namespaces, and a
 * deployment has exactly one of them installed.
 *
 * ─── TWO PRIMITIVES, NOT ONE ────────────────────────────────────────────────
 *
 * Callers park on a separate SIGNAL channel rather than on the resource channel.
 * That looks like an extra moving part and is load-bearing twice over:
 *
 *   1. Capacity can be freed WITHOUT a resource coming back — a caller that
 *      discarded a broken connection frees a slot and pushes nothing. Parking on
 *      the resource channel would leave everyone waiting for a resource that is
 *      never going to arrive, right up to their timeout, while the pool had room
 *      to create one.
 *   2. A signal must never be mistaken for, or counted as, an idle resource.
 *      Sharing one channel means `count()` reports waiters as resources.
 *
 * Signals are COALESCED to one and remembered for a caller that has not parked
 * yet, because a released caller re-reads the real state — the signal only ever
 * means "look again".
 *
 * ─── WHAT IS NOT VERIFIED HERE ──────────────────────────────────────────────
 *
 * `Coroutine\Channel` and `Coroutine\Lock` are declared by a PHP EXTENSION, not
 * by any source on the include path. Neither a static analyser nor this
 * package's test suite can see them on a machine without the extension, which
 * is every machine this is developed on. So:
 *
 *   - every extension call goes through {@see invoke()}, whose dynamic method
 *     name is honest about being uncheckable rather than asserted with an
 *     ignore comment;
 *   - this class has NO unit tests. {@see Stack} is exercised exhaustively and
 *     the pool's logic is adapter-agnostic, but the channel semantics this
 *     relies on — that a push wakes a parked pop, that a zero timeout is
 *     "forever" — are claims about the extension, and claims about the
 *     extension can only be tested against the extension.
 *
 * Treat it as unproven until it has run under a Swoole worker.
 */
final class Coroutine extends Adapter
{
    /** The shortest wait these extensions honour without reading it as "forever". */
    private const POLL = 0.001;

    private object $idle;

    private object $lock;

    /** Coalesced "look again" signals, kept apart from the resource channel. */
    private object $signals;

    /**
     * Resolved extension class names.
     *
     * Typed `string`, not `class-string`: the classes exist only when the
     * extension is loaded, so nothing here can prove the stronger type and
     * claiming it would be a claim about the host, not about this code. The
     * `class_exists` probe in {@see resolveClasses()} is what actually
     * establishes it.
     */
    private readonly string $channelClass;

    private readonly string $lockClass;

    public function __construct()
    {
        [$this->channelClass, $this->lockClass] = self::resolveClasses();

        $this->initialize(1);
    }

    /**
     * True when this adapter can be used at all — a coroutine extension is
     * loaded. The pool's own default picks {@see Stack} otherwise.
     */
    public static function available(): bool
    {
        return class_exists('Swoole\Coroutine\Channel') || class_exists('OpenSwoole\Coroutine\Channel');
    }

    /**
     * True when the CURRENT execution is inside a coroutine.
     *
     * Separate from {@see available()} because the extension being loaded does
     * not mean the code is running in a coroutine: a Swoole worker's own
     * bootstrap is not, and parking there would block the whole worker rather
     * than yield it.
     */
    public static function inCoroutine(): bool
    {
        foreach (['Swoole\Coroutine', 'OpenSwoole\Coroutine'] as $class) {
            if (!class_exists($class)) {
                continue;
            }

            /** @var callable(): mixed $cid */
            $cid = [$class, 'getCid'];

            if ((int) $cid() > 0) {
                return true;
            }
        }

        return false;
    }

    public function initialize(int $size): static
    {
        $channel = $this->channelClass;
        $lock    = $this->lockClass;

        $this->idle    = new $channel(max(1, $size));
        $this->lock    = new $lock();
        $this->signals = new $channel(1);

        return $this;
    }

    public function push(mixed $connection): static
    {
        self::invoke($this->idle, 'push', $connection);

        // A returned resource changes what a parked caller is waiting on.
        $this->unblock();

        return $this;
    }

    public function pop(float $timeout): mixed
    {
        // A non-positive channel timeout reads as "wait forever" in both
        // extensions — the exact opposite of a zero budget. Clamp to one poll.
        $budget = $timeout > 0.0 ? $timeout : self::POLL;

        if ($this->count() > 0) {
            return $this->take();
        }

        self::invoke($this->signals, 'pop', $budget);

        return $this->count() > 0 ? $this->take() : false;
    }

    /**
     * Idle resources right now.
     *
     * @phpstan-impure another coroutine can change the idle contents between
     *                 two calls, including during a blocking pop(), so this is
     *                 not a value an analyser may carry across statements
     */
    public function count(): int
    {
        return (int) self::invoke($this->idle, 'length');
    }

    public function canBlock(): bool
    {
        return true;
    }

    public function unblock(): static
    {
        // Coalesced, and remembered for a caller that has not parked yet: one
        // signal is enough, because a released caller re-reads the real state.
        if ((int) self::invoke($this->signals, 'length') === 0) {
            self::invoke($this->signals, 'push', true);
        }

        return $this;
    }

    public function synchronized(callable $callback): mixed
    {
        if (self::invoke($this->lock, 'lock') === false) {
            throw new \RuntimeException('Pools: could not acquire the coroutine lock.');
        }

        try {
            return $callback();
        } finally {
            self::invoke($this->lock, 'unlock');
        }
    }

    /**
     * Take one idle resource and pass the baton on if more remain.
     *
     * The hand-off matters because signals are coalesced: a burst of returns
     * arriving while nobody is parked would otherwise release a single caller
     * and leave the rest waiting beside resources that are already idle.
     */
    private function take(): mixed
    {
        $connection = self::invoke($this->idle, 'pop', self::POLL);

        if ($this->count() > 0) {
            $this->unblock();
        }

        return $connection;
    }

    /**
     * Call a method on an extension-provided object.
     *
     * The method name is a variable deliberately. `$channel->push($x)` would be
     * a static claim that `push` exists on a class no analyser here can see,
     * and the only way to make that claim pass is an ignore comment — which
     * silences the check without making it true. A dynamic call says what is
     * actually happening: the method is resolved by the extension at runtime.
     */
    private static function invoke(object $target, string $method, mixed ...$arguments): mixed
    {
        return $target->{$method}(...$arguments);
    }

    /** @return array{0: string, 1: string} */
    private static function resolveClasses(): array
    {
        foreach ([['Swoole\Coroutine\Channel', 'Swoole\Coroutine\Lock'], ['OpenSwoole\Coroutine\Channel', 'OpenSwoole\Coroutine\Lock']] as $pair) {
            if (class_exists($pair[0]) && class_exists($pair[1])) {
                return $pair;
            }
        }

        throw new \RuntimeException(
            'Pools: the Coroutine adapter needs Swoole or OpenSwoole with Coroutine\\Channel and '
            . 'Coroutine\\Lock. Use the Stack adapter under PHP-FPM.',
        );
    }
}
