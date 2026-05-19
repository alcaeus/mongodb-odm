# Persistence & Hydration Layer — Analysis and Codec Refactoring Proposal

> **Scope note**: This analysis covers full-document serialisation (insert / upsert / replace)
> and hydration (read path). Building atomic update operators from a changeset
> (`PersistenceBuilder::prepareUpdateData`, `CollectionPersister`) is explicitly out of scope.

---

## 1. Current Architecture: Data Flow

### 1.1 Write path (PHP → MongoDB)

```
$dm->persist($document)  →  UnitOfWork schedules insert
                                 ↓
                         DocumentPersister::executeInserts()
                                 ↓
                         PersistenceBuilder::prepareInsertData($document)
                           ├─ scalar fields   → Type::convertToDatabaseValue()
                           ├─ EmbedOne        → prepareEmbeddedDocumentValue() (recursive)
                           ├─ EmbedMany       → prepareAssociatedCollectionValue()
                           ├─ ReferenceOne    → DocumentManager::createReference()
                           └─ ReferenceMany   → prepareAssociatedCollectionValue()
                                 ↓ array[]
                         Collection::insertMany($data[])
                                 ↓
                              MongoDB
```

### 1.2 Read path (MongoDB → PHP)

```
$dm->find(Foo::class, $id)
        ↓
DocumentPersister::load($criteria)
  ├─ prepareQueryOrNewObj()       (PHP field names → DB field names, type conversion)
  ├─ addDiscriminatorToPreparedQuery()
  ├─ addFilterToPreparedQuery()
  └─ Collection::findOne($query)  → raw BSON array
        ↓
DocumentPersister::createDocument($result)
        ↓
UnitOfWork::getOrCreateDocument()
  ├─ identity map check           (return existing if already loaded)
  └─ HydratorFactory::hydrate($document, $data, $hints)
       ├─ fire preLoad lifecycle callbacks
       ├─ invoke @AlsoLoad methods
       ├─ mark proxy as initialized
       ├─ GeneratedHydrator::hydrate($document, $data, $hints)  ← generated PHP code
       │    ├─ scalars       → Type::convertFromDatabaseValue()
       │    ├─ EmbedOne      → recursively call HydratorFactory::hydrate()
       │    │                   + UnitOfWork::setParentAssociation()
       │    │                   + UnitOfWork::registerManaged()
       │    ├─ EmbedMany/    → create PersistentCollection(initialized: false, mongoData: $raw)
       │    │  ReferenceMany   (lazy — loaded on first access)
       │    └─ ReferenceOne  → DocumentManager::getReference()  (creates proxy)
       ├─ fire postLoad lifecycle callbacks
       └─ return $hydratedData array  ← used by UnitOfWork to store original snapshot
        ↓
UnitOfWork::registerManaged($document, $id, $hydratedData)
        ↓
Returns hydrated, identity-map-registered PHP object
```

---

## 2. Responsibility Map: What Each Class Actually Does

### `PersistenceBuilder`

| Operation | What it does |
|---|---|
| `prepareInsertData()` | Walks all field mappings; converts every field to its DB representation; returns a flat PHP `array` ready for `insertMany()` |
| `prepareUpsertData()` | Same as insert but uses `$setOnInsert` semantics |
| `prepareEmbeddedDocumentValue()` | Recursively serialises an embedded object to `array`/`object` |
| `prepareReferencedDocumentValue()` | Delegates to `DM::createReference()` to produce ObjectId / DBRef / RefKey |
| `prepareAssociatedCollectionValue()` | Iterates a PersistentCollection; encodes each element via the two methods above |

**Verdict**: `prepareInsertData()` + `prepareEmbeddedDocumentValue()` together constitute a
complete **encoder** (PHP object graph → BSON-compatible array). The update methods
(prepareUpdateData, prepareUpsertData) are a separate concern (changeset → operators) and
are out of scope here.

### `DocumentPersister`

