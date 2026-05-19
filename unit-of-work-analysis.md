# Unit of Work — Responsibility Analysis and Refactoring Proposal

## 1. The Proposal Under Examination

The stated goal: `DocumentManager` should own all long-lived tracking state (identity map,
original snapshots, scheduling queues). `UnitOfWork` should be a short-lived object,
created at the start of `flush()`, given a snapshot of state, responsible only for
computing changesets and executing database writes, and destroyed when `flush()` returns.

---

## 2. What the Current UnitOfWork Actually Does

The current `UnitOfWork` is 3 000+ lines. Its ~100 private-field mutations can be
partitioned by _when_ they are relevant:

### 2.1 Long-lived state (whole request, survives flush)

| Field | Purpose |
|---|---|
| `$identityMap` | `class → serialized-id → object`. Prevents loading the same document twice. |
| `$documentIdentifiers` | `spl_object_id → scalar-id`. Reverse identity lookup. |
| `$documentStates` | `spl_object_id → STATE_*`. Managed / New / Removed / Detached. |
| `$originalDocumentData` | `spl_object_id → array`. Pristine snapshot from last DB read or flush. Used for dirty-detection at next flush. |
| `$parentAssociations` | `spl_object_id → [mapping, parent, path]`. Embedded document parent chain. |
| `$embeddedDocumentsRegistry` | `spl_object_id → object`. Prevents GC from collecting embedded docs. |
| `$persisters` | Lazy singleton per class. Cached across flushes. |
| `$collectionPersister` | Lazy singleton. Cached across flushes. |
| `$persistenceBuilder` | Lazy singleton. Cached across flushes. |

### 2.2 Flush-scoped state (cleared at the end of every `commit()`)

| Field | Purpose |
|---|---|
| `$scheduledDocumentInsertions` | Documents queued for INSERT. |
| `$scheduledDocumentUpdates` | Documents queued for UPDATE. |
| `$scheduledDocumentUpserts` | Documents queued for UPSERT. |
| `$scheduledDocumentDeletions` | Documents queued for DELETE. |
| `$orphanRemovals` | Embedded / referenced documents to be cascade-deleted. |
| `$scheduledCollectionUpdates` | `PersistentCollection` instances to be atomically updated. |
| `$scheduledCollectionDeletions` | `PersistentCollection` instances to be deleted. |
| `$hasScheduledCollections` | Index: `owner → [collection → collection]`. Efficient check whether an owner has pending collection work. |
| `$documentChangeSets` | `spl_object_id → [field → [old, new]]`. Computed during flush, consumed by persisters. |
| `$visitedCollections` | Collections visited during changeset computation; snapshotted after flush. |
| `$scheduledForSynchronization` | Documents using `DEFERRED_EXPLICIT` change tracking, marked explicitly by user code or collection mutations. Cleared after flush. |
| `$commitsInProgress` | Guard counter. Incremented/decremented around `commit()`. |

### 2.3 Functional categories (method count)

| Responsibility | Approx. methods |
|---|---|
| Identity map & document state queries | 11 |
| Scheduling document operations (insert/update/delete) | 10 |
| Change detection & changeset computation | 9 |
| Flush orchestration (`commit()`, `doCommit()`, transactions) | 10 |
| Cascade operations (persist, remove, detach, merge, refresh) | 10 |
| Collection scheduling & state management | 13 |
| Lifecycle callbacks & event dispatch | 4 |
| Persister factory / registry | 4 |
| Embedded doc & parent tracking | 3 |
| Orphan removal | 2 |
| Proxy / lazy-load support | 2 |
| Locking | 2 |
| Utilities & introspection | ~8 |

This is nine distinct concerns in one class.

---

## 3. Evaluation of the Proposal

### 3.1 What the proposal gets right

The intuition maps directly onto a well-established distinction in _Patterns of Enterprise
Application Architecture_ (Fowler, 2002):

- The **Identity Map** pattern says one object per DB row per session. This is inherently
  a _session-scoped_ concern, not a _transaction-scoped_ one.
- The **Unit of Work** pattern says "track what changed in this business transaction and
  write it out atomically at the end". Fowler explicitly scopes the UoW to a single
  logical transaction, not to a session.

The current code conflates the two. The `UnitOfWork` class is in practice a combination
of an _Identity Map_ + _Change Tracker_ (session-scoped) with a _Unit of Work_
(transaction-scoped). Moving the session-scoped half to `DocumentManager` is architecturally
correct.

