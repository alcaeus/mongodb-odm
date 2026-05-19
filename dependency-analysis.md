# Dependency Analysis: Doctrine MongoDB ODM

## 1. Current Architecture

### 1.1 Boot Sequence

`DocumentManager` is the composition root. Its constructor instantiates every subsystem
in a fixed order:

```
DocumentManager::__construct()
  1. Client, Configuration, EventManager         (primitives / provided externally)
  2. ClassNameResolver                            (based on proxy strategy in config)
  3. ClassMetadataFactory::__construct()          (empty), then setter-injected with $this
  4. HydratorFactory($this, $evm, ...)
  5. UnitOfWork($this, $evm, $hydratorFactory)
  6. SchemaManager($this, $metadataFactory)
  7. ProxyFactory($this, ...)  →  calls $this->getUnitOfWork() internally
  8. RepositoryFactory                            (retrieved from config, not instantiated)
```

### 1.2 Full Dependency Graph

```
DocumentManager
├── owns → ClassMetadataFactory
│            └── needs → DocumentManager (setter-injected after construction)
│            └── needs → Configuration   (setter-injected after construction)
├── owns → HydratorFactory
│            └── needs → DocumentManager
│            └── needs → EventManager
├── owns → UnitOfWork
│            ├── needs → DocumentManager
│            ├── needs → HydratorFactory
│            ├── lazy  → PersistenceBuilder(DocumentManager, UnitOfWork)
│            ├── lazy  → CollectionPersister(DocumentManager, PersistenceBuilder, UnitOfWork)
│            └── lazy  → DocumentPersister(PersistenceBuilder, DocumentManager, UnitOfWork,
│                                          HydratorFactory, ClassMetadata)
│                          └── eager → CollectionPersister (via uow->getCollectionPersister())
├── owns → SchemaManager
│            ├── needs → DocumentManager
│            └── needs → ClassMetadataFactory
└── owns → ProxyFactory  (one of three implementations)
             ├── needs → DocumentManager
             └── eager → UnitOfWork (via $dm->getUnitOfWork() in constructor)
```

### 1.3 What Each Subsystem Actually Uses from `DocumentManager`

`DocumentManager` is passed as a convenience façade, but each consumer only needs a
narrow slice of it:

| Consumer | Methods actually called on `DocumentManager` |
|---|---|
| `ClassMetadataFactory` | `getConfiguration()`, `getEventManager()` |
| `HydratorFactory` | `getClassMetadata()`, `getClassNameForAssociation()`, `getReference()`, `getRepository()`, `getUnitOfWork()`, `getHydratorFactory()`, `getConfiguration()`, event arg construction |
| `UnitOfWork` | `getClassMetadata()`, `getDocumentCollection()`, `getDocumentDatabase()`, `getConfiguration()`, `getEventManager()`, `createReference()`, `getReference()`, `getRepository()` |
| `DocumentPersister` | `getClassMetadata()`, `getDocumentCollection()`, `getDocumentDatabase()`, `getConfiguration()`, `createReference()`, `getReference()`, `getUnitOfWork()` |
| `CollectionPersister` | `getClassMetadata()`, `getDocumentCollection()`, `createReference()`, `getConfiguration()` |
| `PersistenceBuilder` | `getClassMetadata()`, `createReference()`, `getConfiguration()` |
| `SchemaManager` | `getClassMetadata()`, `getDocumentCollection()`, `getDocumentDatabase()` |
| `ProxyFactory` | `getUnitOfWork()`, `getClassMetadata()`, `getEventManager()` |

---

## 2. Circular Dependencies

### 2.1 The God-Object Coupling (highest severity)

Every subsystem holds a reference to `DocumentManager` and calls `getX()` on it as a
service locator. This creates a star-shaped dependency graph with `DocumentManager` at
the centre. The practical consequences:

- Replacing any single subsystem (e.g. `HydratorFactory`) requires the replacement to
  accept the full `DocumentManager` interface, dragging in everything else.
