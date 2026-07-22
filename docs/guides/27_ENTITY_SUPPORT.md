# 27 — Entity, Casting & Hydration Support (`Project\Support\`)

> Reusable, DI-free, I/O-free entity-mapping helpers under `projects/Support/`.
> They are the **GDA-compliant decomposition of the legacy `__DEV__/Entity`
> Active Record** — the fat CodeIgniter/Eloquent-style base was split across the
> layers it conflated, and only the genuinely reusable casting / mapping /
> entity-mechanics live here.

This file is the AI-context summary. The exhaustive, copy-pasteable cookbooks are:

- `projects/Support/Casting/README.md` — casting engine + hydrator (13 examples)
- `projects/Support/Entity/README.md` — the `Entity` base (18-part cookbook)

---

## Why it exists

`__DEV__/Entity/Entity.php` was a fat Active Record: magic `__get/__set`,
mutators/accessors, `save()/delete()/restore()`, `performInsert/Update`,
`getRepo_()`, WP-style meta tables, change tracking — all in one base. GDA forbids
ORM/AR in the Domain layer, entities importing infrastructure, and entities
calling their own repository. The responsibilities were therefore split:

| Old `Entity` responsibility | GDA home |
|---|---|
| Attributes, transitions, change tracking, invariants | **Domain entity** (or the `Entity` base) |
| `save()/delete()/performInsert/Update`/meta tables | **Repository** (`DatabasePort`, tenant-scoped) |
| Type casting + row⇄object mapping | **this Support layer** |
| Mass-assignment + validation | **DTO** at the controller edge (entity keeps a guard as defense-in-depth) |
| `toArray()/jsonSerialize()` | Response **DTO** (entity provides them too) |

---

## Components

| Namespace | Class | Role |
|---|---|---|
| `Project\Support\Casting` | `DataCaster` | Cast ONE field value, either direction |
| `Project\Support\Casting` | `TypeParser` | Parse a type string into `{nullable, baseType, params}` |
| `Project\Support\Casting` | `CastInterface` / `BaseCast` | Cast contract + identity base |
| `Project\Support\Casting` | `CastException` | Invalid handler / JSON |
| `Project\Support\Casting\Casts` | 11 built-ins | see table below |
| `Project\Support\Hydration` | `DataConverter` | Map a whole DB row ⇄ object |
| `Project\Support\Entity` | `Entity` (abstract) | Enterprise base for domain entities |

---

## DataCaster

```php
new DataCaster(
    ?array  $castHandlers = null,  // [type => CastInterface::class] merged over defaults
    ?array  $types        = null,  // [field => typeString]
    ?object $helper       = null,  // forwarded as 3rd arg to every cast
    bool    $strict       = true,  // true: null into a non-nullable type throws
);

$caster->castAs(mixed $value, string $field, 'get'|'set' $method = 'get'): mixed;
$caster->setTypes(array $types): static;   // resets parse cache
```

- `'get'` = DataSource → PHP; `'set'` = PHP → DataSource.
- Prefix a type with `?` to pass `null` through. Prefer `?type` over `strict:false`.
- A field absent from `$types` is returned unchanged.

### Type grammar (`TypeParser`)

```text
"?"? baseType ( "[" param ( "," param )* "]" )?
```

`?json[array]` → nullable JSON decoded as assoc array. `datetime[ms]`,
`datetime[Y-m-d]`, `int-bool`, `csv`, etc.

### Built-in casts (`Project\Support\Casting\Casts`)

| Type key(s) | get (DB→PHP) | set (PHP→DB) |
|---|---|---|
| `int` / `integer` | `int` | identity |
| `float` / `double` | `float` | identity |
| `string` | `string` | identity |
| `bool` / `boolean` | `bool` (`filter_var`; `t`/`f` for PG) | identity |
| `int-bool` | `bool` | `int` (0/1) — requires bool input |
| `csv` | `string`→`array` | `array`→`string` |
| `array` | `string`→`array` (native unserialize) | `array`→`string` (`serialize`) |
| `json` | `string`→`stdClass` (or `array` with `[array]`) | value→JSON `string` |
| `object` | `(object)` cast | identity |
| `datetime` | `string`→`DateTimeImmutable` | `DateTimeInterface`→`string` |
| `timestamp` | `int`/`string`→`DateTimeImmutable` | `DateTimeInterface`→`int` |

> `bool` casts on READ only — use `int-bool` when the column stores `0/1` and the
> WRITE must emit an int. `json[array]` → assoc array; plain `json` → `stdClass`.

### Custom cast

