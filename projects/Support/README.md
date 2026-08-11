# `Project\Support` — Project-Layer Support Library

> Namespace `Project\Support\` → `projects/Support/` (composer psr-4 `"Project\\": "projects/"`).

Reusable, **DI-free** helpers that belong to the PROJECT layer. Every class here:

- performs **no I/O** (the SEO sitemap writers are the one deliberate exception —
  they write files you hand them a path for),
- reads **no globals** (`config()`, `kernel()`, `app()` never appear),
- imports **nothing** outside its own namespace + PHP built-ins.

That is what makes them safe to call from a plugin's `Domain/`, `Application/`
or `Infrastructure/` layer without breaking the Five Access Rules: they are
value-level utilities, not services. They are plain autoloaded classes — nothing
here is bound in a container and nothing needs a module load.

---

## Map

| Path | Class(es) | Role | Doc |
|---|---|---|---|
| `Arr.php` | `Arr` | Static array helpers (dot access, pluck, flatten) | this file |
| `Str.php` | `Str` | Static string helpers (case conversion, slug, random) | this file |
| `Collection.php` | `Collection` | Fluent, chainable array wrapper | this file |
| `Resource.php` | `Resource` | API transformer base — one class per output shape | this file |
| `ResourceCollection.php` | `ResourceCollection` | List of items transformed by a `Resource` | this file |
| `Casting/` | `DataCaster`, `TypeParser`, `CastInterface`, 11 casts | Cast ONE field value DB↔PHP | [`Casting/README.md`](Casting/README.md) |
| `Hydration/` | `DataConverter` | Map a whole DB row ⇄ object | this file + [`Casting/README.md`](Casting/README.md) |
| `Entity/` | `Entity` | Enterprise entity base (attribute bag, casts, dirty tracking) | [`Entity/README.md`](Entity/README.md) |
| `Seo/` | `RichGraph`, `SeoHead`, sitemap toolkit, `RouteCatalog`, `IndexNowKey` | SEO/sitemap/JSON-LD building | [`Seo/README.md`](Seo/README.md) |

Deeper AI context: `docs/ai-context/27_ENTITY_SUPPORT.md` (casting + entity) and
`docs/ai-context/29_PROJECT_LAYER.md` (the whole `Project\` layer).

---

## `Arr` — static array helpers

Dot-notation aware. Used internally by `Collection`; fine to use standalone.

```php
use Project\Support\Arr;

Arr::get($row, 'billing.address.city', 'n/a');   // dot path, default on miss
Arr::get($row, null);                            // null key → the whole array
Arr::set($payload, 'meta.source', 'import');     // creates intermediate arrays, by reference
Arr::has($row, 'billing.address');               // true even when the value is null

Arr::only($row, ['id', 'email']);                // key-preserving subset
Arr::except($row, ['password_hash']);

Arr::first($rows);                                // first value, or $default when empty
Arr::first($rows, fn($v, $k) => $v['active']);    // first match; callback gets ($value, $key)

Arr::flatten($nested);                            // full depth
Arr::flatten($nested, depth: 1);                  // one level only

Arr::pluck($rows, 'name');                        // list of values
Arr::pluck($rows, 'name', 'id');                  // ['id-1' => 'name', …]

Arr::isAssoc($array);                             // keys are NOT 0..n-1
```

Notes worth knowing:

- `get()` checks `array_key_exists($key, …)` FIRST, so a literal key containing
  a dot (`'a.b' => 1`) wins over the dot path — that is intentional.
- `has()` distinguishes "missing" from "present but null" via an internal
  sentinel; `isset()`-style checks do not.
- `pluck()` reads array items by dot path and object items by public property.

---

## `Str` — static string helpers

```php
use Project\Support\Str;

Str::studly('order_line');      // 'OrderLine'
Str::camel('order_line');       // 'orderLine'
Str::snake('OrderLine');        // 'order_line'
Str::kebab('OrderLine');        // 'order-line'
Str::slug('Héllo World!');      // 'hello-world'   (unicode letters/numbers kept)

Str::startsWith($path, '/api'); // false for an EMPTY needle (unlike str_starts_with)
Str::endsWith($file, '.php');
Str::contains($ua, 'Mobile');

Str::limit($text, 120);         // mb-safe truncate + '...'
Str::random(32);                // hex, from random_bytes — cryptographically strong
```

`startsWith`/`endsWith`/`contains` return **false** on an empty needle by
design, so `Str::contains($x, '')` cannot accidentally pass a filter. Use the
native `str_*` functions when you want PHP's empty-needle semantics.

---

## `Collection` — fluent array wrapper

`final class Collection implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable`.
Transforming methods return a **new** Collection; only `push()`/`put()` mutate
and return `$this`. Original implementation — no Laravel dependency.

```php
use Project\Support\Collection;

$total = Collection::make($orderRows)
    ->filter(fn(array $r) => $r['status'] === 'paid')
    ->sortBy('created_at')
    ->sum('amount_cents');