- Unit-testing any subsystem requires constructing or mocking a full `DocumentManager`.
- The `protected` constructor and `static create()` factory prevent consumers from
  substituting only what they need.

### 2.2 `UnitOfWork` ↔ Persisters (mutual ownership)

```
UnitOfWork
  ├── creates → DocumentPersister   (passing $this as UnitOfWork)
  ├── creates → CollectionPersister (passing $this as UnitOfWork)
  └── creates → PersistenceBuilder  (passing $this as UnitOfWork)

DocumentPersister  → holds UnitOfWork, holds CollectionPersister
CollectionPersister → holds UnitOfWork
PersistenceBuilder  → holds UnitOfWork
```

`UnitOfWork` is simultaneously the identity-map/change-tracking coordinator **and** a
factory/registry for its own consumers. The persisters then call back into `UnitOfWork`
for state lookups, creating a genuine bidirectional dependency.

### 2.3 `ClassMetadataFactory` — Setter Injection Anti-Pattern

`ClassMetadataFactory` has an empty constructor and is put into a semi-constructed state
until `setDocumentManager()` and `setConfiguration()` are called:

```php
$this->metadataFactory = new $metadataFactoryClassName();  // invalid state
$this->metadataFactory->setDocumentManager($this);         // valid state only now
$this->metadataFactory->setConfiguration($this->config);
```

This makes the class impossible to use safely without implicit knowledge of the required
call order.

### 2.4 `ProxyFactory` — Construction-Time Timing Dependency

All three proxy factory implementations call `$dm->getUnitOfWork()` in their
constructors. This works because `UnitOfWork` is instantiated before `ProxyFactory` in
`DocumentManager::__construct()`, but it is a fragile temporal dependency: swapping the
order would cause a null-dereference.

---

## 3. Proposed Refactoring

### 3.1 Guiding Principles

1. **No BC breaks.** All public API (`DocumentManager::create()`, `getUnitOfWork()`,
   `getHydratorFactory()`, etc.) stays intact.
2. **Introduce interfaces where none exist** so implementations can be swapped.
3. **Replace service-locator calls with targeted interfaces** so each subsystem only
   depends on what it actually uses.
4. **Adopt PSR-11 (`ContainerInterface`)** as the backbone for the internal service
   registry, progressively replacing ad-hoc `getX()` forwarding on `DocumentManager`.

### 3.2 Step 1 — Introduce Narrow Service Interfaces

Add focused interfaces that represent the slices of `DocumentManager` each consumer
actually needs. These live in a new `ServiceLocator` (or `Infrastructure`) namespace.

```php
namespace Doctrine\ODM\MongoDB;

/** Provides ClassMetadata for a given class name. */
interface ClassMetadataProvider
{
    /** @param class-string<T> $className
     *  @return ClassMetadata<T>
     *  @template T of object */
    public function getClassMetadata(string $className): Mapping\ClassMetadata;
}

/** Provides MongoDB Collection / Database objects for a given document class. */
interface CollectionProvider
{
    public function getDocumentCollection(string $className): \MongoDB\Collection;
    public function getDocumentDatabase(string $className): \MongoDB\Database;
}

/** Resolves references between documents. */
interface ReferenceProvider
{
    public function getReference(string $documentName, mixed $identifier): object;
    public function createReference(object $document, array $referenceMapping): array;
    public function getClassNameForAssociation(array $mapping, mixed $data): string;
}
```

`DocumentManager` implements all three (and already satisfies the contracts — no
behaviour change). Each subsystem then typehints against the narrowest interface it
needs:

| Consumer | Before | After |
|---|---|---|
| `SchemaManager` | `DocumentManager` | `ClassMetadataProvider & CollectionProvider` |
| `CollectionPersister` | `DocumentManager` | `ClassMetadataProvider & CollectionProvider & ReferenceProvider` |
| `PersistenceBuilder` | `DocumentManager` | `ClassMetadataProvider & ReferenceProvider` |

