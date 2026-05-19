# ODM Persistence Cycle: History, Limitations, and the Road Ahead

---

## 1. ORM Heritage: What We Inherited

Doctrine MongoDB ODM was forked from Doctrine ORM. The ORM was designed for relational
databases, where every row in every table is a first-class entity with its own identity.
Several of those assumptions followed the fork unchanged:

**Embedded documents are treated as entities.**
Every embedded object gets its own identity-map entry. Its changes are computed separately
from the root document. Its updates are dispatched as separate database writes after the
root document write completes. This is the relational model ("every row is an entity")
applied to a data model where nesting is the fundamental design feature.

**The unit of change detection is the field, not the document.**
The ORM's unit of work diffed individual columns. ODM does the same: it walks every
field mapping, computes per-field changesets, and turns those into MongoDB atomic
operators (`$set`, `$unset`, `$inc`). This produces efficient partial updates but it
also means the ODM thinks in fields, not in documents.

**Collections are treated as relationships.**
The ORM manages the join table as a side effect of persisting a collection. ODM carried
this over: embedded and referenced collections are "scheduled" as separate work items and
written after the owner document, mirroring how the ORM flushes relationship tables after
entity rows.

---

## 2. The Current Persistence Cycle (What Actually Happens on Flush)

For a single root document with embedded collections, a flush triggers:

```
1. DocumentPersister::update($rootDoc)
   └─ $set / $unset / $inc on changed scalar fields
   └─ $set on atomicSet / atomicSetArray collections  ← included here
   └─ $set on changed embedded scalar fields (dot notation: "address.city")
      (one updateOne query)

2. CollectionPersister::update($rootDoc, $nonAtomicCollections)
   For each pushAll / addToSet collection:
     a. deleteElements:  $unset items.N + $pull null  (one updateOne per collection)
     b. insertElements:  $push {$each: [...]}          (one updateOne per collection)
   For each set / setArray collection:
     $set {collectionPath: [...]}                      (one updateOne per batch)

3. CollectionPersister::delete($rootDoc, $deletedCollections)
   $unset {collectionPath: true}                       (one updateOne per batch)
```

A document with two modified `pushAll` collections triggers **five** separate
`updateOne` calls against MongoDB. Each is individually atomic, but they are not atomic
with each other, and each one is a full network round trip.

---

## 3. The Path Conflict Wall

MongoDB rejects any update document where two operators touch overlapping field paths.
This produces a hard constraint on what the ODM can express in a single write:

```js
// REJECTED by MongoDB — "items" and "items.0.name" conflict
db.col.updateOne({_id: id}, {
  $push:  { "items": { _id: 99, name: "new" } },
  $set:   { "items.0.name": "updated existing" }
})
```

The conflict arises because `$push` claims ownership of the entire `items` path, and
`$set` claims a sub-path of it simultaneously. MongoDB cannot determine a safe execution
order.

**Current workaround: `atomicSet` / `atomicSetArray`.**
By replacing the entire array with a single `$set`, there are no sub-path operators and
no conflict. But this comes at a cost: the entire array is read into PHP, modified, and
written back in full — no granular diffing, no `$push`, no `$addToSet`.

This is why the two "atomic" strategies exist as separate mapping options rather than
being the default. They are the escape hatch, not the happy path.

---

## 4. What MongoDB Now Provides

### 4.1 Array Filters (MongoDB 3.6+)

```js
db.orders.updateOne(
  { _id: orderId },
  { $set: { "items.$[item].status": "shipped" } },
  { arrayFilters: [{ "item._id": { $in: [1, 3] } }] }
)
```

Array filters allow targeting specific array elements by condition rather than by index.
The positional filtered operator `$[<identifier>]` acts as a placeholder resolved at
query time by the server.

**What this unlocks for the ODM:**
- Update specific embedded documents inside a collection without knowing their array index.
- Replace the current "compute index, write `items.N.field`" approach (fragile under
  concurrent writes) with "match element by `_id`, write that element's field".
- Multiple array elements can be updated in a single write if they share a filter.
- Deeply nested arrays: `items.$[item].tags.$[tag].label` can target a specific tag
  inside a specific order item in one shot.

### 4.2 Update Pipelines (MongoDB 4.2+)

```js
db.orders.updateOne(
  { _id: orderId },
  [
    { $set: { status: "processing", updatedAt: "$$NOW" } },
    { $set: { "items": {
        $map: {
          input: "$items",
          as:    "item",
          in: {
            $mergeObjects: [
              "$$item",
              { $cond: [
                  { $eq: ["$$item._id", targetItemId] },
                  { quantity: newQty, subtotal: { $multiply: [newQty, "$$item.price"] } },
                  {}
              ]}
            ]
          }
        }
    }}}
  ]
)
```