| Responsibility | Methods |
|---|---|
| Insert / Upsert execution | `executeInserts`, `executeUpserts`, `executeUpsert` |
| Update / Delete execution | `update`, `delete` |
| Single-document loading | `load`, `createDocument` |
| Cursor loading | `loadAll` → `HydratingIterator` |
| Collection loading | `loadCollection`, `loadEmbedManyCollection`, `loadReferenceManyCollection*` |
| Query preparation | `prepareQueryOrNewObj`, `prepareQueryElement`, `prepareSort`, `prepareProjection` |
| Lazy-load trigger | `refresh` |

**Verdict**: `DocumentPersister` conflates two unrelated concerns:
1. **Query preparation** — converting PHP field names and values into MongoDB wire format for
   queries and projections (entirely separate from hydration).
2. **Load orchestration** — fetching raw BSON and coordinating identity-map + hydration.

### `HydratorFactory` + generated hydrators

| Responsibility | Notes |
|---|---|
| Code generation | Emits a PHP class file per document class |
| Scalar field decoding | Inlines `Type::convertFromDatabaseValue()` calls |
| EmbedOne decoding | Recursive call back into `HydratorFactory::hydrate()` |
| EmbedMany / ReferenceMany decoding | Creates `PersistentCollection` with `initialized: false` |
| ReferenceOne decoding | Calls `DM::getReference()` to create a proxy |
| Lifecycle event dispatch | `preLoad` / `postLoad` callbacks and events (in `HydratorFactory::hydrate()`) |
| Original data tracking | Returns `$hydratedData` array consumed by `UnitOfWork` |

**Verdict**: The generated hydrator is an **implicit codec** — it decodes one document class,
knows how to recurse into embedded documents, and handles the full object graph. The code
generation is a performance optimisation that avoids per-call reflection, but it produces
code that is difficult to debug, requires a writable filesystem, and couples DocumentManager
directly into generated source code.

---

## 3. Problems with the Current Approach

### 3.1 Generated-code fragility

Hydrators are PHP source files generated at boot or on-demand. This requires:
- A writable hydrator directory in every environment.
- A cache-clear step after mapping changes (stale generated code causes subtle bugs).
- An `eval()` fallback that is hard to reason about in production.
- Generated code that embeds `DocumentManager`, making it impossible to test hydration in
  isolation.

### 3.2 `HydratorInterface` conflates decoding with change-tracking setup

```php
public function hydrate(object $document, array $data, array $hints = []): array;
```

The return value (`$hydratedData`) is not used for the caller's benefit — it exists solely
so `UnitOfWork` can store an original snapshot. Decoding a document should not have to know
about change tracking.

### 3.3 `PersistenceBuilder` encodes into `array`, not BSON

`prepareInsertData()` returns a PHP `array`. The driver then re-encodes that array to BSON.
The `DocumentCodec` interface from `mongodb/mongodb` targets `MongoDB\BSON\Document` directly,
allowing the driver to skip one serialisation pass.

### 3.4 `DocumentPersister::loadAll()` wraps its cursor in `HydratingIterator`

`HydratingIterator` is a hand-rolled lazy-decoding cursor. The `CodecCursor` in
`mongodb/mongodb` already provides exactly this — a driver cursor that decodes each document
on iteration via a `DocumentCodec`.

### 3.5 Deep coupling to `DocumentManager` inside hydration code

Generated hydrators call `$this->dm->getReference()`, `$this->dm->getClassMetadata()`,
`$this->dm->getUnitOfWork()->setParentAssociation()`. These are session-level concerns that
do not belong inside a data converter.

---

## 4. What MongoDB's `DocumentCodec` Gives Us

From the driver library analysis:

```php
interface DocumentCodec extends Codec
{
    public function decode(Document $value): object;  // BSON Document → PHP object
    public function encode(mixed $value): Document;   // PHP object → BSON Document
    public function canDecode(mixed $value): bool;
    public function canEncode(mixed $value): bool;
}
```

**Key capabilities:**

- A `Collection` created with a `codec` option automatically encodes on writes and decodes on
  reads without any additional wiring in the application.
- `find()` and `aggregate()` wrap their cursor in a `CodecCursor` that decodes lazily on
  iteration — identical behaviour to `HydratingIterator` but provided by the library.
