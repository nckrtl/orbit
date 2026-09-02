#!/usr/bin/env bash
set -euo pipefail
umask 077

root=/var/lib/orbit-e2e/pcov
version=8.5
source_uri=https://packages.sury.org/php/
key_url=https://packages.sury.org/php/apt.gpg
key_sha256=b486fd5488185c4c46467960fa69c53d5085fec492cf76b9eaf3db33561c9d7c
primary_fingerprint=15058500A0235D97F5D10063B188E2B695BD4743
secondary_fingerprint=45BEA3E529112086C622F8A4B214EAC28059B8AC
keyring=/usr/share/keyrings/orbit-sury-php.gpg
source_file=/etc/apt/sources.list.d/orbit-php.sources
collector=$root/collector.php
active=$root/active.json
observation_ini=/etc/php/$version/mods-available/orbit-e2e-observe.ini
disabled_ini=/etc/php/$version/mods-available/orbit-e2e-pcov-disabled.ini
fpm_drop_in=/etc/systemd/system/php$version-fpm.service.d/orbit-e2e-sury.conf

safe_identity() {
  [[ "$1" =~ ^[A-Z][A-Z0-9]*-[1-9][0-9]*$ && "$2" =~ ^[0-9a-f]{32}$ ]]
}

is_sury_package() {
  local package=$1 package_version=$2 package_source=$3
  if apt-cache madison "$package" | awk -F' \\| ' \
    -v version="$package_version" -v source="$package_source" \
    '$2 == version && $3 == source { found = 1 } END { exit !found }'; then
    return 0
  fi
  [[ "$package_version" =~ \+0~[0-9]{8}\.[0-9]+\+ubuntu[0-9]+\.[0-9]+~[0-9]+\.gbp[0-9a-f]+$ ]]
}

