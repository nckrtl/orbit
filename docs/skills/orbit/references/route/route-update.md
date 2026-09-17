---
title: "route:update"
description: "Change the domain or publication intent of an explicit Route."
---

# route:update

Change the domain or the publication intent of an explicit Route. At least one option is required.

```bash
orbit route:update <route> [--domain=DOMAIN] [--publication=INTENT]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `route` | yes | Numeric Route ID. |

| Option | Meaning |
| --- | --- |
| `--domain=DOMAIN` | New explicit domain. |
| `--publication=INTENT` | New publication intent, `private` or `public`. |

```bash
orbit route:update 9 --domain=shop.example.com
```

A domain change reserves a replacement Route while the current Route stays authoritative. The Gateway prepares certificates, Caddy sites, environment values, and private DNS before cutover. It then removes the old Route and its projections. The response returns the replacement Route ID. A publication-only update keeps the Route ID. See [Route changes](https://orbit.nckrtl.com/docs/reference/routes.md#change-an-existing-route) and [public publication](https://orbit.nckrtl.com/docs/reference/routes.md#activate-public-publication) for eligibility and recovery.

> **Note:** During a domain change, inspection shows both Route records, `replacement_step`, `failed_step`, and `error_code`. Repeat the same request to resume. A conflicting domain or publication request changes no recorded intent.

| Error code | Meaning |
| --- | --- |
| `route.update_required` | No option was given. |
| `route.domain_immutable` | The Route is generated; only an explicit domain can change. |
| `route.domain_conflict` | Another Route owns the new domain. |
| `route.reconciliation_required` | The requested change falls outside the supported Route lifecycle. |
| `route.domain_change_conflict` | A different domain change is already in progress. |
| `instance.source_profile_missing` | The target has no recorded source profile; recover it through the same creation request with `--recover-source-profile`. |