- `insertOne()`, `replaceOne()`, `BulkWrite` automatically call `codec->encode()` before
  sending to MongoDB.
- `canEncode()`/`canDecode()` allow a single codec library to serve multiple document types
  (polymorphism, discriminators).

**What it does NOT provide out of the box:**

- Identity-map integration (do not decode if already in memory).
- Proxy / lazy-object creation for references.
- `PersistentCollection` creation for lazy collection loading.
- Lifecycle event dispatch (preLoad / postLoad).
- Change-tracking snapshot capture.

These session-level concerns need to live in an adapter layer around the codec, not inside
the codec itself.

---

## 5. Proposed Architecture

The central idea: split the current monolith into two clean layers.

```
┌──────────────────────────────────────────────────────────┐
│  SESSION LAYER  (DocumentManager / UnitOfWork)           │
│  • Identity map           • Lifecycle events             │
│  • Proxy creation         • Change-tracking snapshots    │
│  • PersistentCollection   • Parent association tracking  │
└───────────────────┬──────────────────────────────────────┘
                    │ calls
┌───────────────────▼──────────────────────────────────────┐
│  CODEC LAYER  (DocumentCodec per class)                   │
│  • encode: PHP object → MongoDB\BSON\Document            │
│  • decode: MongoDB\BSON\Document → PHP object            │
│  • scalar type conversion  • embedded doc composition    │
│  • discriminator handling  • no DM reference             │
└───────────────────┬──────────────────────────────────────┘
                    │ uses
┌───────────────────▼──────────────────────────────────────┐
│  DRIVER LAYER  (mongodb/mongodb Collection)               │
│  • CodecCursor (lazy decode on iteration)                │
│  • BulkWrite encodes before sending                      │
└──────────────────────────────────────────────────────────┘
```

### 5.1 `DocumentCodec` — pure encode/decode, no session state

A per-class implementation of `DocumentCodec` handles only data conversion:

```php
final class UserDocumentCodec implements DocumentCodec
{
    public function __construct(
        private readonly ClassMetadata $metadata,
        private readonly DocumentCodecRegistry $codecs,  // for embedded types
    ) {}

    public function canDecode(mixed $value): bool
    {
        return $value instanceof Document;
    }

    public function decode(mixed $value): User
    {
        $plain = [];

        // Scalar fields: Type conversion
        if ($value->has('name')) {
            $plain['name'] = Type::getType('string')
                ->convertFromDatabaseValue($value->get('name'));
        }

        // EmbedOne: delegate to the embedded class's codec
        if ($value->has('address')) {
            $plain['address'] = $this->codecs
                ->getCodecFor(Address::class)
                ->decode($value->get('address'));
        }

        // EmbedMany: returns a plain array of decoded objects
        // (PersistentCollection wrapping happens in the session layer)
        if ($value->has('tags')) {
            $plain['tags'] = array_map(
                fn($item) => $this->codecs->getCodecFor(Tag::class)->decode($item),
                iterator_to_array($value->get('tags'))
            );
        }

        // ReferenceOne: returns raw reference data
        // (proxy creation happens in the session layer)
        if ($value->has('authorRef')) {
            $plain['authorRef'] = $value->get('authorRef');  // raw DBRef/ObjectId
        }

        return $this->metadata->newInstance($plain);
    }

    public function encode(mixed $value): Document
    {
        // Inverse of decode — builds BSON Document from PHP object
        $data = [];
        foreach ($this->metadata->fieldMappings as $mapping) {
            $fieldValue = $this->metadata->propertyAccessors[$mapping['fieldName']]
                ->getValue($value);
            $data[$mapping['name']] = Type::getType($mapping['type'])
                ->convertToDatabaseValue($fieldValue);
        }
        // ... embedded and reference fields ...
        return Document::fromPHP($data);
    }

    public function canEncode(mixed $value): bool
    {
        return $value instanceof User;
    }
}
```

**Critical design rule**: No `DocumentManager`, `UnitOfWork`, or `HydratorFactory` reference
inside a codec. A codec is a pure data converter.

### 5.2 `DocumentCodecRegistry` — composition and discriminator routing

