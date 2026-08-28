# Incus topology registry

Incus proof is optional. A Linear issue can select it only from the closed list
of registered profiles in this document.

The monorepo foundation does not register a profile. The Incus development
harness is the next delivery layer. Until that harness lands, an Incus-backed
issue stays in Preparation.

A profile becomes registered only when the repository provides and verifies
all of these exact-ID operations:

- create or safely resume a topology for one Linear issue;
- synchronize the selected worktree roles;
- execute from a VM-local runtime checkout;
- record proof against the candidate commit;
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
Future commands: `bin/incus-profile acquire gateway_app-dev_app-prod --issue <ISSUE>`,
`bin/incus-profile create gateway_app-dev_app-prod --issue <ISSUE>`,
`bin/incus-profile sync gateway_app-dev_app-prod --worktree <WORKTREE> --role <ROLE>`,
`bin/incus-profile prove gateway_app-dev_app-prod --sha <CANDIDATE_SHA> --tree <TREE>`,
and `bin/incus-profile release gateway_app-dev_app-prod`. These commands do
not register a live profile.
