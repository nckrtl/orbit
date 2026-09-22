# Local resolver overrides

An operator on macOS uses `dns:resolve` to install or reset a Domain Name System (DNS) mapping that lives only on the caller machine. The mapping covers one development top-level domain (TLD) or one exact private Route name. The command does not change a Route's application domain, scope, target, or Gateway DNS.

The CLI lives in `apps/cli`. [ADR 0009](/decisions/0009-clustered-app-instance-routing) records that local overrides stay separate from Gateway publication.

## Platform

The CLI writes these overrides only on macOS, and only when Homebrew dnsmasq is installed.

| Condition | Result |
| --- | --- |
| The caller is not on macOS | The CLI exits with `dns.unsupported_platform` and changes no files. |
| Homebrew dnsmasq is missing | The CLI exits with `dns.dnsmasq_missing` and changes no files. |

## Names and targets

`dns:resolve` accepts one name and either an IPv4 or IPv6 address, or `--reset` without a target. The name is the private Route DNS name, not an application endpoint field.

| Input | Result |
| --- | --- |
| One lowercase DNS label | The CLI writes a wildcard TLD override for every name under that TLD. |
| A lowercase multi-label DNS name | The CLI writes an exact-name override for that private Route name. |
| A malformed name or address | The CLI refuses the command before it changes local resolver files. |
| A target together with `--reset` | The CLI exits with `dns.target_invalid` and changes no files. |

## Router and workload paths

An exact-name override to the Cluster Router address keeps the Router path for that Route. An exact-name override to the workload Node address reaches the same Route directly. A wildcard TLD override sends every name under that TLD to the supplied address.

## Precedence

When both overrides exist, the exact-name record answers that Route name and the wildcard TLD record answers the remaining names under the TLD. The CLI restores Gateway or wildcard resolution for that name when the operator resets the exact-name record, and it leaves the wildcard record unchanged. The CLI leaves every exact-name override in place when the operator resets the wildcard TLD record.

An exact-name record does not answer descendants of that name. Repeating an exact-name install replaces an older wildcard-style mapping for that hostname, even when the target address has not changed.

## Commands

The CLI writes a Homebrew dnsmasq mapping and a macOS `/etc/resolver` file for the supplied name.

```bash
orbit dns:resolve beast 192.168.6.20
orbit dns:resolve shop.app.beast 192.168.1.40
orbit dns:resolve shop.app.beast --reset
orbit dns:resolve beast --reset
```

Repeating an identical install or reset is idempotent. A write or dnsmasq refresh failure returns a bounded error and does not overwrite unrelated local resolver configuration.

Reset stops if it cannot remove the selected dnsmasq mapping. It does not remove the system resolver in that case. If the mapping is removed but removing the system resolver fails, the completed removal stays in place; retry the same reset to finish.

## Output

Human output names the exact Route name or the dotted TLD. `--json` returns one success object.

| Kind | Identity field | Remaining fields |
| --- | --- | --- |
| Wildcard TLD | `tld` | `target`, `status`, `changed`, `restart_browser` |
| Exact name | `hostname` | `target`, `status`, `changed`, `restart_browser` |

`restart_browser` is true when the command changed the local mapping. After a change, the operator restarts open browsers so they do not reuse an existing connection.

## Failures

Each failure reports one bounded code without operating-system command output.

| Failure code | Result |
| --- | --- |
| `dns.tld_invalid` | The single-label TLD is malformed. |
| `dns.hostname_invalid` | The exact Route name is malformed. |
| `dns.target_invalid` | The target is not an IP address, or a target was supplied with `--reset`. |
| `dns.unsupported_platform` | The caller is not on macOS. |
| `dns.dnsmasq_missing` | Homebrew dnsmasq is not installed. |
| `dns.write_failed` | The CLI could not update local resolver files. |
| `dns.refresh_failed` | Local files changed, and dnsmasq could not be refreshed. |

[Private DNS](/reference/private-dns) owns managed Linux peer resolvers. [Routes](/reference/routes) owns Route records and traffic setup.
