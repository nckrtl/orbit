# WireGuard endpoints

This page tells an operator which WireGuard endpoint forms the Gateway accepts during bootstrap and Node provisioning, how it builds an omitted endpoint, and how to recover from an invalid stored value.

## Accepted endpoint forms

The Gateway applies the same endpoint validation before bootstrap effects and before it renders a peer configuration.

| Host form | Example | Result |
| --- | --- | --- |
| IPv4 address | `192.0.2.10:51820` | Accepted with the same bytes. |
| Hostname | `vpn.example.com:51820` | Accepted with the same bytes. |
| IPv6 address | `[2001:db8::10]:51820` | Accepted with required square brackets. |

The port is a decimal integer from 1 through 65535. The Gateway rejects a bare IPv6 address, a missing or invalid port, whitespace, and control characters. An invalid explicit bootstrap endpoint stops before the Gateway writes settings, records a Node, creates files, or changes the host.

## Generated defaults

When `orbit:bootstrap` receives no `--wireguard-endpoint`, the Gateway combines the public host with `--wireguard-port`. It adds square brackets only when the public host is IPv6, so IPv4 and hostname defaults keep their bytes.

Peer configuration selects the Node endpoint override first, the stored Gateway endpoint second, and the Gateway public SSH host with the configured WireGuard port last. The final fallback follows the same IPv6 bracket rule. The selected value must pass the endpoint grammar before the Gateway renders the peer configuration.

## Recover an invalid stored endpoint

The Gateway does not reinterpret a stored bare IPv6 endpoint because its host and port boundary is ambiguous. Correct the value to bracketed form, such as `[2001:db8::10]:51820`, and retry bootstrap or Node provisioning. Repeating `orbit:bootstrap` with a valid `--wireguard-endpoint` replaces the Gateway setting through the normal idempotent bootstrap path.

The owning implementation and tests live in `apps/gateway`.