### 3.3 Step 2 — Introduce a PSR-11 Internal Service Container

Add a lightweight PSR-11 container that owns all internal services. `DocumentManager`
becomes a thin façade that delegates to the container instead of directly storing
every service as a private field.

```php
use Psr\Container\ContainerInterface;

final class DocumentManagerServices implements ContainerInterface
{
    private array $factories  = [];
    private array $instances  = [];

    public function register(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
    }

    public function get(string $id): mixed
    {
        return $this->instances[$id] ??= ($this->factories[$id])($this);
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || isset($this->instances[$id]);
    }
}
```

`DocumentManager::__construct()` becomes a registration site rather than an
instantiation cascade:

```php
protected function __construct(?Client $client, ?Configuration $config, ?EventManager $evm)
{
    $this->config       = $config ?? new Configuration();
    $this->eventManager = $evm    ?? new EventManager();
    $this->client       = $client ?? new Client('mongodb://127.0.0.1', [], $this->config->getDriverOptions());

    $services = new DocumentManagerServices();

    $services->register(ClassMetadataFactoryInterface::class, function () {
        $factory = new ($this->config->getClassMetadataFactoryName())();
        $factory->setDocumentManager($this);
        $factory->setConfiguration($this->config);
        // … cache etc.
        return $factory;
    });

    $services->register(HydratorFactory::class, fn() => new HydratorFactory(
        $this, $this->eventManager,
        $this->config->getHydratorDir(),
        $this->config->getHydratorNamespace(),
        $this->config->getAutoGenerateHydratorClasses(),
    ));

    $services->register(UnitOfWork::class, fn() => new UnitOfWork(
        $this, $this->eventManager, $services->get(HydratorFactory::class),
    ));

    $services->register(SchemaManager::class, fn() => new SchemaManager(
        $this, $services->get(ClassMetadataFactoryInterface::class),
    ));

    $services->register(ProxyFactory::class, fn() => match (true) {
        $this->config->isNativeLazyObjectEnabled()   => new NativeLazyObjectFactory($this),
        $this->config->isLazyGhostObjectEnabled()    => new LazyGhostProxyFactory($this, …),
        default                                       => new StaticProxyFactory($this),
    });

    $this->services = $services;
}

// Existing public API unchanged — delegates to container:
public function getUnitOfWork(): UnitOfWork
{
    return $this->services->get(UnitOfWork::class);
}
```

**BC impact:** none. All existing `getUnitOfWork()`, `getHydratorFactory()`, etc.
methods remain; they now delegate to `$this->services->get(…)` instead of returning
a stored private field. Callers see no change.

**Benefits:**
- Services are lazy by default — only instantiated on first access.
- Boot order is encoded in the factory lambdas, not in the constructor's line order.
- Third parties (and tests) can replace individual services by calling
  `DocumentManager::create()` with a pre-configured container, without subclassing.

### 3.4 Step 3 — Extract `PersisterRegistry` from `UnitOfWork`

Move persister creation out of `UnitOfWork` into a dedicated registry. `UnitOfWork`
receives the registry as a constructor argument:

```php
final class PersisterRegistry
{
    /** @var array<class-string, DocumentPersister> */
    private array $documentPersisters = [];
    private ?CollectionPersister $collectionPersister = null;
    private ?PersistenceBuilder $persistenceBuilder   = null;

    public function __construct(
        private readonly ClassMetadataProvider $metadataProvider,
        private readonly CollectionProvider    $collectionProvider,
        private readonly UnitOfWork            $uow,
        private readonly HydratorFactory       $hydratorFactory,
    ) {}

    public function getDocumentPersister(string $documentName): DocumentPersister
    {
        return $this->documentPersisters[$documentName] ??= new DocumentPersister(
            $this->getPersistenceBuilder(),
            $this->metadataProvider,
            $this->collectionProvider,
            $this->uow,
            $this->hydratorFactory,
            $this->metadataProvider->getClassMetadata($documentName),
        );
    }

    public function getCollectionPersister(): CollectionPersister { … }

    public function getPersistenceBuilder(): PersistenceBuilder { … }
}
```

