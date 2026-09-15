---
title: "Gateway trust"
description: "How the CLI registers, selects, and removes Gateway profiles and pins the Gateway root certificate in the operating-system trust store."
---

# Gateway trust

This page tells an operator how the CLI registers, selects, and removes Gateway profiles, and how it pins a Gateway root certificate. It also covers operating-system trust-store changes and recovery when a local profile changes during a trust command.

## Trust sequence

`gateway:trust` reads the active Gateway profile and completes each trust boundary in this order.

| Step | Observable result |
| --- | --- |
| Bootstrap fetch | The CLI fetches the Gateway root certificate without following redirects and validates its reported SHA-256 fingerprint. |
| Pinned verification | The CLI stores the certificate privately, repeats the request with it as the Transport Layer Security (TLS) trust root, and refuses a certificate that changed between requests. |
| Operating-system trust | The CLI verifies the certificate in the operating-system trust store, installs it with visible local privilege escalation when needed, and verifies the installed result. |
| Profile pin | The CLI saves the private certificate path only when the profile still has the name, URL, and pin that the command started with. |

The command returns the certificate fingerprint, private certificate path, trust status, and Gateway request ID in JSON mode. Human output reports the trust status and request ID. Neither output includes certificate material or underlying operating-system errors.

## Existing profile guard

The final profile save compares current profile content while it holds the local configuration lock. A switch to another active profile does not change that content and does not block the save. A concurrent command that already saved the same target certificate path is also successful.

If another operation replaces the same profile URL or saves a different certificate path first, `gateway:trust` keeps that replacement and exits with `gateway.ca_profile_update_failed`. The error states that the root certificate was trusted but the profile could not be updated, and it includes the valid Gateway request ID.

Operating-system trust and the local profile file are separate stores. The operating-system trust change may therefore be complete when the guarded profile save fails. Inspect the current profile selection and run `gateway:trust` again for the intended profile; the repeated command verifies the current Gateway and completes or confirms its pin.

## Registration and replacement

`gateway:add` owns profile registration and explicit same-name replacement. It completes certificate verification and operating-system trust before it publishes the supplied profile, and `--use` selects that profile after registration. The existing-profile guard used by `gateway:trust` does not change this replacement behavior.

`gateway:use` selects an existing profile as the active Gateway. It does not change certificate pins or the operating-system trust store.

## Profile removal

`gateway:remove` removes a named Gateway profile from the CLI configuration. The CLI deletes that profile entry and, when the profile records a pinned certificate path and that file exists, deletes the pinned certificate file. The command does not change the operating-system trust store.

Removal requires default-No interactive confirmation or explicit `--yes`. Removing the active profile also requires the independent `--force` override. See [gateway:remove](/cli/gateway#orbit-gatewayremove) for refusal codes and automation examples.

The CLI refuses the active profile and leaves the configuration unchanged. With `--force`, the CLI removes the active profile and clears the active selection. An unknown name exits with `gateway.profile_not_found`. An active profile without `--force` exits with `gateway.profile_active`.

Human output names the removed profile. `--json` returns the removed profile name.

The remove options change whether the active profile may be deleted, or the output format.

| Option | Behavior |
| --- | --- |
| `--force` | Removes the active profile and clears the active selection. Without this option the CLI refuses the active profile. |
| `--json` | Emits one structured success or error object. Success includes the removed profile name. |

## Options

The trust options change certificate acceptance or output format.

| Option | Behavior |
| --- | --- |
| `--accept-ca-change` | Accepts a fetched certificate that differs from the profile's current pin only after the operator verifies the new fingerprint. |
| `--json` | Emits one structured success or error object, including the request ID. |

Without `--accept-ca-change`, the CLI exits with `gateway.ca_changed` before pinned verification or operating-system installation when a pinned Gateway presents another certificate.
