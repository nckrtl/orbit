# Node provisioning

This page tells an operator which Linux user the Gateway connects as when `orbit node:provision <name> [host]` bootstraps a Node, and how the Gateway records the machine architecture of that Node. It explains how each choice differs between a new Node and an existing Node, and which identity and architecture inputs the command accepts. The same request serves a first provisioning and a later change to a Node's TLD, roles, or settings.

## Bootstrap identity

The Gateway opens one SSH session as the bootstrap user and runs the base bootstrap. The bootstrap installs the base packages and creates the managed user when it is missing. It installs the Gateway SSH key for that user and grants that user passwordless sudo. The Gateway then verifies SSH access as the managed user, and every later Gateway command on the Node runs as that user.

When the request names no bootstrap user, the Gateway selects it from the Node record.

| Node state | Bootstrap user | Bootstrap command |
| --- | --- | --- |
| New Node | `root` | Runs the base bootstrap directly. |
| Existing Node | The Node's recorded managed user | Runs the same base bootstrap through passwordless sudo. |

An explicit bootstrap user replaces this default for a new Node and for an existing Node. Name one when the host allows no root login. Name one when the recorded managed user cannot log in, for example after a first provisioning failed before the bootstrap created that user.

## Identity inputs

The CLI sends a bootstrap user only when the option is present. The Gateway API reads an omitted `user` field as absence and rejects an empty or null value.

| Input | Meaning |
| --- | --- |
| `--user` | Optional bootstrap SSH user for the CLI. The Gateway API and PHP software development kit (SDK) field is `user`. |
| `--orbit-user` | Optional managed user for the CLI. The Gateway API and SDK field is `orbit_user`. A new Node records `orbit`; an existing Node keeps its recorded managed user. |

The Gateway console command `orbit:node-provision` applies the same defaults for the first Node.

## Machine architecture

The Gateway observes the machine architecture right after it verifies SSH access as the managed user. It runs `uname -m` on the Node and reads the reported value, for example `x86_64` or `aarch64`. A new Node records that observed value, so the request needs no architecture input. An existing Node keeps its recorded architecture whatever the request carries.

When the request names an architecture for a new Node, the Gateway compares it with the observed value. The Gateway records an equal value. A different value stops the request with status `409` before the Gateway materializes a Tool Manager or converges a role. Each architecture failure leaves the Node record failed at the `machine-architecture` step.

| Input | Meaning |
| --- | --- |
| `--architecture` | Optional machine architecture; the API and SDK field is `architecture`. A new Node records the observed value, which an explicit value must equal. An existing Node keeps its record. |

The Gateway console command `orbit:node-provision` accepts the same option with the same default.

## Failure codes

Each identity or architecture failure names the boundary that stopped the request.

| Code | Meaning |
| --- | --- |
| `node.invalid_linux_user` | The bootstrap user, the managed user, or the recorded managed user is not a valid Linux user name. The Gateway changes no Node. |
| `node.user_change_unsupported` | The request names another managed user for a Node that owns roles or instances. The Gateway changes no Node. |
| `node.bootstrap_failed` | The bootstrap session or the base bootstrap failed as the bootstrap user. |
| `node.orbit_ssh_failed` | The Gateway could not connect as the managed user after the bootstrap. |
| `node.architecture_unavailable` | The Gateway could not read a machine architecture from the Node as the managed user. |
| `node.architecture_mismatch` | The request names an architecture that differs from the observed one for a Node without a record. The Gateway records no architecture and converges no role. |

The owning implementation and tests live in `apps/gateway`.
