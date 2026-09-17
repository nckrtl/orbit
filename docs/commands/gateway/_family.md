---
title: "gateway"
description: "Register Gateway profiles on the operator machine, select the active one, pin the Gateway root certificate, and check Gateway status."
commands:
  - gateway:add
  - gateway:use
  - gateway:status
  - gateway:trust
  - gateway:remove
---

Commands that manage remote resources send their requests to the active Gateway. The `gateway` family manages the local profiles that name a Gateway, pins its root certificate authority (CA), installs that certificate in the operating-system trust store, and shows whether the Gateway answers.

Profiles live in `$HOME/.orbit/config.json`; set `ORBIT_HOME` to move that directory. Pinned certificates live under `$ORBIT_HOME/gateways/<profile>-<hash>/ca/`. The [Gateway trust reference](/reference/gateway-trust) owns the trust sequence, the profile guard, and recovery when a profile changes during a trust command.

## Commands

| Command | Result |
| --- | --- |
| [`gateway:add`](#orbit-gatewayadd) | Add a profile, trust the Gateway root CA, and optionally make the profile active. |
| [`gateway:use`](#orbit-gatewayuse) | Select the active profile. |
| [`gateway:status`](#orbit-gatewaystatus) | Show the status and version of the active Gateway. |
| [`gateway:trust`](#orbit-gatewaytrust) | Trust or re-verify the active Gateway root CA. |
| [`gateway:remove`](#orbit-gatewayremove) | Remove a profile and its pinned certificate. |

Every command accepts `--json`. `gateway:use` and `gateway:remove` work locally. Registration, status, and trust contact the selected Gateway; registration and trust can also change the operating-system trust store.

{/* commands */}

## Related

- [`node`](/cli/node) registers the machines that the active Gateway manages.
- [`activity`](/cli/activity) lists the requests that the active Gateway authorized.