The JPA/Hibernate analogy reinforces this:

- Hibernate `Session` = identity map + long-lived state + flush entry point.
- Hibernate's internal `ActionQueue` = what fires during flush (the "real" UoW).
- There is no long-lived `UnitOfWork` class in Hibernate; the equivalent of our
  `$scheduledDocumentInsertions` etc. lives in `ActionQueue`, created fresh for every
  flush inside `Session`.

Our current design inverts this: the `UnitOfWork` is the session, and `DocumentManager`
is a thin façade in front of it.

### 3.2 The critical blocker: `PersistentCollectionTrait`

A fully short-lived UoW faces one hard constraint. `PersistentCollectionTrait` stores
`$this->uow = $dm->getUnitOfWork()` at collection initialisation time and then calls
back into it _throughout the document's lifetime_, not only during flush:

| Call site | When it happens |
|---|---|
| `$this->uow->loadCollection($this)` | On first iteration — lazy load, outside flush. |
| `$this->uow->scheduleForSynchronization($owner)` | On any mutation — outside flush (DEFERRED_EXPLICIT). |
| `$this->uow->scheduleOrphanRemoval($element)` | On `remove()` / `clear()` — outside flush. |
| `$this->uow->unscheduleOrphanRemoval($value)` | On `add()` (re-adding) — outside flush. |
| `$this->uow->scheduleCollectionDeletion($this)` | On `clear()` — outside flush. |

If `UnitOfWork` is destroyed after `flush()` returns, every `PersistentCollection`
created during or before that flush would hold a stale reference to a dead object.
The collection callbacks would then either throw or silently do nothing.

This is not a minor implementation detail — it affects every reference/embed collection
in every loaded document tree. Any short-lived UoW proposal must solve this.

### 3.3 Secondary constraints

- `UnitOfWork` implements `PropertyChangedListener` and registers itself as a listener on
  documents using the `NOTIFY` change tracking policy
  (`$document->addPropertyChangedListener($this)`). A destroyed UoW would silently
  swallow change notifications. The listener reference must survive the flush boundary.
- `$originalDocumentData` must persist between flushes. After flush #1 the snapshots are
  updated to reflect what was written; flush #2 diffs against those updated snapshots.
  If DM does not own this data, every second flush would see a full document as "changed".

---

## 4. The Three Viable Architectures

### Architecture A — True Short-Lived UoW (user's proposal, fully realised)

Solve the blocker by moving all collection/orphan scheduling to `DocumentManager` via a
narrow interface:

```
DocumentManager implements CollectionScheduler, OrphanRemovalTracker
  owns:
    IdentityMap
    DocumentStateRegistry (states + identifiers + original data)
    SchedulingQueues (insert/update/upsert/delete/orphan)
    CollectionSchedulingQueues (update/delete/hasScheduled/visited)
    ParentAssociationRegistry
    EmbeddedDocumentRegistry
    (PropertyChangedListener implementation)

  flush():
    $uow = new UnitOfWork($this, ...)   ← created here
    $uow->execute()                     ← computes changesets, calls persisters
    ← destroyed here

PersistentCollectionTrait
  holds: DocumentManager (already does)
  routes scheduleCollectionDeletion() etc. → DocumentManager
  (no longer holds UnitOfWork reference)
```

**Advantages:**
- Clean separation: DM = session, UoW = atomic transaction executor.
- UoW becomes fully testable as a pure value: given this identity map + these snapshots +
  these queues, produce these DB writes.
- No state leaks between flushes.

**Disadvantages:**
- PersistentCollection changes are a public-ish API change (the constructor signature
  `PersistentCollectionTrait::__construct(DM, UoW)` becomes `__construct(DM)`).
- The `NOTIFY` property-changed listener now lives on DM, which some users extend directly.
- Largest migration surface of the three options.

---

### Architecture B — Extract a `FlushOrchestrator` (recommended middle path)

Keep UoW long-lived, but extract its flush-only logic into a separate disposable object
that UoW creates internally at commit time. The public API and all PersistentCollection
references stay unchanged.

