---
title: "Install from source"
description: "Install the CLI and bootstrap a Gateway from the complete monorepo."
---

# Install from source

This guide helps a first-time operator install Orbit's CLI and bootstrap a Gateway from the PHP monorepo. Use a fresh, disposable Gateway and follow [your first app](/reference/first-app) to add a separate development Node. The [alpha guide](/reference/alpha) defines the trial's limits and required checks.

## Requirements

The CLI is a local HTTP client. Managed machines have additional operating-system and network requirements.

| Location | Requirements |
| --- | --- |
| CLI machine | PHP 8.5, Composer 2, Git, and the extensions required by the locked dependencies. Linux and macOS have local certificate-trust support. |
| Gateway | Ubuntu 26.04, systemd, SSH, passwordless sudo for the dedicated `orbit` user, PHP 8.5 CLI and FPM, Composer 2, SQLite, Caddy, WireGuard, dnsmasq, and UFW. |
| Development Node | Fresh Ubuntu 26.04 with SSH access for root or a bootstrap user with passwordless sudo. Orbit installs the managed account, network, and role packages. |
| Network | A Gateway address reachable by each Node, UDP 51820 to the Gateway, outbound package and Git access, and a non-overlapping WireGuard subnet. The default is `10.44.0.0/24`. |
| Administrative access | Keep provider-console access to each machine. Role provisioning closes public SSH after private access works. |

Ubuntu 24.04 is unsupported, including for a managed operator Node without roles. Windows is outside this walkthrough. A macOS CLI installation does not register the Mac as a managed Linux Node or configure its VPN. This walkthrough runs the first CLI on the Gateway so its active WireGuard identity is already available.

## Select source

