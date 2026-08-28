# Optional Incus diagnostic registry

Incus is optional diagnostic tooling. It never gates issue readiness, review,
merge, or proof. No registered profiles is acceptable.

The monorepo foundation does not register a profile. This document may list
diagnostic profiles, but live proof uses active nodes selected with
`orbit node:list --json`.

A profile becomes registered only when the repository provides and verifies
all of these exact-ID operations:

- create or safely resume a topology for one Linear issue;
- synchronize the selected worktree roles;
- execute from a VM-local runtime checkout;
- record diagnostic evidence against the candidate commit;
- release its instances, networks, storage, source paths, and manifest; and
- verify that release completed.

Each registry entry must name the profile ID, ordered roles, checkout roles,
prepared image, create command, synchronize command, release command, manifest
location, evidence location, and maximum lifetime. Cleanup must be idempotent.
A TTL reaper is only a fallback for abandoned resources.

## Registered profiles

None.