```
UnitOfWork (long-lived, slimmed)
  owns (long-lived only):
    IdentityMap
    DocumentStateRegistry
    OriginalDocumentData
    ParentAssociationRegistry
    EmbeddedDocumentRegistry
    SchedulingQueues (populated by persist/remove/collection callbacks as now)
    PersisterRegistry (lazy singleton caches)
    PropertyChangedListener

  commit():
    $flush = new FlushOrchestrator($this, $dm, $persisters, ...)
    $flush->execute()
    ← FlushOrchestrator destroyed here; UoW retains only long-lived state

FlushOrchestrator (short-lived, created per commit)
  owns (flush-scoped):
    DocumentChangeSets
    VisitedCollections
    CommitsInProgress guard
  executes:
    computeChangeSets()
    doCommit() → calls persisters
    transaction handling
    lifecycle event dispatch
    cleanup of flush-scoped scheduling queues on UoW after success
```

**Advantages:**
- PersistentCollection is unchanged.
- `UnitOfWork`'s public surface area stays the same (no BC break at all).
- Flush logic becomes independently testable without constructing a full document graph.
- Separation of "what changed" (UoW) from "how to write it" (FlushOrchestrator) is clean.
- `$documentChangeSets`, `$visitedCollections`, and transaction state are scoped to
  exactly one flush without requiring any caller changes.

**Disadvantages:**
- UoW still mixes identity-map and scheduling concerns (though far less than now).
- Does not achieve the "pure session" split the user envisions — that requires
  Architecture A's additional phase.

---

### Architecture C — Slim UoW with Extracted `IdentityMap` Object

Introduce a standalone `IdentityMap` object that both DM and UoW can hold a reference
to. This is the minimal structural change that starts decoupling the two classes.

```
IdentityMap
  owns: $map[class][serialized-id] = object
        $documentIdentifiers[spl_id] = scalar-id
        $documentStates[spl_id] = STATE_*
        $originalDocumentData[spl_id] = []

DocumentManager
  owns: IdentityMap (shared reference)
  delegates: getReference(), getPartialReference() → IdentityMap

UnitOfWork
  owns: IdentityMap (shared reference)
  owns: scheduling queues, cascades, flush logic as now
```

**Advantages:**
- Smallest change, lowest risk.
- IdentityMap becomes independently testable.
- Prepares for Architecture A/B without committing to them.

**Disadvantages:**
- Does not meaningfully reduce UoW's complexity.
- The fundamental responsibility confusion remains.

---

## 5. Recommended Approach

Adopt **Architecture B**, with Architecture A as the declared long-term target.

The reasoning:

1. Architecture B is fully BC. The public signatures of `UnitOfWork`, `DocumentManager`,
   and `PersistentCollectionTrait` do not change. It can land in a minor release.

2. `FlushOrchestrator` answers the user's intuition directly: a unit of work _is_ the
   act of flushing, scoped to a single `commit()` call.

3. Once `FlushOrchestrator` exists and owns all flush-scoped state, Architecture A
   (moving scheduling queues to DM) becomes a straightforward follow-up: redirect
   collection/orphan callbacks from UoW to DM, then pass the queues into
   `FlushOrchestrator` at construction time.

---

## 6. Concrete Responsibility Assignment

### `DocumentManager` (session façade, long-lived)

| Responsibility | Notes |
|---|---|
| MongoDB connection, databases, collections | Already there. |
| Configuration | Already there. |
| Service registry (metadata, hydrators, proxies, repositories) | Already there, to be improved per `dependency-analysis.md`. |
| `persist()`, `remove()`, `detach()`, `merge()`, `refresh()` entry points | Already delegates to UoW; stays as-is. |
| `flush()` | Delegates to UoW `commit()` which internally creates `FlushOrchestrator`. |
| `getReference()`, `getPartialReference()` | Delegates to UoW identity-map lookups. |
| `contains()`, `isUninitializedObject()` | Delegates to UoW. |
| _Architecture A addition:_ `CollectionScheduler` interface | Routes PersistentCollection callbacks when UoW is short-lived. |

### `UnitOfWork` (after Architecture B slimming)

| Responsibility | Notes |
|---|---|
| Identity map | Owns `$identityMap`, `$documentIdentifiers`, `$documentStates`. |
| Original data snapshots | Owns `$originalDocumentData`. Updated after every flush. |
| Scheduling queues | `$scheduledDocument*`, `$scheduledCollection*`, `$orphanRemovals`. Populated by persist/remove/collection callbacks; consumed by `FlushOrchestrator`. |
| Cascade operations | `doPersist`, `doRemove`, `doDetach`, `doMerge`, `doRefresh` and their cascade variants. |
| Embedded doc tracking | `$parentAssociations`, `$embeddedDocumentsRegistry`. |
| `PropertyChangedListener` | Registers on NOTIFY documents; records `$documentChangeSets` entries. |
| Persister registry | Lazy singleton caches for `DocumentPersister`, `CollectionPersister`, `PersistenceBuilder`. (Move to `PersisterRegistry` per `dependency-analysis.md` as a separate step.) |
| `commit()` entry point | Creates `FlushOrchestrator`, calls `execute()`, clears flush-scoped queues. |