install_sury() {
  local mode=$1 os_id= os_codename= architecture key_tmp fingerprints
  [[ "$mode" == runtime || "$mode" == pcov ]]
  . /etc/os-release
  os_id=${ID-}
  os_codename=${VERSION_CODENAME-}
  [[ "$os_id" == ubuntu && "$os_codename" =~ ^[a-z0-9][a-z0-9.-]*$ ]]
  architecture=$(dpkg --print-architecture)
  [[ "$architecture" =~ ^[a-z0-9][a-z0-9-]*$ ]]

  key_tmp=$(mktemp)
  trap 'rm -f "${key_tmp:-}"' RETURN
  curl --fail --silent --show-error --location "$key_url" --output "$key_tmp"
  [[ "$(sha256sum "$key_tmp" | cut -d' ' -f1)" == "$key_sha256" ]]
  fingerprints=$(gpg --show-keys --with-colons "$key_tmp" | awk -F: '$1 == "fpr" { print $10 }')
  [[ "$fingerprints" == "$primary_fingerprint"$'\n'"$secondary_fingerprint" ]]
  if [[ ! -f "$keyring" ]] || ! cmp -s -- "$key_tmp" "$keyring"; then
    install -o root -g root -m 0644 "$key_tmp" "$keyring"
  fi

  local expected_source
  expected_source=$(printf 'Types: deb\nURIs: %s\nSuites: %s\nComponents: main\nSigned-By: %s\n' \
    "$source_uri" "$os_codename" "$keyring")
  if [[ ! -f "$source_file" ]] || ! printf '%s\n' "$expected_source" | cmp -s - "$source_file"; then
    printf '%s\n' "$expected_source" > "$source_file"
  fi

  apt-get -o DPkg::Lock::Timeout=300 update
  local -a packages=(php8.5-cli php8.5-fpm php8.5-common php8.5-curl php8.5-mbstring php8.5-sqlite3 php8.5-xml)
  [[ "$mode" == runtime ]] || packages+=(php8.5-pcov)
  local package package_version package_source needs_upgrade=0
  package_source="${source_uri%/} $os_codename/main $architecture Packages"
  for package in "${packages[@]}"; do
    if package_version=$(dpkg-query -W -f='${Version}' -- "$package" 2>/dev/null); then
      is_sury_package "$package" "$package_version" "$package_source" || needs_upgrade=1
    fi
  done
  local -a install_options=(--yes --no-install-recommends --no-remove)
  [[ "$needs_upgrade" -eq 1 ]] || install_options+=(--no-upgrade)
  DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=300 install \
    "${install_options[@]}" -- "${packages[@]}"

  for package in "${packages[@]}"; do
    package_version=$(dpkg-query -W -f='${Version}' -- "$package")
    is_sury_package "$package" "$package_version" "$package_source"
  done
  [[ -x /usr/bin/php8.5 && -x /usr/sbin/php-fpm8.5 ]]

  if [[ -L /usr/local/bin/php ]]; then
    [[ "$(readlink -- /usr/local/bin/php)" == /opt/orbit/php/8.5/bin/php ]]
    rm -- /usr/local/bin/php
  elif [[ -e /usr/local/bin/php ]]; then
    printf 'Refusing unexpected /usr/local/bin/php runtime collision.\n' >&2
    exit 65
  fi
  [[ "$(runuser -u orbit -- env PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin sh -c 'command -v php')" == /usr/bin/php ]]
  [[ "$(readlink -f /usr/bin/php)" == /usr/bin/php8.5 ]]

  install -d -o root -g root -m 0755 "$(dirname "$fpm_drop_in")"
  if [[ ! -f "$fpm_drop_in" ]] || ! printf '[Service]\nProtectSystem=false\n' | cmp -s - "$fpm_drop_in"; then
    printf '[Service]\nProtectSystem=false\n' > "$fpm_drop_in"
    chmod 0644 "$fpm_drop_in"
    systemctl daemon-reload
  fi

  if [[ "$mode" == runtime ]]; then
    systemctl enable "php$version-fpm"
    systemctl restart "php$version-fpm"
    return
  fi

  install -d -o root -g root -m 0755 "$root"
  cat > "$collector" <<'PHP'
<?php
declare(strict_types=1);

if (!extension_loaded('pcov') || !function_exists('pcov\\collect')) {
    throw new RuntimeException('PCOV observation is enabled but the extension is unavailable.');
}
$activePath = '/var/lib/orbit-e2e/pcov/active.json';
$raw = file_get_contents($activePath);
$context = is_string($raw) ? json_decode($raw, true, 8, JSON_THROW_ON_ERROR) : null;
if (!is_array($context) || array_keys($context) !== ['attempt', 'issue', 'phase', 'role', 'started_at']) {
    throw new RuntimeException('PCOV observation context is invalid.');
}
$processType = PHP_SAPI === 'cli' ? 'cli' : (PHP_SAPI === 'fpm-fcgi' ? 'fpm' : null);
if ($processType === null) {
    throw new RuntimeException('PCOV observed an unexpected PHP process type.');
}
$id = bin2hex(random_bytes(16));
$directory = '/var/lib/orbit-e2e/pcov/'.$context['phase'];
$started = microtime(true);
$startedAt = gmdate('Y-m-d\\TH:i:s.', (int) $started).sprintf('%06d', (int) (($started - floor($started)) * 1000000)).'Z';
$base = [
    'schema' => 1,
    'id' => $id,
    'attempt' => $context['attempt'],
    'issue' => $context['issue'],
    'phase' => $context['phase'],
    'role' => $context['role'],
    'process_type' => $processType,
    'pid' => getmypid(),
    'php_version' => PHP_VERSION,
    'pcov_version' => (string) phpversion('pcov'),
    'started_at' => $startedAt,
];
$write = static function (string $path, array $value): void {
    $handle = fopen($path, 'x');
    if ($handle === false) {
        throw new RuntimeException('PCOV process output collided.');
    }
    try {
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        if (fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
            throw new RuntimeException('PCOV process output could not be persisted.');
        }
    } finally {
        fclose($handle);
    }
};
$write($directory.'/'.$id.'.start.json', $base);
register_shutdown_function(static function () use ($base, $directory, $write): void {
    $coverage = pcov\collect();
    $files = array_keys($coverage);
    sort($files, SORT_STRING);
    $finished = microtime(true);
    $finishedAt = gmdate('Y-m-d\\TH:i:s.', (int) $finished).sprintf('%06d', (int) (($finished - floor($finished)) * 1000000)).'Z';
    $write($directory.'/'.$base['id'].'.result.json', [
        ...$base,
        'finished_at' => $finishedAt,
        'files' => array_values(array_unique($files)),
    ]);
});
PHP
  chmod 0644 "$collector"
  printf '; priority=98\npcov.enabled=0\n' > "$disabled_ini"
  chmod 0644 "$disabled_ini"
  phpenmod -v "$version" -s cli pcov
  phpenmod -v "$version" -s cli orbit-e2e-pcov-disabled
  phpdismod -v "$version" -s fpm pcov 2>/dev/null || true
  systemctl enable --now "php$version-fpm"
  systemctl restart "php$version-fpm"
  /usr/bin/php8.5 -r 'exit(extension_loaded("pcov") && !filter_var(ini_get("pcov.enabled"), FILTER_VALIDATE_BOOL) ? 0 : 1);'
}