```php
interface DocumentCodecRegistry
{
    /** @param class-string $className */
    public function getCodecFor(string $className): DocumentCodec;

    /**
     * For polymorphic decoding: find the right codec from a raw BSON document
     * (reads the discriminator field if present).
     */
    public function getCodecForDocument(Document $document, ClassMetadata $rootMetadata): DocumentCodec;
}
```

This replaces `HydratorFactory`'s role as a factory/cache of per-class codecs.
It is instantiated once, registered with the DM service container, and injected wherever
needed. It has no filesystem dependency — no code generation.

### 5.3 `HydrationSession` — session-layer adapter wrapping the codec

This is the glue between the stateless codec layer and the stateful session layer. It is
called by `DocumentPersister` when loading documents, and wraps the codec result with
identity-map checks, proxy creation, and lifecycle event dispatch.

```php
final class HydrationSession
{
    public function __construct(
        private readonly IdentityMap $identityMap,    // from refactored UnitOfWork
        private readonly DocumentCodecRegistry $codecs,
        private readonly ProxyFactory $proxyFactory,
        private readonly EventManager $evm,
        private readonly ClassMetadataProvider $metadataProvider,
    ) {}

    /**
     * Decodes a BSON Document into a managed PHP object.
     * Returns an existing identity-map entry if the document is already loaded.
     */
    public function decode(Document $bson, ClassMetadata $rootMetadata, array $hints = []): object
    {
        $codec = $this->codecs->getCodecForDocument($bson, $rootMetadata);
        $metadata = /* resolve from codec */;

        // Identity-map check
        $id = $metadata->getPHPIdentifierValue(/* extract from $bson */);
        if ($existing = $this->identityMap->tryGetById($id, $metadata->rootDocumentName)) {
            if ($hints[Query::HINT_REFRESH] ?? false) {
                // re-hydrate into existing instance
                return $this->rehydrate($existing, $bson, $metadata, $hints);
            }
            return $existing;
        }

        // Decode plain object
        $this->evm->dispatchEvent(Events::preLoad, ...);
        $document = $codec->decode($bson);          // pure data conversion

        // Post-decode session wiring
        $this->wirePlainObject($document, $bson, $metadata, $hints);

        $this->evm->dispatchEvent(Events::postLoad, ...);
        return $document;
    }

    private function wirePlainObject(object $document, Document $bson, ClassMetadata $metadata, array $hints): void
    {
        foreach ($metadata->fieldMappings as $mapping) {
            if ($mapping['type'] === ClassMetadata::ONE && isset($mapping['reference'])) {
                // Replace raw reference data with proxy
                $rawRef = $metadata->propertyAccessors[$mapping['fieldName']]->getValue($document);
                if ($rawRef !== null) {
                    $proxy = $this->proxyFactory->getProxy(/* target class */, /* id */);
                    $metadata->propertyAccessors[$mapping['fieldName']]->setValue($document, $proxy);
                }
            }

            if ($mapping['type'] === ClassMetadata::MANY) {
                // Replace plain array with PersistentCollection
                $rawData = $metadata->propertyAccessors[$mapping['fieldName']]->getValue($document);
                $collection = $this->createPersistentCollection($document, $mapping, $rawData);
                $metadata->propertyAccessors[$mapping['fieldName']]->setValue($document, $collection);
            }
        }

        // Register in identity map + store original snapshot for change tracking
        $this->identityMap->registerManaged($document, /* id */, /* snapshot */);
    }
}
```

### 5.4 `DocumentPersister` after the refactoring

With `Collection` constructed with a per-class codec and `HydrationSession` handling
identity-map + session wiring, `DocumentPersister` collapses significantly:

