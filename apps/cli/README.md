# Orbit CLI

The small Laravel Zero client for the Orbit gateway. Fleet commands call the
gateway over HTTPS through `nckrtl/orbit-php-sdk`. The CLI never connects to
managed nodes through SSH.

## Development

From the monorepo root, install every project:

```bash
bin/bootstrap
```

Composer symlinks `packages/php-sdk` into the CLI, so local SDK changes are
available at once.

## First Use

Register the gateway and verify the connection:

```bash
./orbit gateway:add 10.70.0.1
./orbit gateway:status
```

Gateway profiles are stored in `$HOME/.orbit/config.json`. Set `ORBIT_HOME` to
override that directory. `gateway:add` installs its root CA in the local operating-system trust store.
It can ask for local administrator privileges.

## Local DNS overrides

On macOS, route one development TLD directly to an IP address:

```bash
./orbit dns:resolve beast 192.168.6.20
./orbit dns:resolve beast --reset
```

The command uses Homebrew dnsmasq and `/etc/resolver/<tld>`. Restart open
browsers after a change so they do not reuse an existing connection.

## Doctor

```bash
./orbit doctor
./orbit doctor --node=7 --family=instance --family=workspace
./orbit doctor --family=firewall --json
```

Doctor only verifies state and never repairs it. Exit status 1 means unhealthy,
unverifiable, or a transport failure.

## JavaScript processes

```bash
./orbit process:add vite \
    --instance=12 \
    --runtime=systemd \
    --command=/usr/local/bin/vp \
    --command=run \
    --command=dev \
    --command=--host=0.0.0.0 \
    --working-directory=/home/orbit/apps/acme \
    --restart=always \
    --start
```

Use `vp install` for project dependencies and `vp run <script>` for package
scripts. Vite+ follows its native package-manager selection order and
defaults projects without a manager signal to pnpm. Bun is used only when
project state selects it. Orbit installs pnpm by default.
Orbit installs Bun separately from the Vite+-managed Node runtime. PHP
dependencies continue to use Composer.

## Tool Management

```bash
./orbit tool:manager:list --node=12
./orbit tool:install @openai/codex --node=12 --manager=vp --constraint='^0.150'
./orbit tool:list --node=12
./orbit tool:update 41
./orbit tool:show 41
./orbit tool:remove 41
```

Use `vp` for npm-compatible global tools such as Codex and Claude Code. Use
Composer for `vendor/package` tools and APT for Ubuntu packages. A constraint
only blocks an unsafe normal candidate. The Gateway owns all package-manager
and version decisions.

## Quality

```bash
composer test       # full Pest 5 suite in parallel, without TIA
composer format     # Mago formatter
composer check      # parallel no-TIA tests and all Mago checks
```
