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

Registered profiles: None.

The future full profile is `gateway_app-dev_app-prod`. It will use the
`gateway` and `app-dev` checkout roles and the `app-prod` standby target.
Registration requires live proof of exact generation and topology identity,
synchronization, candidate SHA and tree, clean prove, and release.
The implemented, unregistered command surface is:

```text
bin/e2e-topology acquire ISSUE WORKTREE
bin/e2e-topology sync ISSUE WORKTREE
bin/e2e-topology verify ISSUE
bin/e2e-topology exec ISSUE ROLE --argv-file=PATH
bin/e2e-topology prove ISSUE WORKTREE --candidate-sha=SHA
bin/e2e-topology release ISSUE
```

These commands always use the full profile. Their presence does not register
the profile before live acceptance passes.

`bin/e2e-standby refresh --allow-cold` permits only initial construction when
no promoted generation or standby resources exist. It never replaces a
promoted generation. An operating-system, base-image, cold-epoch, or corrupt
standby change requires a separate reviewed disaster-recovery procedure before
the harness mutates Incus resources.