`UnitOfWork` then typehints against `PersisterRegistry` rather than creating persisters
itself. The circular `UnitOfWork → creates → DocumentPersister → holds → UnitOfWork`
cycle becomes:

```
PersisterRegistry → holds → UnitOfWork  (one direction only)
UnitOfWork        → holds → PersisterRegistry
```

This is still a bidirectional reference, but it is explicit and the creation logic is
no longer intermixed with change-tracking logic.

### 3.5 Step 4 — Fix `ClassMetadataFactory` Initialization

Replace the two-step setter injection with a single-step factory function registered
in the container (shown in Step 2 above). The publicly visible API of
`ClassMetadataFactory` stays the same; only `DocumentManager`'s wiring changes.

As a second phase (minor BC, semver-minor), the `setDocumentManager()` and
`setConfiguration()` setter methods can be deprecated in favour of constructor
arguments.

### 3.6 Step 5 — Decouple `ProxyFactory` from `UnitOfWork` at Construction Time

The proxy factories currently call `$dm->getUnitOfWork()` in their constructors
to store a reference eagerly. With the PSR-11 container (Step 2), both `ProxyFactory`
and `UnitOfWork` are registered as lazy services. The proxy factory can receive a
`Closure` instead of a hard reference:

```php
// In the container registration:
$services->register(ProxyFactory::class, fn() => new LazyGhostProxyFactory(
    dm:  $this,
    uow: fn() => $services->get(UnitOfWork::class),  // lazy closure
    …
));
```

Inside `LazyGhostProxyFactory`, the stored callable is only invoked the first time a
proxy is actually needed, by which point the container has already resolved `UnitOfWork`.
The timing dependency disappears entirely.

---

## 4. Migration Roadmap

The steps are ordered so that each one is independently mergeable and non-breaking.

| # | Change | BC break? | Risk |
|---|---|---|---|
| 1 | Add `ClassMetadataProvider`, `CollectionProvider`, `ReferenceProvider` interfaces; `DocumentManager` implements them | None | Low |
| 2 | Update `SchemaManager`, `CollectionPersister`, `PersistenceBuilder` to typehint narrower interfaces | None (DM still satisfies them) | Low |
| 3 | Introduce `DocumentManagerServices` (PSR-11 container); wire `DocumentManager` to use it internally | None | Medium |
| 4 | Add `psr/container` to `require` in `composer.json` | None | Low |
| 5 | Extract `PersisterRegistry`; inject into `UnitOfWork` | None (existing `getDocumentPersister()` on UoW can delegate) | Medium |
| 6 | Pass lazy closure to `ProxyFactory` instead of eagerly calling `getUnitOfWork()` | None | Low |
| 7 | Deprecate `ClassMetadataFactory` setter methods; add constructor args | Soft deprecation | Low |
| 8 | (3.0) Remove deprecated setters; make `DocumentManagerServices` replaceable via `create()` | BC break in major | — |

Steps 1–6 can all land in a minor release. Step 7 prepares for the 3.0 BC break at
Step 8.

---

## 5. What This Does Not Change

- The public `DocumentManager` API (`getUnitOfWork()`, `getHydratorFactory()`,
  `getClassMetadata()`, `persist()`, `flush()`, etc.) stays identical.
- `DocumentManager::create()` signature is unchanged.
- No changes to document mapping or query building.
- No changes to generated hydrator or proxy classes.
- The internal PSR-11 container is an implementation detail; it is not exposed through
  any public API.

The refactoring is additive. Existing application code and integrations (Symfony bundle,
Laminas module, etc.) require zero changes.
