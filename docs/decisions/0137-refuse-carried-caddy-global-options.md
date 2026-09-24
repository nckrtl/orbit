---
title: "ADR 0137: Refuse carried Caddy global options"
sidebarTitle: "0137 Refuse carried Caddy global options"
description: "Proposed. Orbit writes the only Caddy global options block on a Node. A publisher refuses a candidate when a fragment it carries forward opens its own global block, and names the fragment, the options, and the file to edit."
---

# ADR 0137: Refuse carried Caddy global options

Orbit writes the only Caddy global options block on a Node. When a fragment that a publisher carries forward opens its own global block, the publisher refuses the candidate before `caddy validate`. The error names the fragment, its options, and the file the operator must edit. Orbit does not merge, strip, or rewrite operator global options.

## Status

Proposed.

## Context

The Orbit Caddy publishers for `app-dev`, `app-prod`, `websocket`, `analytics`, ProxyCli, Metrics, and Herdr observers write a candidate `Caddyfile` in the versioned layout under `/etc/caddy/orbit-versions`. Since commit `7fe26990` that file starts with Orbit's global options block (the ProxyCli publisher omitted it until this decision), `auto_https disable_certs`, followed by `import <version>/fragments/*.caddy`. A publisher keeps a Node's pre-existing `Caddyfile` as `fragments/00-unmanaged.caddy` unless it is the unmodified package default, and it copies every fragment it does not own into the next version.

Caddy accepts one global options block, and only as the first block. An adopted `Caddyfile` or a carried fragment that starts with its own global block therefore makes every candidate invalid. `caddy validate` then reports "server block without any key is global configuration, and if used, it must be first", and every changed publication on that Node fails with `<role>.caddy_config_failed`. The message names neither the fragment nor the fix. The Incus harness hit this on `app-prod` when its internal TLS step carried a `local_certs` block; [PR #634](https://github.com/nckrtl/orbit/pull/634) removed that fragment from the harness.

The global options decide certificate automation for every site on the Node. Orbit's certificate model depends on them.

## Decision

- Orbit owns the Caddy global options on a Node. The block that `CaddyGlobalOptions` renders is the only global block in a published version.
- Every one of those publishers and fragment removals, ProxyCli included, writes that block. Before it validates a candidate, each checks every carried fragment. A fragment whose first block has no key is a global options block. The publisher then exits without publishing. It changes neither the live `Caddyfile` nor Caddy.
- The error names the fragment, the option names inside the block, and the file to edit: the live version's fragment when Orbit already carries it, or the adopted `Caddyfile` on first adoption. The operator removes the block and publishes again. The publisher's existing error code, such as `app-dev.caddy_config_failed`, stays the failure code. The message reaches the activity record only where the failing path already keeps command output.
- Site blocks, snippets, and addresses that start with an environment placeholder such as `{$SITE}` are not global blocks and keep working.

## Rejected alternatives

- Merge the adopted directives into Orbit's global block: rejected because operator options can change certificate automation for every Orbit site. `auto_https off`, `local_certs`, or an ACME issuer would silently override or contradict `auto_https disable_certs`. A repeated option also has no safe merge rule.
- Strip the adopted block and keep the rest: rejected because it silently drops configuration the operator wrote, and the sites that relied on it change behavior without warning.
- Leave the failure to `caddy validate`: rejected because the message does not name the fragment, the options, or the fix.
- Refuse only at first adoption: rejected because fragments that Orbit already carries, including `00-unmanaged.caddy` from before `7fe26990`, fail in the same way.

## Consequences

- An operator sees which file blocks publication and what to remove. The Node keeps serving its current configuration until then.
- Role convergence and several publishers keep no command output, so after `node:role:add --converge` the operator sees only `app-dev.caddy_config_failed` as the underlying error, not the message. The reference page tells the operator where to look.
- Operator global options are not supported on Orbit Nodes. A setting that must apply Node-wide needs an Orbit change to `CaddyGlobalOptions`.
- The check is a small shell parser in the publisher scripts. It ignores comments, carriage returns, and a byte order mark, recognizes a global block by its first token, and does not evaluate imports inside a carried fragment. `caddy validate` remains the final check.

## Affects

- Components: apps/gateway
- ADRs: none
- Detail: docs/reference/caddy-configuration.md
- Verify: `apps/gateway` Pest tests in `tests/Feature/Infrastructure/Caddy/CaddyGlobalOptionsTest.php` and the App development publisher tests for an adopted and a carried global block