An update pipeline uses aggregation stages (`$set`, `$unset`, `$replaceWith`, `$addFields`)
as the update expression. Later pipeline stages can reference values set by earlier stages.
Crucially, pipeline stages do not conflict with each other — each stage produces a new
document state that the next stage reads.

**What this unlocks for the ODM:**
- A single write can express: change scalar fields, update a specific embedded document,
  and add a new element to a collection — with no path conflicts.
- Can reference current field values to compute new ones (e.g., increment based on a
  related field, compute a derived field on write).
- The path conflict wall disappears: scalar field `$set` and array element updates live
  in separate pipeline stages that never share a path namespace.
- Can conditionally update fields based on current document state, removing the need for
  a read-before-write in several locking / versioning scenarios.

---

## 5. Future Work

### 5.1 Single-write flush for the full document tree

**Today:** root doc update + N collection writes = N+1 `updateOne` calls.

**Goal:** one `updateOne` with an update pipeline that expresses all changes atomically.

The pipeline would be assembled from the full changeset:
- Stage 1: `$set` / `$unset` for changed scalar fields.
- Stage 2+: one stage per modified embedded collection, using `$set` with `$map` /
  `$mergeObjects` to update changed elements and `$concatArrays` to add new ones.

The root document becomes the true unit of atomicity, which is what the MongoDB document
model promises.

### 5.2 Array filters for identity-based embedded document updates

When a specific embedded document changes, instead of:
```
$set: { "items.2.name": "new name" }   ← fragile: index computed at flush time
```
use:
```
$set: { "items.$[item].name": "new name" },
arrayFilters: [{ "item._id": embeddedId }]
```

The changeset builder becomes: "which embedded docs changed, and what are their
identifiers" — not "at what index do they currently sit". This is both more correct
(concurrent inserts cannot shift the wrong element into position) and more natural to
derive from a PHP object graph.

### 5.3 Retire the `atomicSet` / `atomicSetArray` workarounds

These strategies were introduced specifically to work around the path conflict problem.
With update pipelines, every collection update can be part of the root document write
without conflict. The distinction between "atomic" and "non-atomic" collection strategies
becomes an implementation detail of how the pipeline stage is assembled, not a
user-facing mapping option.

The strategies would be deprecated and the pipeline-based approach would become the only
strategy, with the server enforcing atomicity by default.

### 5.4 Treat embedded documents as owned value objects, not tracked entities

**Today:** Embedded documents are registered in the identity map, have their own
change-tracking entries, and are scheduled as separate flush work items. This is the ORM
relational model applied unchanged.

**Goal:** The root document is the aggregate root. Embedded documents are part of its
state. The changeset is computed as a diff of the entire document tree from root down, and
the single resulting update pipeline expresses that entire diff. No separate scheduling,
no separate writes, no identity-map entries for embedded objects.

This aligns with how MongoDB actually stores data (a document is one atomic blob), how
application developers think about document databases (the document is the boundary), and
with established DDD vocabulary (aggregate root / value object).

### 5.5 Solve the `pushAll` two-query pattern

**Today:** Adding elements to a `pushAll` collection removes stale elements with `$unset`
+ `$pull null` and then inserts new elements with `$push $each`. Two queries, not atomic
with each other.

**With update pipelines:**
```js
{ $set: { "items": { $concatArrays: [
    { $filter: { input: "$items", cond: { $not: { $in: ["$$this._id", removedIds] } } } },
    newItems
]}}}
```
A single pipeline stage atomically removes specific elements (by identity, not index) and
appends new ones. The current two-query `$unset`/`$pull`/`$push` dance is gone.

---

## 6. Summary

| Problem | Root cause | Fix |
|---|---|---|
| N+1 writes per flush | ORM-inherited "flush collections after entity" model | Update pipeline: one write per root document |
| Path conflict (can't push + update same array) | Operator-based updates share a path namespace | Pipeline stages: each sees the previous stage's output |
| Index-based embedded doc updates (fragile) | ORM diffed by column, ODM diffed by field index | Array filters: target by identity, not position |
| `atomicSet` / `atomicSetArray` as escape hatches | The only way to avoid path conflicts with full replacement | Obsoleted by pipeline-based strategy |
| Embedded docs as tracked entities | Direct ORM fork inheritance | Model embedded docs as value objects owned by root |

The unifying theme: MongoDB's document model means the root document should be the unit
of atomicity. All of the current workarounds — atomic strategies, multi-query collection
updates, index-based updates, separate scheduling of embedded docs — exist because the ODM
still thinks in rows and foreign keys. Update pipelines and array filters give us the tools
to close that gap.