### `FlushOrchestrator` (new, short-lived, created per `commit()`)

| Responsibility | Notes |
|---|---|
| Changeset computation | `computeChangeSets()`, `computeOrRecomputeChangeSet()`, `computeAssociationChanges()` |
| Write execution | `executeInserts()`, `executeUpdates()`, `executeUpserts()`, `executeDeletions()` |
| Collection write execution | `executeCollectionUpdates()`, `executeCollectionDeletions()` |
| Lifecycle event dispatch | `preFlush`, `onFlush`, `postFlush`, per-document hooks |
| Transaction coordination | Session handling, retry logic, `withTransaction()` |
| Owns (flush-only state) | `$documentChangeSets`, `$visitedCollections`, `$commitsInProgress` |
| Takes as constructor input | UoW reference (for identity map reads), persisters, scheduling queues snapshot, event manager |
| Returns / side-effects | Updates `$originalDocumentData` on UoW; clears scheduling queues on UoW; fires events. |

### `DocumentPersister` / `CollectionPersister`

No change to responsibilities. They receive and act on individual documents / collections;
they do not need to know about flush orchestration or identity-map concerns.

### `PersistentCollectionTrait`

| Responsibility | Notes |
|---|---|
| Lazy loading | Calls `UnitOfWork::loadCollection()` (or via DM in Architecture A). |
| Mutation tracking | Calls `scheduleCollectionDeletion`, `scheduleOrphanRemoval` etc. — on UoW now, on DM in Architecture A. |
| `isOrphanRemovalEnabled` check | Uses `DocumentManager::getClassMetadata()`. Already uses DM, not UoW. |

---

## 7. Migration Roadmap

| # | Change | Target | BC break? |
|---|---|---|---|
| 1 | Extract `IdentityMap` as a standalone value object; UoW wraps it | Architecture C | None |
| 2 | Group flush-scoped fields into a `FlushState` DTO inside UoW | Prep for B | None |
| 3 | Extract `FlushOrchestrator`; `UnitOfWork::commit()` delegates to it | Architecture B | None |
| 4 | Move `computeChangeSets()` and all `execute*()` methods into `FlushOrchestrator` | Architecture B | None |
| 5 | Move transaction coordination into `FlushOrchestrator` | Architecture B | None |
| 6 | Introduce `CollectionScheduler` interface; implement on DM | Prep for A | None |
| 7 | `PersistentCollectionTrait` routes scheduling to `CollectionScheduler` (DM), not UoW | Architecture A step | Soft change (constructor sig) |
| 8 | Move scheduling queues from UoW to DM; `FlushOrchestrator` receives them from DM | Architecture A | None externally |
| 9 | `UnitOfWork::commit()` becomes `DocumentManager::flush()` creating `FlushOrchestrator` directly | Architecture A complete | None (DM::flush() already exists) |
| 10 | Deprecate `UnitOfWork` as a public API surface; expose only via interfaces | Cleanup | Soft deprecation |

Steps 1–5 are purely internal UoW refactoring with zero external impact and can land in
a single minor release. Steps 6–9 complete the move to Architecture A in a subsequent
minor release (or 3.0 if the PersistentCollection constructor signature is considered
public API).

---

## 8. What This Achieves

After step 5 (Architecture B complete):

- `FlushOrchestrator` is a pure executor: given a snapshot of scheduled operations,
  compute changesets and write to MongoDB. It holds no persistent state and can be
  tested in isolation without a document graph.
- `UnitOfWork` becomes a clean _session state tracker_: identity map, original data,
  scheduling queues. No flush machinery.
- The `flush()` method clearly expresses the architecture: _take what's tracked, execute
  it, discard the executor_.

After step 9 (Architecture A complete):

- `DocumentManager` is the session: it knows what's loaded, what's dirty, what's new.
- `FlushOrchestrator` is the transaction: it knows how to commit a given state to the DB.
- `UnitOfWork` becomes an optional compatibility shim or is removed entirely.
- Developers can extend or replace either the session layer (custom identity map) or the
  flush layer (custom write strategies) independently.
