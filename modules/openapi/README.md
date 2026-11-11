# alfacode-team/openapi

A small OpenAPI 3.1 document model and JSON emitter. Framework-agnostic: it
knows nothing about routers, manifests or HTTP kernels — you hand it operations,
it hands you a spec.

## Install

```bash
composer require alfacode-team/openapi
```

## Use

```php
use AlfacodeTeam\OpenApi\{Document, Info, Operation, Parameter, Response, Schema, SecurityScheme, Server};

$doc = new Document(new Info('Billing API', '2.1.0'));
$doc->addServer(new Server('https://api.example.com'));
$doc->addSecurityScheme('bearer', SecurityScheme::bearer('JWT'));

$doc->addOperation('/invoices/{id}', 'GET', Operation::create()
    ->withOperationId('invoices.show')
    ->withTag('billing')
    ->withParameter(Parameter::path('id', Schema::string(format: 'uuid')))
    ->withSecurity('bearer')
    ->withResponse(200, Response::of('OK.', Schema::unknownObject()))
    ->withResponse(404, Response::error('Not found.')));

file_put_contents('openapi.json', $doc->toJson());
```

## What it guarantees

**Deterministic output.** Paths sort, methods within a path follow a fixed verb
order, security schemes sort, and responses sort by status. Regenerating an
unchanged input produces a byte-identical document — which is what makes the
output safe to commit and review in a diff. A spec that reshuffles itself on
every build is a spec nobody can review.

**Invalid documents are refused, not emitted.** Four guards, each for a mistake
that otherwise produces a spec that looks fine and generates a broken client:

| Rejected | Why |
|---|---|
| `/things/{id:num}` | a framework path, not an OpenAPI template — no client can match it. The type belongs in the parameter schema |
| `things` | paths must be absolute |
| `FETCH /things` | not a method OpenAPI describes |
| the same `operationId` twice | OpenAPI requires document-wide uniqueness; generators fail or silently drop one of the two methods |

**A path parameter cannot be optional.** `Parameter::path()` hard-codes
`required: true`, because a non-required path parameter is invalid OpenAPI. A
route with an optional segment is two URL templates and has to be emitted as
two operations — the caller does that, and this model makes the alternative
unexpressible.

## Honest about what it does not know

`Schema::unknownObject()` exists so a generator can say "there is a body here
and I cannot describe it". A client generated from an undescribed body compiles
and hands back raw data; one generated from a guessed shape does not compile at
all. Guessing is the worse failure.

`Response::error()` models one shape the framework does know — the standard
error envelope:

```json
{ "error": { "code": "...", "message": "...", "requestId": "...", "fields": {} } }
```

## Scope

Modelled: paths, operations, path/query/header parameters, request bodies,
responses, `http bearer` and `apiKey` security schemes, tags, servers, and the
JSON Schema subset a route surface produces (scalars, patterns, formats, enums,
arrays, objects).

Not modelled: OAuth2/OIDC flows, callbacks, webhooks, links, `$ref` reuse,
discriminators, XML, YAML output. Each is easy to add when something needs it;
none is needed to describe a REST surface.

## Test

```bash
composer install && composer test
```

## Licence

MIT.
