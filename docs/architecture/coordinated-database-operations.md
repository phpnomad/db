# Coordinated database operations

Status: contract defined. Runtime support requires adapter conformance proof.

## Purpose and layers

A database-backed application sometimes needs to apply a complete mutation
without partial writes or lost concurrent updates. Ordinary independent CRUD
calls do not establish either guarantee. A new optional database capability
supplies both while leaving the existing QueryStrategy, QueryBuilder,
AtomicOperationStrategy, and generic datastore contracts unchanged.

The application-facing operation remains a datastore operation. For Siren it
means persisting one already calculated scoring effect, including its durable
retry claim and every recipient change. The database-backed handler invokes
this capability. Siren Core sees domain inputs and results, never tables,
query builders, connections, or coordination commands. Attribution, filters,
and score calculation stay outside persistence.

Datastore is not database. Nothing here requires a REST-backed or file-backed
datastore to implement a database interface. No such backend is being built.
An application-specific strong datastore capability is separate from ordinary
datastore CRUD. A handler may offer it only when it can fulfill the guarantee.

## Optional contracts

CoordinatedQueryStrategy extends QueryStrategy as a separate opt-in interface.
Its coordinate method receives a coordination table and complete record
identity, all participating tables, and one callback. The coordination record
must already exist. A stable parent record therefore coordinates creation of
child aggregates even when no aggregate exists yet.

The table and record concepts already belong to the database package. The
contract exposes no engine names, lock commands, storage-engine inspection, or
vendor error codes. An integration chooses its mechanism. The logical scope
retains the database resource, table, and complete record identity. An
application-wide or tenant-blind guard, counter, or quota cannot replace it.
Inherent backend contention may still serialize unrelated operations. The
generic contract does not promise parallel progress across distinct records.

HasQueryTables is a second optional interface. An upgraded builder reports the
actual root and all join tables of its current query. The operation-local query
strategy uses this metadata to reject undeclared sources before execution.
An uninspectable query builder is unsupported inside the operation. The base
QueryBuilder does not gain a required method, and ordinary queries keep working.

## Guarantees

1. Atomicity: all participant changes in a successful call commit together.
   A callback error or confirmed concurrency abort leaves none of them applied.
2. Coordination: successful participating operations with the same database
   resource and coordination record are equivalent to some serial execution.
   A later operation reads at least the earlier committed state. This covers
   both update and absent-child creation races.
3. Affinity: the callback's query strategy reads and writes through the same
   database operation. It cannot switch to a replica or independently commit.
4. Lifetime: the callback runs at most once. Its query strategy becomes invalid
   before coordinate returns or throws. Every method rejects later use.
5. Boundaries: only declared tables are usable. Every query, insert, update,
   delete, and estimated count validates participation before execution.
6. Honest support: unsupported scope, resources, or ambient operations fail
   before the callback and before any write. No best-effort execution follows.

All writers that require these guarantees must use the same coordination
protocol. A direct third-party write does not become coordinated automatically.
The operation does not combine separate connections, pools, shards, APIs,
files, caches, or event buses. Two datastore objects do not establish affinity.

Input validation must reject empty participant lists, empty identities,
missing identity fields, invalid value types, and a coordination table outside
the participants. Integrations validate their own identifier rules and actual
resource eligibility. Scope descriptors must remain stable during execution.

## Failure and retry

UnsupportedCoordinationException means the requested operation did not start.
RecordNotFoundException means no matching coordination record exists.
CoordinatedOperationConflictException means contention prevented success and
rollback was confirmed. CoordinatedOperationOutcomeUnknownException means
commit or rollback could not be established. These are different outcomes.

Ordinary callback errors propagate after confirmed rollback. A rollback failure
must preserve uncertainty rather than imply the callback error was harmless.
Nested or foreign ambient operations are rejected before changing their state.
The integration does not retry a callback or expose transaction control through
its scoped query strategy. Each integration logs detected failures through
LoggerStrategy with the operation phase, table names, outcome classification,
retry safety, and chained cause. Sensitive identity values are omitted or
redacted. Logging must preserve the established outcome classification.

Each invocation makes one attempt. Asynchronous retry belongs to durable queue
redelivery, never an adapter or call-site loop:

| Outcome | Caller action |
| --- | --- |
| Invalid or unsupported request | Correct configuration or input. Do not retry unchanged. |
| Missing coordination record | Resolve the missing parent. Do not assume a transient conflict. |
| Confirmed conflict and rollback | Eligible for a bounded durable-queue redelivery. Synchronous callers surface failure. |
| Callback failure with confirmed rollback | Propagate the cause. Only its owner can classify it as transient. |
| Commit or rollback unknown | Reconcile through durable effect identity before any retry. |
| Committed data with publication failure | Keep the committed result. Never repeat the database mutation to repair publication. |

