---
title: "gateway:add"
description: "Add a profile, trust the Gateway root CA, and optionally make the profile active."
---

Add a Gateway profile. The CLI fetches the Gateway root CA, verifies its fingerprint, repeats the request with the certificate pinned, installs it in the operating-system trust store, and then saves the profile.

```bash
orbit gateway:add <gateway> [--name=NAME] [--ca=PATH] [--use]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `gateway` | yes | Gateway IPv4 or IPv6 address, or an HTTPS origin. The CLI turns a bare address into `https://<address>` and refuses any other scheme. |

| Option | Default | Meaning |
| --- | --- | --- |
| `--name=NAME` | `default` | Local profile name. Adding a profile with an existing name replaces it. |
| `--ca=PATH` | none | Absolute path to a Gateway root CA certificate that the fetched certificate must match. The CLI refuses a different certificate with `gateway.ca_changed`. |
| `--use` | off | Make the profile active after registration. |

```bash
orbit gateway:add 10.70.0.1 --use
orbit gateway:add https://gateway.example.com --name=production --ca=/etc/orbit/production-root.pem
```

The operating-system trust step can ask for local administrator privileges. The JSON result includes the profile, whether it is active, the `trust_status` (`trusted` or `already_trusted`), the certificate `sha256` fingerprint, and the Gateway `request_id`.

| Error code | Meaning |
| --- | --- |
| `gateway.profile_invalid` | The name, URL, or CA path is not accepted; the URL must be a safe HTTPS origin and the CA path must be absolute. |
| `gateway.ca_unavailable`, `gateway.ca_fetch_failed` | The CLI could not reach or safely fetch the root CA endpoint. |
| `gateway.ca_invalid` | The Gateway returned invalid certificate material or a fingerprint that does not match. |
| `gateway.ca_changed` | The fetched certificate differs from the `--ca` pin. |
| `gateway.ca_verification_failed` | The pinned HTTPS verification did not return the same certificate. |
| `gateway.ca_install_failed` | The operating-system trust store could not be inspected or updated. |
| `gateway.ca_profile_update_failed` | The certificate was trusted, but the profile could not be saved. |