```
Before                                          After
──────────────────────────────────────────      ──────────────────────────────────────────
executeInserts()                                executeInserts()
  PersistenceBuilder::prepareInsertData()         Collection::insertMany($documents)
  Collection::insertMany($encoded)                ↑ codec.encode() called by driver automatically

load($criteria)                                 load($criteria)
  prepareQueryOrNewObj($criteria)                 prepareQuery($criteria)     (unchanged)
  Collection::findOne($query)                     Collection::findOne($query) (unchanged)
  createDocument($result)                         HydrationSession::decode($bson, $metadata)
    UnitOfWork::getOrCreateDocument()               (identity map + proxy + snapshot in session)
      HydratorFactory::hydrate()

loadAll($criteria)                              loadAll($criteria)
  Collection::find($query)                        Collection::find($query)
  → HydratingIterator($cursor, $uow, ...)         → CodecCursor (provided by driver, uses codec)
                                                    + HydrationSession wired into codec

loadCollection() / loadEmbedManyCollection()    loadCollection()
  HydratorFactory::hydrate($embeddedDoc)          HydrationSession::decode($bson, $embeddedMetadata)
  UnitOfWork::registerManaged(...)                  (handles embedded + register in one call)
```

Remaining responsibilities of `DocumentPersister` after refactoring:
- Query preparation (field name mapping, type conversion for queries) — unchanged
- Filter and discriminator injection into queries — unchanged
- Collection/Bucket handle for the target class — unchanged
- Executing writes (insert, update, delete, upsert) — unchanged
- Locking (optimistic version checks) — unchanged
- Coordinating collection persistence on write — unchanged (out of scope)

### 5.5 What happens to generated hydrators

They are eliminated entirely. `DocumentCodecRegistry` replaces `HydratorFactory`:
- No code generation step.
- No hydrator directory configuration.
- No `AUTOGENERATE_*` strategy constants.
- Codecs are plain PHP classes, instantiable by any DI container.
- The `HydratorInterface` is replaced by `DocumentCodec`.

---

## 6. Handling the Hard Cases

### 6.1 Polymorphism and discriminators

Current: `HydratorFactory` reads the discriminator field to pick the right generated class.

With codecs: `DocumentCodecRegistry::getCodecForDocument(Document, ClassMetadata)` reads
the discriminator field from the `Document` and returns the right codec. All polymorphism
is handled at registry level, not inside individual codec `decode()` methods.

```php
public function getCodecForDocument(Document $document, ClassMetadata $rootMetadata): DocumentCodec
{
    if ($rootMetadata->discriminatorField && $document->has($rootMetadata->discriminatorField['name'])) {
        $discriminatorValue = $document->get($rootMetadata->discriminatorField['name']);
        $concreteClass = $rootMetadata->discriminatorMap[$discriminatorValue];
        return $this->getCodecFor($concreteClass);
    }
    return $this->getCodecFor($rootMetadata->name);
}
```

### 6.2 `EmbedMany` / `ReferenceMany` — lazy vs eager

Current: The hydrator stores raw `mongoData` on a `PersistentCollection` with
`initialized: false`. The collection is only decoded on first access.

With codecs: The codec's `decode()` method for many-associations still returns a
"placeholder" value (the raw BSON array). `HydrationSession::wirePlainObject()` then
replaces that placeholder with a `PersistentCollection` carrying the raw BSON data,
exactly as today. The PersistentCollection triggers `DocumentPersister::loadCollection()`
on first access, which calls `HydrationSession::decode()` for each element.

Lazy loading is preserved; the codec never eagerly decodes collections.

### 6.3 Snapshot for change tracking

Current: `HydratorInterface::hydrate()` returns `$hydratedData` so `UnitOfWork` can store
the original snapshot.

With codecs: The codec returns a fully hydrated `object`. `HydrationSession` computes the
snapshot by reading the field values from the returned object immediately after `decode()`,
before any user code can modify them.

```php
$document = $codec->decode($bson);
$this->wirePlainObject($document, $bson, $metadata, $hints);

// Snapshot: read current field values immediately after hydration
$snapshot = $this->extractSnapshot($document, $metadata);
$this->identityMap->registerManaged($document, $id, $snapshot);
```

This removes the dual-return-value design from `HydratorInterface`.

### 6.4 `alsoLoadFields` / `alsoLoadMethods`

Current: handled inside the generated hydrator before field-level code runs.

With codecs: handled in `HydrationSession::decode()` before calling `codec->decode()`, as
a pre-processing step on the raw `Document`. The codec only sees the normalised BSON; it
does not need to know about `@AlsoLoad`.

