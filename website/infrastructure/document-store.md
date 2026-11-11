# DocumentStorePort — Schemaless collections

`DocumentStorePort` is the contract for schemaless document collections: MongoDB, Firestore, and stores shaped like them.

::: warning It is not an alternative to DatabasePort
`DatabasePort` is portable because MySQL, PostgreSQL and SQLite answer the same language. MongoDB and Firestore answer none of it. An application usually has **both** ports bound, for different data.
:::

## The contract

```php
interface DocumentStorePort
{
    public function get(string $collection, string $id): ?DocumentRecord;
    public function find(DocumentQuery $query): DocumentPage;      // cursor pages, never an offset
    public function count(DocumentQuery $query): int;              // a second request, with its own cost

    public function insert(string $collection, array $document): string;           // store-generated id
    public function create(string $collection, string $id, array $document): bool; // false = id taken
    public function put(string $collection, string $id, array $document): void;    // create or REPLACE
    public function patch(string $collection, string $id, array $set, array $unset = []): bool;
    public function increment(string $collection, string $id, string $field, int|float $by = 1): bool;
    public function delete(string $collection, string $id): bool;

    public function ensureIndex(DocumentIndex $index): IndexResult;
    public function dropIndex(string $collection, string $name): bool;
    public function listIndexes(string $collection): array;

    public function supports(DocumentStoreCapability $capability): bool;
    public function describe(): string;
}
```

Supporting types live in `Kernel\Ports\Document\`: `DocumentQuery`, `DocumentFilter`, `DocumentOperator`, `DocumentIndex`, `IndexResult`, `DocumentRecord`, `DocumentPage`, `CollectionName`, `FieldPath` and `DocumentStoreCapability`.

## The intersection rule

A query is built with `DocumentQuery`, which **refuses at construction** anything only one store can run. The ten operators it admits are the intersection of the two query languages, so a query that builds is a query both backends can answer.

Everything below is the adapter's obligation, and all of it is measured rather than assumed.

## Four behaviours that bite

### Ordering is also a filter

Firestore: *"an orderBy() clause also filters for existence of the given fields."* MongoDB keeps those documents and sorts them first.

Measured on MongoDB 9.0.4: sorting 9 documents by `priority` returned 5; adding `{$exists: true}` returned 4.

An adapter **must** apply `DocumentQuery::requiredFields()`. A list page quietly missing rows looks exactly like a list page.

### `!=` and not-in exclude a missing field

On Firestore they exclude a missing field, and `!=` excludes an explicit null too. MongoDB matches both.

Measured: `{$ne: 'paid'}` returned 4 documents; with `$exists` and `$ne: null` it returned 2. So *"everything not yet paid"* means two different sets depending on which store answers. Say which you mean with `requiredFields()` and `nonNullFields()`.

### A cursor needs a total order

Append the document id as the final sort key (`__name__` on Firestore, `_id` on MongoDB), or pages overlap wherever sort values tie.

### AlreadyExists is not Conflict

`ensureIndex()` returns an outcome, and the two are distinct because the stores distinguish them.

Measured on MongoDB 9.0.4: the same keys under a new name is **code 85**; the same name with `unique: true` added is **code 86** — and the index stays non-unique, so the duplicate still inserts.

An adapter that swallows "already exists" to look idempotent turns that into a green deploy with no constraint behind it.

## What the port deliberately refuses

### No transactions

MongoDB needs a replica set — a standalone 9.0.4 refuses, confirmed — and Firestore always has them. A transactional method here would work in production and fail on a laptop, or the reverse.

### No offset or skip

Firestore charges *"a read for each skipped document"*, so a page number would be priced by how far the user scrolled. `find()` returns cursor pages only.

### No silent downgrade

A unique index asked for and not enforced is an invariant removed without removing the belief in it. That is what `DocumentStoreCapability` and the throw in `ensureIndex()` are for — check `supports()` before relying on one.

### One divergence is left in place

`increment()` on a **non-numeric** field: MongoDB refuses the write (measured, 9.0.4); Firestore *"sets the field to the given value instead"*. Detecting it on Firestore would need a read-then-write, which loses the concurrent increments the method exists to protect. Converging it would destroy the guarantee, so a counter field must only ever be written as a number.

## Adapters

The kernel ships none. Two first-party plugins implement this port, and both `solves: "document.store"` — so a project binds exactly one:

- `hkm-plugin-mongodb` — through `ext-mongodb`
- `hkm-plugin-firestore` — through Firestore's REST API, over `http.client`