begin_phase() {
  local phase=$1 role=$2 issue=$3 attempt=$4 directory
  [[ "$phase" == setup || "$phase" == acceptance ]]
  [[ "$role" == gateway || "$role" == app-dev ]]
  safe_identity "$issue" "$attempt"
  directory=$root/$phase
  rm -rf -- "$directory"
  install -d -o root -g root -m 1777 "$directory"
  printf '{"attempt":"%s","issue":"%s","phase":"%s","role":"%s","started_at":"%s"}\n' \
    "$attempt" "$issue" "$phase" "$role" "$(date -u +%Y-%m-%dT%H:%M:%S.000000Z)" > "$active"
  chmod 0644 "$active"
  cat > "$observation_ini" <<INI
; priority=99
pcov.enabled=1
pcov.directory=/home/orbit/orbit
pcov.exclude=~(?:^|/)(?:vendor|tests?)(?:/|$)~
auto_prepend_file=$collector
INI
  chmod 0644 "$observation_ini"
  phpenmod -v "$version" -s cli pcov
  phpenmod -v "$version" -s cli orbit-e2e-observe
  if [[ "$role" == gateway ]]; then
    phpenmod -v "$version" -s fpm pcov
    phpenmod -v "$version" -s fpm orbit-e2e-observe
    systemctl restart "php$version-fpm"
  fi
}

probe_cli() {
  runuser -u orbit -- env -C /home/orbit HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit \
    DB_DATABASE=/home/orbit/.orbit/gateway.sqlite orbit list --raw >/dev/null
}

probe_fpm() {
  curl --fail --silent --show-error --max-time 30 \
    --cacert /home/orbit/.orbit/ca/root.pem \
    --resolve gateway.orbit:443:127.0.0.1 https://gateway.orbit/up >/dev/null
}