Queue callers use the existing durable queue's attempt limits, backoff, and
terminal-failure reporting, with idempotency keys enforced at queue insertion.
Synchronous callers propagate failure and log it, without business-level retries.
An unknown outcome must be visible through LoggerStrategy and its distinct
exception. This library adds no queue, retry scheduler, or dead-letter system.

Siren's effect claim commits in the same operation as all recipient changes.
There is no separately committed in-flight claim to strand after rollback.
An unknown outcome is reconciled against that durable claim. A queue caller
that owns another in-flight domain state must settle it through its existing
terminal-failure hook when attempts end, and prove that transition. This does
not add queue states to the database capability or Siren's effect claim.

## Database-handler bridge

A following database-package slice will create operation-local clones of the
actual participating database handlers and their providers. It must preserve
subclass scope decorators, model adapters, defaults, and validation. It must
not replace a shared provider or mutate a globally bound handler in place.
Each local provider gets independent mutable query and clause builders and the
operation-bound query strategy. Joining a handler on another resource fails.

Shared cache reads are bypassed during coordinated mutation. An operation-local
hydration cache may support the handler's set-then-get model conversion path,
but it must be invalidated when that record changes and cannot replace a fresh
mutation read. Record notifications are buffered. Failure discards local state.

After confirmed commit, the bridge invalidates affected shared cache identities
and then emits compatible record events. It never replays shared cache sets
from an older operation, which could overwrite a newer committed value.
Publication is ordered within one invocation only. Cross-invocation event
ordering and durable delivery are outside this guarantee. The bridge returns a
typed committed result containing the callback value and any publication
failures. It logs those failures through LoggerStrategy and continues the
remaining invalidations and notifications. Callers consume the committed value
without reapplying the mutation. They may explicitly retry failed publication
only when that observer's contract makes repetition safe. The bridge never
throws a generic mutation failure after confirmed commit. The result model and
its proof belong to the later bridge slice, not this interface-only change.

## Proof required before enabling an integration

The adapter contract suite must run against real independent database clients.
The harness uses environment-driven configuration and an explicitly owned
test schema, resets database state and model caches between cases, and skips
clearly when its database is absent. A skip is not adapter-conformance proof.
It must prove overlapping same-scope additions, duplicate occurrence claims,
absent-child creation, complete rollback on a later write failure, and fresh
reads after a preceding operation commits. A synchronization barrier must force
the overlap. Delays and timing assumptions are not proof.

Further cases cover missing coordination records, undeclared query/join/write
tables, unsupported builders and resources, mismatched sessions, ambient and
nested operations, expired query strategies, callback invocation count, commit
uncertainty, and rollback uncertainty. Actual root/join metadata must survive
builder reuse and reflect reset or replacement of clauses.

Legacy base-interface implementations remain constructible and usable without
the new capability. A non-row-lock test implementation will exercise the same
semantic contract without becoming a supported production backend. Generic tests
do not require distinct scopes to run concurrently because inherent backend
contention is allowed. They do not permit a tenant-blind application guard.
The MySQL adapter uses record-specific coordination and must prove that distinct
identities can progress independently with real clients and explicit barriers.

The handler bridge needs its own integration proof through real public datastore
calls resolved from the production Application and its real container bindings.
Event dispatch, caches, middleware where applicable, and persistence stay real.
Only external network boundaries may be mocked. Scope, defaults, adaptation,
cache identity variants, and record payloads
must remain compatible. No cache or event effect may escape a rollback. Siren
then adds domain tests for claims, all recipients, merges, current-distribution
creation, and both legacy and enriched event paths.

Before feature release, checked-in end-to-end tests must drive the real running
Siren application through its actual event entry point, production bindings,
database, and cache. They must assert the final scores, durable claims, cache
visibility, and record notifications for both legacy and enriched facts, with
retry and failure cases. Each supported platform must pass. A skipped adapter
or end-to-end suite is not a passing release gate. Manual UAT proof remains a
separate gate and does not replace these repeatable tests.

## Delivery order

1. Database contracts and optional query-source metadata.
2. MySQL and WordPress integration support with real conformance tests.
3. The reusable operation-local database-handler bridge.
4. Siren whole-effect datastore contracts and handlers.
5. Legacy and enriched score writers, merge, and distribution participation.
6. Complete feature wiring, compatibility, release checks, and UAT proof.

Adapters unable to support coordination remain valid ordinary adapters. The
first supported Siren paths are its PDO and WordPress database paths. SafeMySQL
must remain usable without falsely advertising a capability it does not have.
