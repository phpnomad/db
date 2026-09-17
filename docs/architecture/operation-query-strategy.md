# Operation-local query boundary

Status: architecture stub. This is not a transaction implementation.

OperationQueryStrategy decorates an existing QueryStrategy. It permits access
only to declared table names, requires inspectable query sources, and rejects
every operation after close. It preserves results and exceptions from its
delegate without caching or retrying. Backend coordinators create it around
their operation-bound strategy and close it in every exit path. Its close
method does not commit, roll back, or close a database connection.

Constructor input is a nonempty list of Table objects with nonempty names.
The decorator snapshots the allowed names, so later edits to a supplied object
cannot add a participant. Duplicate names are harmless. Aliases do not create
new physical participants. Name comparison is exact, leaving platform-specific
normalization to the adapter. This class knows no identifier quoting rules.

Every public query operation checks lifetime first. Closed handles throw
InactiveDatabaseOperationException before inspecting arguments or delegating.
An undeclared table throws InvalidArgumentException before delegation. Queries
must implement HasQueryTables and report at least one source. Uninspectable
builders throw UnsupportedCoordinationException. Empty or malformed source
lists throw InvalidArgumentException. Every reported root and join must be a
participant. Metadata is checked on each call, including a reused builder.

These are trusted framework objects, not a sandbox for hostile code. A custom
builder that lies about its SQL violates HasQueryTables. The adapter must not
accept an arbitrary public SQL string through this boundary. The backend still
owns transaction support, identifier safety, resource affinity, fresh reads,
coordination, and failure classification. A table allowlist is not tenant
authorization. Real handler scope decorators remain necessary.

Acceptance tests exercise the public decorator contract with a recording query
delegate. They prove all five methods, exact results and causes, root and join
boundaries, builder reuse, aliases, descriptor changes, and permanent closure.
They are unit contracts because the decorator performs no I/O. Later adapter
and handler integration tests must exercise this class through real bindings
and real database operations. These contracts do not prove atomicity.

One implementation packet owns OperationQueryStrategy. The implementer may
remove the acceptance suite's incomplete marker, but may not edit assertions,
public signatures, or this contract. A separate proof clone must first show
that a conforming implementation passes and valid-source mutations fail at
the intended assertions. The scratch implementation is never the deliverable.
