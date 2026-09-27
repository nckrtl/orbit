---
title: "WireGuard endpoints"
description: "Accepted endpoint forms, generated defaults, and recovery for an invalid stored WireGuard endpoint."
covers:
  - apps/gateway/app/Domain/WireGuard/WireGuardEndpoint.php
  - apps/gateway/app/Infrastructure/WireGuard/VpnConfigurationRepository.php
  - apps/gateway/app/Console/Commands/BootstrapGatewayCommand.php
  - apps/gateway/app/Actions/Gateway/GatewayBootstrapIdentityValidator.php
---

# WireGuard endpoints

This page tells an operator which WireGuard endpoint forms the Gateway accepts during bootstrap and Node provisioning, how it builds an omitted endpoint, and how to recover from an invalid stored value.

## Accepted endpoint forms

The Gateway checks the endpoint before bootstrap changes anything and before it renders a peer configuration.

| Host form | Example | Result |
| --- | --- | --- |
| IPv4 address | `192.0.2.10:51820` | Accepted with the same bytes. |
| Hostname | `vpn.example.com:51820` | Accepted with the same bytes. |
| IPv6 address | `[2001:db8::10]:51820` | Accepted. The square brackets are required. |

The port is a decimal integer from 1 through 65535. The Gateway rejects a bare IPv6 address, a missing or invalid port, whitespace, and control characters. An invalid explicit bootstrap endpoint stops before the Gateway writes settings, records a Node, creates files, or changes the host.

## Generated defaults

When `orbit:bootstrap` gets no `--wireguard-endpoint`, the Gateway combines the public host with `--wireguard-port`, which defaults to 51820. It adds square brackets only when the host is an IPv6 address.

A peer configuration takes the first endpoint that exists:

1. The Node's endpoint override.
2. The stored Gateway endpoint.
3. The public SSH host of the `vpn` Node with the configured WireGuard port, with the same bracket rule.

The selected value must pass the same check before the Gateway renders the peer configuration.

## Recover an invalid stored endpoint

The Gateway does not guess where the host ends and the port starts in a stored bare IPv6 endpoint. Correct the value to the bracketed form, such as `[2001:db8::10]:51820`, and retry bootstrap or Node provisioning. Running `orbit:bootstrap` again with a valid `--wireguard-endpoint` replaces the Gateway setting.
