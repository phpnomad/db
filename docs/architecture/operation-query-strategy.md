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

The coordinator owns LoggerStrategy reporting for validation and callback
failures, including errors from this decorator and builder metadata. The coordinator records
phase, participant tables, outcome, and safe scope context before propagating
or classifying the failure. Coordinator acceptance tests must prove that path.
The decorator preserves the original cause and adds no retry or I/O. Misuse of
an escaped handle after closure reaches the caller's normal request or job
exception boundary, which logs the programming error. It is not a database
conflict and must not trigger a mutation retry.

Acceptance tests exercise the public decorator contract with a recording query
delegate. They prove all five methods, exact results and causes, root and join
boundaries, builder reuse, aliases, descriptor changes, and permanent closure.
They are unit contracts because the decorator performs no I/O. Later adapter
and handler integration tests must exercise this class through real bindings
and real database operations. These contracts do not prove atomicity.

Before coordinated application behavior is complete, an end-to-end test must
drive a representative request or job in the real running application through
its production handler and coordinator bindings. Against a real database, a
declared joined query must return seeded rows. An undeclared join must fail
before query execution and reach the expected logging boundary. Component tests
and mocks of the application do not satisfy this downstream gate. The parent
coordination contract also requires full Siren business-flow proof on each
supported platform.

One implementer owns OperationQueryStrategy. The implementer may
remove the acceptance suite's incomplete marker, but may not edit assertions,
public signatures, or this contract. A separate temporary copy must first show
that a correct implementation passes. Removing each promised behavior must
produce its expected assertion failure while the code still compiles. The
temporary implementation is discarded before production work begins.
