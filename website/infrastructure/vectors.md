# Vectors & Embeddings

Two ports: `VectorStorePort` for similarity search over fixed-dimension vectors, and `EmbeddingPort` for turning text into one. They are separate because the stores and the models are separate products with separate failure modes.

Both follow the same intersection rule as [`DocumentStorePort`](/infrastructure/document-store): a filter, metric or index type is in the port only if every store that could back it gives it one meaning. Anything a store cannot honour is refused at construction rather than approximated.

## VectorStorePort

```php
interface VectorStorePort
{
    public function ensureCollection(VectorCollection $collection): VectorResult;
    public function collection(string $name): ?VectorCollection;
    public function dropCollection(string $name): bool;

    public function ensureIndex(VectorIndex $index): VectorResult;
    public function dropIndex(string $collection): bool;

    public function upsert(string $collection, VectorRecord $record): void;
    public function upsertMany(string $collection, array $records): int;
    public function delete(string $collection, string $id): bool;
    public function deleteWhere(string $collection, array $filters): int;

    public function get(string $collection, string $id): ?VectorRecord;
    public function count(string $collection, array $filters = []): int;
    public function search(VectorQuery $query): array;

    public function supports(VectorStoreCapability $capability): bool;
    public function describe(): string;
}
```

Value objects live in `Kernel\Ports\Vector\`.

### Collections

A collection has a fixed **dimension** (1 to 2000 — the most an HNSW index will take) and a **metric** (`Cosine`, `Euclidean`, `DotProduct`) fixed per collection, never per query.

`ensureCollection()` and `ensureIndex()` answer with a `VectorResult` in which `AlreadyExists` and `Conflict` are distinct, so a migration that changes a definition cannot report success while the old definition stays in place.

### Searching

`VectorQuery::nearest()` takes `k` (1 to 1000) and AND-ed filters: `Eq`, `In` and four numeric ranges.

- A missing field never matches.
- Types are strict.
- A range never matches a non-number.

`VectorFilter::matches()` executes those rules in PHP, so an adapter's tests can assert against it rather than deriving the expectation from the code under test.

### distance is lower-is-closer, for every metric

Dot product is reported as the **negative** inner product so the ordering rule holds across all three metrics. Results are ordered by `(distance, id)`.

### A search returns min(k, matches)

The port states this as an obligation on the adapter, and the measurement behind it is the reason the obligation exists.

::: danger Filtered k-NN silently under-returns
Measured on 30,000 rows with a cosine HNSW index in pgvector 0.8.7 / PostgreSQL 18.6: a filter matching 2% of rows at `LIMIT 10` returned **2 rows**. A filter matching 0.05% — 15 rows — returned **0**.

An HNSW scan applies its filter *after* a fixed candidate list, and the planner keeps no statistics for jsonb containment, so it takes the index anyway. There is no error. The adapter must detect the short result and run an exact pass.
:::

`hkm-plugin-pgvector` does exactly that: an approximate pass first, an exact pass when it returns fewer than `k`.

### What is deliberately absent

Negations, `Or`, offset, and non-HNSW index types. Negation was cut after *"not paid"* returned one, two or three rows from the same database depending on how it was asked. Vectors are capped at **2000 dimensions**, which is where HNSW refuses to index.

## EmbeddingPort

```php
interface EmbeddingPort
{
    public function embed(string $text): Vector;
    public function embedMany(array $texts): array;   // list<Vector>
    public function dimensions(): int;
    public function model(): string;
}
```

Deliberately nothing about prompts, tools or providers — that is a different problem with a far less settled shape.

`embedMany()` exists because re-embedding unchanged text is the main cost sink in any real system. An adapter should cache on `sha256(model, dimensions, text)` and send only the distinct misses.

## Adapters

The kernel ships none.

| Plugin | Port | Notes |
|---|---|---|
| `hkm-plugin-pgvector` | `VectorStorePort` | One table per collection; no registry table, no migration of its own |
| `hkm-plugin-embeddings` | `EmbeddingPort` | A deterministic local embedder and an OpenAI-compatible adapter over `http.client`, with a content-hash cache |

::: tip EMBEDDINGS_DRIVER has no default
It is required with no default, so a deployment cannot end up on meaningless local vectors — or on a paid API — by omission.
:::