$byCustomer = Collection::make($orders)->groupBy('customer_id');   // Collection<Collection>
```

| Group | Methods |
|---|---|
| Build | `__construct(iterable)`, `make()` |
| Read | `all()`, `count()`, `isEmpty()`, `isNotEmpty()`, `get()`, `has()`, `contains()`, `first()`, `last()` |
| Transform | `map()`, `filter()`, `reject()`, `pluck()`, `keys()`, `values()`, `unique()`, `reverse()`, `slice()`, `take()`, `chunk()`, `flatten()`, `merge()` |
| Order/group | `sort()`, `sortBy()`, `groupBy()`, `where()` |
| Aggregate | `reduce()`, `sum()`, `avg()`, `min()`, `max()`, `implode()` |
| Side effects | `each()` (return `false` to break), `push()`, `put()`, `pipe()` |
| Export | `toArray()`, `toJson()`, `jsonSerialize()`, `getIterator()` |

Behaviour that is easy to get wrong:

- `map()` and `filter()` receive **`($value, $key)`** and **preserve keys**.
  Call `->values()` when you need a JSON list.
- `contains()` accepts a value (strict `in_array`) **or** a predicate.
- `chunk()` returns a Collection **of Collections**; `toArray()` unwraps nested
  Collections recursively.
- `sortBy()`/`where()`/`sum()` cast each item with `(array)` before the dot
  lookup, so they work on arrays and on objects with public state.
- `toJson()` always sets `JSON_THROW_ON_ERROR`.

---

## `Resource` / `ResourceCollection` — API output shaping

Keeps "what the API returns" out of controllers and services. One subclass per
output shape; the controller stays at its 3-line limit.

```php
use Project\Support\Resource;

final class UserResource extends Resource
{
    public function toArray(): array
    {
        return [
            'id'    => $this->get('id'),
            'name'  => $this->get('fullName'),   // property OR getter — see below
            'email' => $this->get('email'),
        ];
    }
}

return $this->ok(UserResource::make($user)->toArray());
return $this->ok(UserResource::collection($users)->toArray());   // list of shaped items
```

- `Resource::make($resource): static` wraps one item; `Resource::collection(iterable): ResourceCollection`
  wraps many (each item constructed through the same resource class, lazily on
  `toArray()`).
- The protected `get(string $key, mixed $default = null)` reads the wrapped
  value whether it is an **array key**, a **public property**, or a **method**
  (`$user->fullName()`), in that order.
- Both implement `JsonSerializable` and expose `toJson()` with
  `JSON_THROW_ON_ERROR`.

Resources are a **presentation** concern: never put authorization, persistence
or event dispatch inside `toArray()`.

---

## `Hydration\DataConverter` — the row ⇄ object bridge

The Repository's hydrator. Runs every mapped field through the
[`DataCaster`](Casting/README.md) and builds/consumes domain objects through
explicit seams — there is **no reflection back-door** that writes private
properties.

```php
use Project\Support\Hydration\DataConverter;

$converter = new DataConverter(
    types: [
        'id'         => 'int',
        'is_active'  => 'int-bool',       // 0/1 column ⇄ bool
        'meta'       => '?json[array]',   // nullable JSON ⇄ assoc array
        'created_at' => 'datetime',
    ],
    castHandlers:  ['money' => MoneyCast::class],   // optional custom casts
    helper:        null,                            // optional object passed to casts
    reconstructor: 'reconstitute',                  // static factory name, or a Closure
    extractor:     'toRawArray',                    // method name, or a Closure
);

$invoice = $converter->reconstruct(Invoice::class, $row);   // DB row  → object
$columns = $converter->extract($invoice);                   // object  → DB columns

$phpRow  = $converter->fromDataSource($row);                // row array → PHP-typed array
$dbRow   = $converter->toDataSource($phpRow);               // PHP array → DB-typed array
```

- `reconstruct()` calls `$classname::$reconstructor($phpData)`. If that static
  factory does not exist it throws `RuntimeException` telling you to pass a
  closure — it will not reach into private state.
- `extract()` calls the named method when present; otherwise it falls back to a
  `(array)` cast that keeps **public state only** (private/protected keys are
  NUL-mangled and dropped). Give entities a real `toRawArray()`.
- Casters are pooled statically by a hash of `types + castHandlers`, so building
  a `DataConverter` per repository call is cheap. Casts are stateless →
  OpenSwoole-safe.

Full casting grammar (`?type`, `type[param,param]`, the 11 built-ins, writing a
custom `CastInterface`) lives in [`Casting/README.md`](Casting/README.md).

---

## Rules

```
✓ Support classes are DI-free value utilities — construct them inline, never bind them.
✓ Safe to call from Domain/, Application/ and Infrastructure/ — they import nothing external.
✓ Collection/Arr/Str are ORIGINAL implementations — do not "align" them with Laravel's API.
✓ Shape API output in a Resource subclass; keep controllers at 3 lines.
✓ Hydrate via DataConverter::reconstruct() (or Entity::reconstitute()); persist via extract()/toRawArray() + $db->upsert().
✗ Adding I/O, env reads, or container lookups to anything under Support/ — that belongs in a plugin.
✗ Business rules inside Resource::toArray() — it is presentation only.
✗ Relying on Collection::map()/filter() to renumber keys — call ->values().
✗ Reflection-based hydration — expose a static reconstitute()/toRawArray() seam instead.
```
