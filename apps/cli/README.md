# Orbit CLI

The small Laravel Zero client for the Orbit gateway. Fleet commands call the
gateway over HTTPS through `nckrtl/orbit-php-sdk`. The CLI never connects to
managed nodes through SSH.

## Development

Clone this repository beside `orbit-php-sdk`, then install dependencies:

```bash
composer install
```

Composer symlinks `../php-sdk`, so local SDK changes are available at once.

## First Use

Register the gateway, trust its root CA locally, then verify the connection:

```bash
./orbit gateway:add local https://gateway.orbit --use
./orbit gateway:trust
./orbit gateway:status
```

Gateway profiles are stored in `$HOME/.orbit/config.json`. Set `ORBIT_HOME` to
override that directory. `gateway:trust` is a visible local operating-system trust step.
It can ask for local administrator privileges.

## Local macOS app-dev setup

Enroll the Mac with its existing WireGuard public key. Then assign and set up
the `app-dev` role:

```bash
./orbit node:provision mini \
  --platform=darwin \
  --architecture=arm64 \
  --ssh-user='<personal-user>' \
  --tld=test \
  --wireguard-public-key='<existing-public-key>'
./orbit node:role:add 2 app-dev
./orbit node:setup app-dev
```

`node:setup` requires an interactive controlling terminal. It shows the
gateway-approved summary and asks for confirmation before it runs the local
script. It uses direct terminal input and output for protected prompts, even
when JSON output is redirected. The CLI does not keep the setup script or transcript
after the owned process tree exits.

The gateway owns role and runtime policy. The CLI transports an explicitly
supplied `process:add --runtime` value unchanged within the public 64-byte,
control-free boundary. If the option is absent, the CLI omits it so the gateway
can select the platform default.

## Quality

```bash
composer test       # Pest 5 with local TIA
composer test:full  # full Pest suite
composer format     # Mago formatter
composer check      # TIA tests and all Mago checks
```