collect_phase() {
  local phase=$1 role=$2 issue=$3 attempt=$4
  [[ "$phase" == setup || "$phase" == acceptance ]]
  [[ "$role" == gateway || "$role" == app-dev ]]
  safe_identity "$issue" "$attempt"
  python3 - "$root/$phase" "$phase" "$role" "$issue" "$attempt" <<'PY'
import json, pathlib, re, sys

directory, phase, role, issue, attempt = pathlib.Path(sys.argv[1]), *sys.argv[2:]
starts = {}
results = {}
for path in sorted(directory.iterdir()):
    match = re.fullmatch(r'([0-9a-f]{32})\.(start|result)\.json', path.name)
    if match is None or not path.is_file() or path.is_symlink():
        raise SystemExit('malformed PCOV process output name')
    try:
        value = json.loads(path.read_text())
    except Exception as error:
        raise SystemExit(f'malformed PCOV process output: {error}')
    if value.get('id') != match.group(1) or value.get('schema') != 1:
        raise SystemExit('malformed PCOV process output identity')
    target = starts if match.group(2) == 'start' else results
    if match.group(1) in target:
        raise SystemExit('duplicate PCOV process output')
    target[match.group(1)] = value
if not starts or starts.keys() != results.keys():
    raise SystemExit('missing or incomplete PCOV process output')
records = []
for process_id in sorted(starts):
    start = starts[process_id]
    result = results[process_id]
    start_keys = ['schema','id','attempt','issue','phase','role','process_type','pid','php_version','pcov_version','started_at']
    result_keys = start_keys + ['finished_at','files']
    if list(start) != start_keys or list(result) != result_keys:
        raise SystemExit('malformed PCOV process output shape')
    if any(result[key] != start[key] for key in start_keys):
        raise SystemExit('mismatched PCOV process output')
    if (result['attempt'], result['issue'], result['phase'], result['role']) != (attempt, issue, phase, role):
        raise SystemExit('stale PCOV process output')
    if result['process_type'] not in ('cli', 'fpm') or not isinstance(result['pid'], int):
        raise SystemExit('malformed PCOV process metadata')
    if not isinstance(result['php_version'], str) or not result['php_version'].startswith('8.5.'):
        raise SystemExit('unexpected PHP runtime')
    if not isinstance(result['pcov_version'], str) or not result['pcov_version']:
        raise SystemExit('unexpected PCOV runtime')
    if not isinstance(result['files'], list) or not all(isinstance(item, str) for item in result['files']):
        raise SystemExit('malformed PCOV file list')
    records.append(result)
print(json.dumps(records, separators=(',', ':')))
PY
}

cleanup() {
  phpdismod -v "$version" -s cli orbit-e2e-observe 2>/dev/null || true
  phpdismod -v "$version" -s fpm orbit-e2e-observe pcov 2>/dev/null || true
  rm -f -- "$observation_ini" "$active"
  if [[ -f /etc/php/$version/mods-available/pcov.ini ]]; then
    printf '; priority=98\npcov.enabled=0\n' > "$disabled_ini"
    chmod 0644 "$disabled_ini"
    phpenmod -v "$version" -s cli pcov
    phpenmod -v "$version" -s cli orbit-e2e-pcov-disabled
    /usr/bin/php8.5 -r 'exit(extension_loaded("pcov") && !filter_var(ini_get("pcov.enabled"), FILTER_VALIDATE_BOOL) ? 0 : 1);'
  fi
  if systemctl list-unit-files "php$version-fpm.service" --no-legend 2>/dev/null | grep -q .; then
    systemctl restart "php$version-fpm"
  fi
  if [[ -x /usr/sbin/php-fpm8.5 ]] && /usr/sbin/php-fpm8.5 -i | grep -q '^pcov support => Enabled$'; then
    exit 1
  fi
  [[ ! -e "$observation_ini" && ! -e "$active" ]]
}

case ${1-} in
  prepare) [[ $# -eq 2 ]]; install_sury "$2" ;;
  begin) [[ $# -eq 5 ]]; begin_phase "$2" "$3" "$4" "$5" ;;
  probe-cli) [[ $# -eq 1 ]]; probe_cli ;;
  probe-fpm) [[ $# -eq 1 ]]; probe_fpm ;;
  collect) [[ $# -eq 5 ]]; collect_phase "$2" "$3" "$4" "$5" ;;
  cleanup) [[ $# -eq 1 ]]; cleanup ;;
  *) exit 64 ;;
esac