Implement `CastInterface` (or extend `BaseCast`) and register via `castHandlers`
(or the entity's `$customCasters`). Custom handlers merge over — and can override —
the defaults.

---

## DataConverter (the Repository hydrator)

```php
new DataConverter(
    array          $types,                          // [column => typeString]
    array          $castHandlers = [],
    ?object        $helper       = null,
    Closure|string $reconstructor = 'reconstitute', // static factory name OR closure
    Closure|string $extractor     = 'toRawArray',   // method name OR closure
);

$conv->fromDataSource(array $row): array;            // row → PHP-typed array
$conv->toDataSource(array $php): array;              // PHP → DB-typed array
$conv->reconstruct(string $class, array $row): object;
$conv->extract(object $obj): array;
```

Reconstruction order: closure → named static factory → throw (no reflection
back-door). Converters pool `DataCaster` by a hash of `types + castHandlers`.

---

## Entity base (`Project\Support\Entity\Entity`)

Abstract. Implements `JsonSerializable`, `ArrayAccess`, `Stringable`. All features
are infrastructure-free.

| Area | API |
|---|---|
| Config | `$primaryKey`, `$casts`, `$customCasters`, `$fillable`, `$guarded`, `$hidden`, `$visible`, `$appends`, `$dates`, `$dateFormat` |
| Mass assignment (secure by default) | `fill()` (honours `$fillable`), `forceFill()` (bypass), `isFillable()` |
| Attribute access | `getAttribute`/`setAttribute`, `getRawAttribute`, `hasAttribute`, `only`, `except`, `get{X}Attribute`/`set{X}Attribute` hooks |
| Typed getters | `getString/getInt/getFloat/getBool/getArray/getDate` |
| Serialization | `toArray`, `toRawArray`, `jsonSerialize`, `toJson`, `__toString`, `makeHidden`/`makeVisible` |
| Change tracking | `syncOriginal`, `isDirty`, `isClean`, `wasChanged`, `getDirty`/`getChanges`, `getOriginal` |
| Identity | `getKey`, `getKeyName`, `exists`, `is`, `isNot` |
| Domain events | `recordEvent` (protected), `hasEvents`, `releaseEvents` |
| Immutability | `seal`, `isSealed` (mutation throws `LogicException`) |
| Lifecycle | `make`, `reconstitute` (records no events), `replicate` (drops PK), `__clone` resets tracking |

### Security

- **Mass assignment denied by default** (`$guarded = ['*']`): `fill()` only writes
  `$fillable` keys, so over-posting can't set `id`/`is_admin`. Defense-in-depth —
  the DTO at the controller edge is still the primary validator.
- **`__debugInfo()` redacts `$hidden`** as `********` — secrets never reach
  `var_dump()`, logs or stack traces.
- **`seal()`** yields a read-only snapshot; any write throws.

---

## Repository usage (NOT Active Record)

```php
final class InvoiceRepository
{
    private DataConverter $converter;

    public function __construct(
        private readonly DatabasePort $db,
        private readonly Identity $identity,
    ) {
        $this->converter = new DataConverter(
            types: ['id' => 'int', 'paid' => 'bool', 'meta' => 'json[array]'],
            reconstructor: 'reconstitute',
            extractor:     'toRawArray',
        );
    }

    public function find(string $id): Invoice
    {
        $row = $this->db->queryOne(
            'SELECT * FROM invoices WHERE id = :id AND tenant_id = :t',
            ['id' => $id, 't' => $this->identity->tenantId],
        ) ?? throw new RepositoryException("Invoice [{$id}] not found", layer: 'repository.invoice');

        return $this->converter->reconstruct(Invoice::class, $row);
    }

    public function save(Invoice $invoice): void
    {
        $this->db->upsert('invoices', $this->converter->extract($invoice), ['id']);
        $invoice->syncOriginal();
    }
}
```

The Service flushes domain events inside the transaction:

```php
$invoice->pay();
foreach ($invoice->releaseEvents() as $event) {
    $this->collector->collect($event);   // buffered in-tx, discarded on rollback
}
$this->repository->save($invoice);
```

---

## Rules

```
✓ Entities carry data + invariants + events; Repositories carry persistence (DatabasePort).
✓ Hydrate with Entity::reconstitute($row) or DataConverter::reconstruct(); persist with toRawArray()/extract() + $db->upsert().
✓ Casts are static + stateless (OpenSwoole-safe); DataConverter pools casters by types-hash.
✓ Mark nullable columns ?type; mass assignment is deny-by-default.
✗ save()/delete()/find()/getRepo_() on an entity — that is the Repository's job.
✗ app()/kernel()/config() or a DB query inside an entity — entities never do I/O.
✗ reconstruct() writing private props by reflection — give the entity a static reconstitute()/toRawArray().
✗ strict:false instead of a nullable ?type. ✗ float for money — custom MoneyCast over integer cents.
```

Relationship to the gold standard: a `final` entity with a private constructor and
fully-encapsulated typed state is still preferred for small, well-defined
aggregates. Extend `Entity` when a flexible, meta-driven attribute bag earns its
keep. See also `03_DOMAIN.md`, `05_REPOSITORY.md`, `22_DATA_ACCESS_ORM_BLUEPRINT.md`.
