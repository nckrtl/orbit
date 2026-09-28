---
title: "Gateway trust"
description: "How the CLI stores Gateway profiles, pins the Gateway root certificate, installs it in the operating-system trust store, and recovers when a profile changes during a trust command."
covers:
  - apps/cli/app/Services/Trust/**
  - apps/cli/app/Repositories/GatewayConfigRepository.php
  - apps/cli/app/Data/GatewayProfile.php
  - apps/cli/app/Support/OrbitHome.php
  - packages/php-sdk/src/GatewayRootCaClient.php
  - apps/gateway/app/Http/Controllers/Api/RootCaCertificatesController.php
---

# Gateway trust

The CLI reaches the Gateway over HTTPS. The Gateway certificate chains to the Orbit root certificate authority (CA). The CLI pins that root certificate on each Gateway profile and installs it in the operating-system trust store. The [`gateway`](/cli/gateway) commands run these steps.

## Profiles

The CLI keeps its profiles in `$ORBIT_HOME/config.json`. `ORBIT_HOME` defaults to `$HOME/.orbit`. The file holds the active profile name and one entry per profile with its `url` and `ca_path`. The CLI writes the file with mode `0600` under a lock. It refuses a `config.json` that is a symlink, belongs to another user, or has group or other permission bits, with `gateway.config_not_private`.

A profile name has 1 to 63 characters: lowercase letters, digits, `.`, `_`, and `-`, starting with a letter or digit. A profile URL is an HTTPS origin without a user, password, path, query, or fragment.

The first profile that `gateway:add` saves becomes the active profile. Later profiles become active only with `--use` or `gateway:use`.

The pinned certificate lives in `$ORBIT_HOME/gateways/<slug>-<hash>/ca/`, where `<slug>` comes from the profile name and `<hash>` is the first 12 characters of the name's SHA-256. The directories have mode `0700`, and the certificate file has mode `0600`.

## Trust sequence

`gateway:add` and `gateway:trust` run the same steps.

1. **Bootstrap fetch.** The CLI calls `GET /api/v1/ca/root` without certificate verification and without following redirects. The Gateway answers this endpoint for any caller. The CLI checks that the certificate matches the SHA-256 fingerprint in the response.
2. **Pin check.** `gateway:add --ca` compares the certificate with the given file. For an existing profile name, `gateway:add` checks the profile pin as `gateway:trust` does. A mismatched pin fails with `gateway.ca_changed`. Pass `--accept-ca-change` to accept the changed certificate.
3. **Pinned verification.** The CLI stores the certificate privately. It repeats the request with that certificate as the only trust root. A different certificate fails with `gateway.ca_verification_failed`.
4. **Operating-system trust.** The CLI checks the trust store. It installs a missing certificate and checks the trust store again.
5. **Profile save.** `gateway:add` saves the profile. `gateway:trust` saves the pin under the [profile guard](#profile-guard).

On macOS, the install runs `sudo security add-trusted-cert` into the System keychain. On Linux, it runs `sudo install` and `sudo update-ca-certificates`.

JSON output of both commands returns the certificate fingerprint, the certificate path, the trust status (`trusted` or `already_trusted`), and the Gateway request ID. No output holds certificate material.

## Profile guard

`gateway:trust` saves the pin only when the profile still has the name and URL that the command started with, and its pin is still the old path or the new one. The CLI checks this under the configuration lock. A switch of the active profile does not block the save.

When another command changed the profile first, `gateway:trust` keeps that change and fails with `gateway.ca_profile_update_failed`. The certificate is then already in the operating-system trust store, because the two stores are separate. Check which profile is active, and run `gateway:trust` again for the profile you want.

## Removal

`gateway:remove` deletes the profile entry and its pinned certificate file. It does not change the operating-system trust store.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Fetch without verification, then verify with the pin

A new operator machine has no Orbit root certificate, so the first request cannot verify the Gateway. The CLI checks the fingerprint in the response and then repeats the request with the fetched certificate as the only root. A certificate that changes between the two requests fails, and nothing reaches the trust store.

### A changed certificate needs an explicit flag

A new root certificate on a known Gateway can mean a rebuilt Gateway or an attacker on the path. Both `gateway:trust` and `gateway:add` with an existing profile name refuse it with `gateway.ca_changed` until the operator checks the fingerprint and passes `--accept-ca-change`.

### Removal leaves the trust store alone

Several profiles can share one Orbit root certificate, and other programs can rely on it. So `gateway:remove` deletes only what the CLI owns: the profile and its private copy of the certificate.