### 6.5 `HINT_READ_ONLY`

Current: hydrators check hints to skip `UnitOfWork::registerManaged()`.

With codecs: `HydrationSession` checks hints before calling `identityMap->registerManaged()`.
The codec itself is stateless and does not need to know about hints.

---

## 7. Responsibility Assignment After Refactoring

| Class | Before | After |
|---|---|---|
| `HydratorFactory` | Code generation, hydration orchestration, lifecycle events | **Replaced** by `DocumentCodecRegistry` |
| `GeneratedHydrator` | Field-level decode, embedded recursion, reference proxy | **Replaced** by `DocumentCodec` per class |
| `HydratorInterface` | `hydrate(object, array): array` | **Replaced** by `DocumentCodec` (standard library interface) |
| `HydratingIterator` | Lazy hydration of find cursors | **Replaced** by driver-native `CodecCursor` |
| `PersistenceBuilder::prepareInsertData` | PHP object → `array` for insertMany | **Replaced** by `DocumentCodec::encode()` |
| `PersistenceBuilder::prepareEmbeddedDocumentValue` | Recursive embedded serialisation | **Replaced** by codec composition via registry |
| `PersistenceBuilder` (update methods) | Changeset → atomic operators | **Unchanged** (out of scope) |
| `DocumentCodecRegistry` (new) | — | Factory / cache / discriminator router for codecs |
| `HydrationSession` (new) | — | Identity map, proxy wiring, collection wrapping, events, snapshot |
| `DocumentPersister` | Query prep + load orchestration + write execution | Query prep + write execution; loads via `HydrationSession` |
| `CollectionPersister` | Atomic collection updates | **Unchanged** (out of scope) |

---

## 8. Migration Roadmap

Each step is independently mergeable without breaking existing public API.

| # | Change | BC break? |
|---|---|---|
| 1 | Add `DocumentCodecRegistry` interface + default implementation (backed by existing `ClassMetadata`) | None — new class |
| 2 | Add `DocumentCodec` implementation per document class, generated or hand-written; wire into registry | None — additive |
| 3 | Introduce `HydrationSession`; `DocumentPersister::createDocument()` delegates to it | None — internal change |
| 4 | Use `CodecCursor` from driver in `DocumentPersister::loadAll()` instead of `HydratingIterator` | None — transparent |
| 5 | Pass codec to `Collection` at construction (via `DocumentPersister`); remove `PersistenceBuilder::prepareInsertData` call from `executeInserts` | None — driver handles encoding |
| 6 | Remove `HydratorFactory` code-generation machinery; deprecate `HydratorInterface` | Soft deprecation |
| 7 | Remove `HydratingIterator` | Soft deprecation |
| 8 | (3.0) Remove `HydratorInterface`, `HydratorFactory`, `HydratingIterator` | BC break in major |

Steps 1–5 can all land in a single minor release. Steps 6–7 deprecate old surfaces for 3.0
removal. Application code that references `HydratorFactory` or `HydratorInterface` directly
(rare, as these are `@internal`) sees a soft deprecation only.

---

## 9. What This Achieves

- **No code generation**. Codecs are plain PHP classes. No filesystem writes, no cache
  directories, no stale-file bugs, no `eval()`.
- **Testable in isolation**. A `DocumentCodec` has no `DocumentManager` reference and can be
  unit-tested with a raw `MongoDB\BSON\Document` fixture.
- **Standard interface**. `DocumentCodec` is from `mongodb/mongodb`, not Doctrine-specific.
  Third-party tooling that understands the codec interface works with ODM documents
  automatically.
- **Driver-native lazy iteration**. `CodecCursor` replaces `HydratingIterator` with the
  driver's own lazy-decode cursor, removing one hand-rolled abstraction.
- **Clearer separation**. Encode/decode (codec) is distinct from identity-map / proxy /
  lifecycle-event wiring (session). Each can be extended or replaced independently.
- **Insert encoding via driver**. Passing a codec to `Collection` means `insertMany()` calls
  `encode()` internally; no intermediate `array` is materialised just to be re-encoded by
  the driver.
