# ADR 0016: Reconcile App identity and source-default updates

## Status

Accepted on 2026-09-02.

If accepted, this decision extends the App, AppInstance, and Route ownership
model in [ADR 0009](0009-clustered-app-instance-routing.md) and the app-prod
placement model in
[ADR 0011](0011-clustered-production-ingress-and-app-prod-placement.md).
Their source, placement, routing, and ownership boundaries otherwise remain in
force.

## Context

An App owns a stable database identity and operator-facing configuration. Its
slug, repository URL, main branch, and relative web root can legitimately
change after AppInstances exist. Treating those fields as permanently
immutable would force operators to replace an App for an ordinary rename or
repository move.

Those fields are not isolated labels. The slug contributes to generated
development Route hostnames and to the derivation of new checkout paths. The
repository URL identifies the origin of every Orbit-owned app-dev clone. The
main branch selects the fallback source for later AppInstances and contributes
to default Route generation. The App root is inherited by every AppInstance
without its own root override and later contributes to runtime publication.

A direct database update would therefore create contradictory state. Existing
clones could retain the old origin while removal validates the new one,
generated hostnames could disagree with the App slug, or running workloads
could continue serving an old document root. A partial multi-Node update could
leave different AppInstances observing different App intent.

Application environment files add a narrower compatibility concern. An
app-dev checkout can contain an operator-owned `.env` value equal to its old
generated HTTPS URL. A slug change should attempt to update that exact value,
but Orbit must not evaluate, disclose, broadly rewrite, or assume ownership of
the rest of the environment file. Environment-file compatibility must not turn
a safe Route rename into arbitrary source mutation.

## Decision

### Keep creation separate from explicit updates

`app:new` creates an App and remains an idempotent creation operation. Repeating
it with the same complete identity and source defaults returns the existing
App. Conflicting input does not update the App.

Orbit exposes a separate typed App update operation through the Gateway, PHP
SDK, and CLI. It can explicitly change the App slug, repository URL, main
branch, and relative root. Omitted fields remain unchanged. Every supplied
field passes the same normalization and validation used at creation before
Orbit performs dependent work.

The App's database ID remains its stable identity. A slug update does not
create a replacement App and the old slug does not remain as an alias.

### Reconcile slug changes without moving placement

Before changing a slug, Orbit inventories every generated development Route
owned by the App and computes its new hostname from the new slug, its existing
AppInstance name, the current App main branch, and the Route's Cluster TLD.
Orbit validates the complete hostname set and refuses the update before
publication if any new hostname is invalid, ambiguous, or already owned by a
different Route.

A Route records whether its hostname is `generated` or `explicit`. A slug
change updates every generated development Route hostname while preserving the
Route ID, App, Cluster, target, and publication intent. An explicit hostname,
including an app-prod hostname, never changes implicitly.

Orbit converges the new DNS, certificate, Router, workload, and health
projections before publishing the new slug and generated hostnames. It then
removes the old projections. At successful completion, old generated
hostnames no longer resolve or serve a redirect. Orbit does not retain them as
aliases.

Existing AppInstance checkout paths remain the immutable paths recorded when
those AppInstances were created. Existing app-prod system users, homes, and
placement bases also remain unchanged. AppInstances created after the rename
derive new placement from the new slug where their placement lifecycle uses
the slug. Public responses continue to expose each existing AppInstance's
actual recorded placement rather than reconstructing it from the current
slug.

### Reconcile repository changes across Orbit-owned clones

A repository URL update applies to the App and every existing Orbit-owned
app-dev clone. It does not apply Git ownership to app-prod operator deployments.

Before changing any clone, Orbit verifies every affected checkout through its
recorded canonical path and repository identity. It refuses the whole update
when a checkout is missing, unsafe, dirty, contains unpublished commits, or
cannot prove that its current branch head and recorded starting commit are
available from the proposed repository. Orbit never pushes source to make a
repository update possible.

After complete preflight, Orbit changes each verified clone's `origin` to the
normalized new repository and verifies the result. Existing local branch
names, checked-out commits, selected-branch evidence, and starting commits do
not change. Failure leaves the old App repository URL authoritative and uses
the durable update lifecycle to resume or restore any origin changed before
the failure.

### Apply main-branch changes prospectively

Changing `App.main_branch` changes the fallback used when a later app-dev
AppInstance has no matching remote instance branch. It does not retarget an
existing AppInstance, rewrite its selected branch or starting commit, or
rename an existing Route. Later Route creation uses the current main branch
when deciding whether an AppInstance receives the unprefixed generated
hostname.

Orbit validates that the proposed main branch exists in the App repository
before publishing it. Failure leaves the existing main branch unchanged.

### Reconcile inherited roots

Changing `App.root` changes the effective root of every AppInstance whose own
root override is null. An explicit AppInstance root override remains
unchanged. Orbit applies the existing role-specific validation, containment,
ownership, and runtime rules to every affected placement.