Use one full monorepo checkout per machine and record the exact revision. Select a published source release from [GitHub releases](https://github.com/nckrtl/orbit/releases), or an explicitly chosen development commit when no alpha tag is available. Do not assume a tag exists.

```bash
git clone https://github.com/nckrtl/orbit.git orbit
cd orbit
git checkout --detach <release-tag-or-full-commit>
git rev-parse HEAD
```

Replace angle-bracket placeholders before running commands. Keep `packages/php-sdk` beside `apps/cli`: Composer resolves the SDK through that relative path. This installation does not require separately published packages or a binary.

Orbit Ops can place a standalone CLI binary from GitHub Actions onto a Node. The [CLI binaries](/reference/cli-binaries) page names the artifacts and dest paths. A binary does not replace this Gateway source installation.

## Install the CLI

From the repository root, install the source dependencies and verify the local PHP platform:

```bash
composer --working-dir=apps/cli install --prefer-dist --no-interaction
composer --working-dir=apps/cli check-platform-reqs
php apps/cli/orbit --version
php apps/cli/orbit list
```

Add the checkout's absolute `apps/cli` directory to your shell's `PATH` if you want to use `orbit` directly. Keep that checkout available; a symlink to the executable alone is not a standalone installation. Contributors use [the contributor setup](https://github.com/nckrtl/orbit/blob/main/CONTRIBUTING.md) instead.

## Prepare the Gateway machine

Run these commands from a root shell on a fresh Ubuntu 26.04 machine. They install prerequisites before Orbit can bootstrap itself.

```bash
apt-get update
apt-get install --yes git curl unzip openssl sqlite3 composer sudo \
  openssh-server ca-certificates iproute2 ufw wireguard-tools dnsmasq caddy \
  php8.5-cli php8.5-fpm php8.5-curl php8.5-mbstring php8.5-sqlite3 php8.5-xml
adduser --disabled-password --gecos '' orbit
printf 'orbit ALL=(ALL) NOPASSWD:ALL\n' > /etc/sudoers.d/orbit
chmod 0440 /etc/sudoers.d/orbit
visudo --check --file=/etc/sudoers.d/orbit
sudo -iu orbit
```

The dedicated account has administrative control of this Gateway and its managed Nodes. Use an empty machine for this walkthrough; do not run these account-creation commands over an existing Orbit installation. If the package source cannot provide PHP 8.5, stop and record the package error instead of bypassing Composer's platform checks.

As `orbit`, clone the selected revision under `/home/orbit/orbit`. The Gateway requires a real checkout below `/home/orbit`, not a symlink. Run the CLI installation above in this checkout, then install the Gateway:

```bash
cd /home/orbit/orbit
composer --working-dir=apps/gateway install --prefer-dist --no-interaction
composer --working-dir=apps/gateway check-platform-reqs
cd apps/gateway
cp .env.example .env
```

Edit `.env` before running Artisan. Keep the remaining template settings and set these values:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://10.44.0.1
ORBIT_HOME=/home/orbit/.orbit
ORBIT_GATEWAY_CHECKOUT=/home/orbit/orbit/apps/gateway
```

The example uses the default WireGuard address. Use your selected address consistently if its subnet conflicts with another network. The checkout setting must point to `apps/gateway`, not the monorepo root. Initialize fresh state as the same user:

```bash
umask 077
mkdir -p /home/orbit/.orbit
touch /home/orbit/.orbit/gateway.sqlite
chmod 0700 /home/orbit/.orbit
chmod 0600 .env /home/orbit/.orbit/gateway.sqlite
php artisan key:generate --no-interaction
php artisan migrate --force
```

Generate an application key only on a new installation. It encrypts stored secrets. Keep it with the database when [backing up or recovering](/reference/gateway-recovery).

## Bootstrap the Gateway

Choose the public host through which the Gateway can reach itself and other Nodes can reach its WireGuard endpoint. An IP or DNS name must resolve and route from those machines. Allow the selected UDP port in the provider firewall before continuing.

```bash
php artisan orbit:bootstrap '<GATEWAY_PUBLIC_HOST>' \
  --wireguard-endpoint='<GATEWAY_PUBLIC_HOST>:51820' \
  --no-interaction
```

Omit `--private-interface` for the ordinary public-endpoint setup. If you use a separate private underlay, inspect `ip -brief address` and pass that machine's actual interface name. Do not copy another provider's interface name or confuse it with the managed `orbit` tunnel. `php artisan orbit:bootstrap --help` lists address, subnet, DNS, and port options.

Bootstrap creates the Gateway identity, SSH and WireGuard keys, root certificate authority, private HTTPS endpoint, DNS, and Gateway/VPN roles. It changes service and firewall configuration. Verify the result:

```bash
sudo systemctl is-active php8.5-fpm caddy wg-quick@orbit dnsmasq
sudo caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
```

Every service must report `active`. A failure message names its step and error code. Preserve that output and inspect it before retrying the same bootstrap input.

## Use private DNS on the Gateway

The Gateway serves Orbit DNS but does not receive the managed peer's resolver configuration. To use this Gateway as the first operator machine, configure its local resolver after bootstrap succeeds. On the fresh Ubuntu installation in this guide, run:

```bash
sudo install -d /etc/systemd/resolved.conf.d
printf '[Resolve]\nDNS=10.44.0.1\nDomains=~.\n' | \
  sudo tee /etc/systemd/resolved.conf.d/orbit-gateway.conf >/dev/null
sudo ln -sfn /run/systemd/resolve/stub-resolv.conf /etc/resolv.conf
sudo systemctl restart systemd-resolved
getent ahostsv4 gateway.orbit
getent ahostsv4 github.com
```

Use the selected Gateway WireGuard address if you changed the default. These commands select Orbit DNS for ordinary and private names and use Ubuntu's systemd-resolved stub. Preserve an existing custom resolver configuration before replacing it. The DNS backend uses separate uplink resolvers, as [private DNS](/reference/private-dns#availability-and-upstream-resolution) describes.

## Connect the CLI

Run this on the Gateway as `orbit`, using the same checkout:

```bash
cd /home/orbit/orbit
export PATH="/home/orbit/orbit/apps/cli:$PATH"
orbit gateway:add 10.44.0.1 --name=alpha --use \
  --ca=/home/orbit/.orbit/ca/root.pem
orbit gateway:status
orbit node:list
```

Registration stores the profile under `ORBIT_HOME` and installs the Gateway root certificate into the local trust store. It can request sudo. The connection must use HTTPS and the private address; do not bypass TLS verification. The Gateway identifies callers by their active WireGuard Node address, not a bearer token.

The Gateway's own Node has fleet authority. To use another Linux operator machine, first provision it from the Gateway with `php artisan orbit:node-provision`, as described in [Node provisioning](/reference/node-provisioning). Use its provider-verified SSH fingerprint and authorize the Gateway public SSH key for its bootstrap account. Install the CLI there after its tunnel works. From the Gateway CLI, grant that Node access with `orbit node:access:add <operator-id> <gateway-id>`, then register the Gateway profile on the operator. Access to the Gateway grants fleet-wide authority.

Continue with [your first app](/reference/first-app) once `gateway:status` and `node:list` succeed.
