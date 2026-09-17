# Coordinated database operations

Status: architecture contract. No integration provides this capability yet.
The acceptance suite and integration implementations follow in dependent PRs.
This change is based on database 2.2.2, not the 3.x clock migration.

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
vendor error codes. An integration chooses its mechanism. Coarser coordination
is valid: separate requested scopes need not execute concurrently.

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
its scoped query strategy. The caller owns any retry and must use durable effect
identity where an uncertain result might already have committed.

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
ordering and durable delivery are outside this guarantee. Post-commit failures
must report the committed outcome and must not cause the score to be reapplied.

## Proof required before enabling an integration

The adapter contract suite must run against real independent database clients.
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
semantic contract without becoming a supported production backend. No test may
require distinct scopes to run concurrently, since broad coordination is valid.

The handler bridge needs its own integration proof through real public datastore
calls. Scope, defaults, adaptation, cache identity variants, and record payloads
must remain compatible. No cache or event effect may escape a rollback. Siren
then adds domain tests for claims, all recipients, merges, current-distribution
creation, and both legacy and enriched event paths.

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

Cross-repository draft dependencies may use explicit branch aliases and locked
commit hashes for review. Those pins are temporary and are not release-ready.
No package release, deployment, or merge is authorized by this implementation.

## Baseline and tooling

The release/2.2 baseline is commit 633eb43. Its unit suite passes with 15 tests
and 38 assertions. Its existing level-9 static analysis reports 231 errors in
13 files. New changes must not add errors, and the inherited failure remains an
open check rather than a green gate.

The installed CLI has no recipes namespace. Its available model recipe creates
a persisted entity, which does not match these interfaces or exceptions. These
declarations have no suitable installed scaffold. Index generation and command
discovery ran before source inspection. No production method is implemented in
this contract slice.