Orbit converges every affected runtime and Route projection against the new
effective root before publishing the App root. If any affected placement
cannot accept the root, the old root and its active runtime projections remain
authoritative. A root update never moves a checkout, production home, release,
or other source content.

### Use one durable reconciliation lifecycle

One App update can supply more than one field. Orbit treats the supplied
changes as one operation with one immutable affected-resource inventory and a
closed durable lifecycle. It validates the complete proposed state and
preflights every affected AppInstance and Route before externally observable
publication.

The normal lifecycle is `reserved`, `preflighted`, `prepared`, `publishing`,
`cleaning_up`, and `complete`. `reserved` records the requested values and the
old App version. `preflighted` records the immutable affected-resource
inventory and the evidence that every affected resource can accept the
change. `prepared` records that every reversible candidate mutation and new
projection is ready while the old App values and projections remain
authoritative.

Persisting `publishing` is the point of no return. From that state Orbit only
recovers forward: it promotes the new App values and generated Route hostnames,
activates the prepared runtime and routing projections, and verifies the new
public behavior. `cleaning_up` records that the new state is authoritative
while Orbit removes old DNS, certificate, Router, workload, and temporary
preparation artifacts. `complete` is published only after required cleanup and
every best-effort environment-file attempt has a bounded recorded result.

A confirmed failure before `publishing` moves the update to `rolling_back`.
Orbit removes staged projections and restores every reversible external
mutation, including any clone origin already changed during preparation. The
old App and Route values remain authoritative throughout rollback. Successful
rollback ends in `rolled_back`. Failed rollback remains `rolling_back`; it
blocks conflicting App and affected-AppInstance mutations, and an identical
retry continues restoration before another update can start.

An interruption is not a confirmed failure. An identical retry resumes the
recorded normal or rollback state from its last verified evidence. Conflicting
updates fail while one update is incomplete. Orbit reports bounded
per-resource progress and failure without returning repository credentials or
environment contents.

Once `publishing` is durable, Orbit never automatically restores an old slug,
generated hostname, repository URL, main branch, or root. A publication or
cleanup failure remains in `publishing` or `cleaning_up` and retries forward
until the new authoritative state is healthy and obsolete projections are
removed. This prevents an externally observed new URL from later reverting to
an old identity.

### Attempt exact app-dev environment URL replacement

After a slug update successfully publishes a new generated hostname, Orbit
attempts a best-effort `.env` rewrite for each app-dev AppInstance whose
generated HTTPS URL changed. The old and new values are exactly
`https://<old-hostname>` and `https://<new-hostname>`, without an implied
trailing slash.

Orbit inspects only `<recorded-checkout-path>/.env`. The checkout must first
pass its normal canonical-path, containment, ownership, and repository-identity
checks. The `.env` entry must be a regular, non-symlink file inside that
checkout and must not be tracked source. Orbit parses assignments without
executing or sourcing the file. It replaces every quoted or unquoted
assignment whose entire value exactly equals the old URL and preserves all
unrelated bytes and the existing quote style.

Orbit never performs substring replacement. A value such as
`https://old.example/path`, a different scheme, or text containing the old URL
remains unchanged. Orbit writes a changed file atomically in the same
directory, preserves its owner, group, and mode, and never records or logs its
contents.

A missing `.env`, no exact match, a tracked file, an unsafe file, or a failed
write does not roll back the already successful hostname update. Orbit records
one bounded result such as `updated`, `missing`, `no_match`, `refused`, or
`failed` for that AppInstance so the operator can reconcile it manually. Orbit
does not modify `.env.example`, another checkout file, an app-prod deployment,
or an AppInstance whose URL did not change.

Removing an old generated hostname means removing Orbit's authoritative DNS
and serving projections. A resolver or client can retain a previously cached
DNS answer until its published TTL expires; Orbit does not claim to purge
third-party caches.

## Consequences

- App configuration remains intentionally mutable, but updates become explicit
  reconciliations rather than side effects of idempotent creation.
- Slugs are public naming inputs but are not filesystem or operating-system
  placement identities after creation. Existing paths, users, and homes can
  therefore retain an older slug visibly and safely.
- Routes need durable generated-versus-explicit hostname provenance before App
  slug updates can ship.
- Repository updates fail closed across every managed development clone and
  can be expensive on a multi-Node App.
- Main-branch changes are prospective; they do not rewrite historical
  AppInstance evidence or existing Route identity.
- Root updates require the relevant app-dev and app-prod runtime lifecycles to
  support coordinated reconvergence before the update feature is complete.
- The `.env` rewrite improves common application behavior without giving Orbit
  general environment-file ownership. Its best-effort result remains visible
  because a successful URL cutover can still leave application configuration
  requiring manual attention.
- The durable publication marker makes rollback a pre-publication operation.
  Failures after publication recover forward so an externally observed App
  identity cannot revert unexpectedly.
- The implementation requires automated state-machine and failure-injection
  coverage plus Incus proof for multi-Node repository, runtime, routing,
  filesystem, and environment-file behavior.
