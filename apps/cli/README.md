# Orbit CLI

The small Laravel Zero client for the Orbit gateway. Fleet commands call the
gateway over HTTPS through `nckrtl/orbit-php-sdk`. The CLI never connects to
managed nodes through SSH.

## Development

Clone this repository beside `orbit-php-sdk`, then install dependencies:

```bash
composer install
./orbit gateway:add local https://gateway.orbit --ca="$HOME/.orbit/ca/root.pem"
./orbit gateway:status
```

Composer symlinks `../php-sdk`, so local SDK changes are available at once.
Gateway profiles are stored in `$HOME/.orbit/config.json`. Set `ORBIT_HOME` to
override that directory.

## Quality

```bash
composer test       # Pest 5 with local TIA
composer test:full  # full Pest suite
composer format     # Mago formatter
composer check      # TIA tests and all Mago checks
```
