<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\UbuntuRelease;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\AppProd\AppProdSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;
use Illuminate\Support\Collection;

final readonly class RemotePhpPackageManager
{
    private const string EXPECTED_DISTRIBUTION = 'ubuntu';

    private const string SOURCE_URI = 'https://packages.sury.org/php/';

    private const string KEY_URL = 'https://packages.sury.org/php/apt.gpg';

    private const string KEYRING_PATH = '/usr/share/keyrings/orbit-sury-php.gpg';

    private const string SOURCE_PATH = '/etc/apt/sources.list.d/orbit-php.sources';

    private const string KEY_SHA256 = 'b486fd5488185c4c46467960fa69c53d5085fec492cf76b9eaf3db33561c9d7c';

    private const string PRIMARY_FINGERPRINT = '15058500A0235D97F5D10063B188E2B695BD4743';

    private const string SECONDARY_FINGERPRINT = '45BEA3E529112086C622F8A4B214EAC28059B8AC';

    private const string SURY_SIGNER = self::PRIMARY_FINGERPRINT;

    /** @var list<string> */
    private const array COMMON_SUFFIXES = [
        'cli',
        'fpm',
        'common',
        'bcmath',
        'curl',
        'gd',
        'imagick',
        'intl',
        'mbstring',
        'mysql',
        'pgsql',
        'redis',
        'sqlite3',
        'xml',
        'zip',
    ];

    /** @var array{source_step: string, source_error: string, install_step: string, install_error: string} */
    private const array APP_DEV_FAILURE_CONTRACT = [
        'source_step' => 'php-package-source',
        'source_error' => 'app-dev.php_package_source_unavailable',
        'install_step' => 'php-fpm-install',
        'install_error' => 'app-dev.php_install_failed',
    ];

    /** @var array{source_step: string, source_error: string, install_step: string, install_error: string} */
    private const array APP_PROD_FAILURE_CONTRACT = [
        'source_step' => 'app-prod-php-package-source',
        'source_error' => 'app-prod.php_package_source_unavailable',
        'install_step' => 'app-prod-php-fpm-install',
        'install_error' => 'app-prod.php_install_failed',
    ];

    /** @param Collection<int, string> $versions */
    public function installForAppDev(Node $node, Collection $versions, AppDevSshExecutor $ssh): void
    {
        $this->install($node, $versions, $ssh, RoleName::AppDev, profile: 'app-dev');
    }

    /** @param Collection<int, string> $versions */
    public function installForAppProd(Node $node, Collection $versions, AppProdSshExecutor $ssh): void
    {
        $needsPcov = $node->roles->pluck('role')->contains(RoleName::AppDev);

        $profile = $needsPcov ? 'app-dev' : 'app-prod';

        $this->install($node, $versions, $ssh, RoleName::AppProd, $profile);
    }

    /**
     * @param Collection<int, string> $versions
     */
    private function install(
        Node $node,
        Collection $versions,
        AppDevSshExecutor|AppProdSshExecutor $ssh,
        RoleName $role,
        string $profile,
    ): void {
        $failure = match ($role) {
            RoleName::AppDev => self::APP_DEV_FAILURE_CONTRACT,
            RoleName::AppProd => self::APP_PROD_FAILURE_CONTRACT,
            default => throw new \LogicException('PHP package installation requires an application role.'),
        };
        if ($versions->isEmpty()) {
            return;
        }

        /** @var array<string, list<string>> $profiles */
        $profiles = [];

        foreach ($versions as $version) {
            $profiles[$version] = $this->packages($version, $profile);
        }

        $allPackages = array_values(array_unique(array_merge(...array_values($profiles))));

        $this->convergeSource($node, $allPackages, $ssh, $failure, $role);

        foreach ($profiles as $version => $packages) {
            $this->installProfile(
                $node,
                $version,
                $profile,
                $packages,
                $ssh,
                $failure,
                $role,
            );
        }
    }

    /**
     * @param list<string> $packages
     * @param array{source_step: string, source_error: string, install_step: string, install_error: string} $failure
     */
    private function convergeSource(
        Node $node,
        array $packages,
        AppDevSshExecutor|AppProdSshExecutor $ssh,
        array $failure,
        RoleName $role,
    ): void {
        $ssh->execute(
            $node,
            new RemoteCommand(
                arguments: [
                    'bash',
                    '-seu',
                    '--',
                    self::EXPECTED_DISTRIBUTION,
                    UbuntuRelease::unsupportedText(),
                    (string) count(UbuntuRelease::forRole($role)),
                    ...array_map(
                        static fn (UbuntuRelease $release): string => $release->value,
                        UbuntuRelease::forRole($role),
                    ),
                    self::SOURCE_URI,
                    self::KEY_URL,
                    self::KEYRING_PATH,
                    self::SOURCE_PATH,
                    self::KEY_SHA256,
                    self::PRIMARY_FINGERPRINT,
                    self::SECONDARY_FINGERPRINT,
                    self::SURY_SIGNER,
                    ...$packages,
                ],
                input: <<<'BASH'
                    expected_id=$1
                    unsupported_text=$2
                    allowed_count=$3
                    shift 3
                    allowed_codenames=("${@:1:$allowed_count}")
                    shift "$allowed_count"
                    expected_uri=$1
                    key_url=$2
                    keyring_path=$3
                    source_path=$4
                    expected_sha256=$5
                    primary_fingerprint=$6
                    secondary_fingerprint=$7
                    sury_signer=$8
                    shift 8

                    fail_os() {
                        if [ "$#" -eq 2 ] && [ -n "$1" ] && [ -n "$2" ]; then
                            printf 'Node operating system [%s/%s] is not supported.\n' "$1" "$2" >&2
                        else
                            printf '%s\n' "$unsupported_text" >&2
                        fi
                        exit 1
                    }

                    if [ ! -r /etc/os-release ]; then
                        fail_os
                    fi

                    os_id=''
                    os_codename=''
                    id_seen=0
                    codename_seen=0
                    while IFS= read -r os_line || [ -n "$os_line" ]; do
                        case "$os_line" in
                            ID=*) os_key=ID; os_value=${os_line#ID=} ;;
                            VERSION_CODENAME=*) os_key=VERSION_CODENAME; os_value=${os_line#VERSION_CODENAME=} ;;
                            ''|\#*) continue ;;
                            *) continue ;;
                        esac
                        case "$os_value" in
                            \"*\") os_value=${os_value#\"}; os_value=${os_value%\"} ;;
                            \'*\') os_value=${os_value#\'}; os_value=${os_value%\'} ;;
                            *\"|\"*) fail_os ;;
                            *\'|\'*) fail_os ;;
                        esac
                        case "$os_value" in ''|*[!A-Za-z0-9._-]*) fail_os ;; esac
                        if [ "$os_key" = ID ]; then
                            [ "$id_seen" -eq 0 ] || fail_os
                            os_id=$os_value; id_seen=1
                        else
                            [ "$codename_seen" -eq 0 ] || fail_os
                            os_codename=$os_value; codename_seen=1
                        fi
                    done < /etc/os-release

                    selected_codename=''
                    for allowed_codename in "${allowed_codenames[@]}"; do
                        if [ "$os_codename" = "$allowed_codename" ]; then selected_codename=$allowed_codename; fi
                    done
                    if [ "$os_id" != "$expected_id" ] || [ -z "$selected_codename" ]; then
                        fail_os "$os_id" "$os_codename"
                    fi

                    for configured_source in \
                        /etc/apt/sources.list \
                        /etc/apt/sources.list.d/*.list \
                        /etc/apt/sources.list.d/*.sources
                    do
                        if [ ! -f "$configured_source" ] || [ "$configured_source" = "$source_path" ]; then
                            continue
                        fi

                        if sudo grep -Eiq \
                            'ppa\.launchpadcontent\.net/ondrej/php|packages\.sury\.org/php|ondrej/php' \
                            -- "$configured_source"
                        then
                            printf '%s\n' 'A conflicting PHP package source is configured.' >&2
                            exit 1
                        fi
                    done

                    for managed_path in "$keyring_path" "$source_path"; do
                        if [ ! -e "$managed_path" ] && [ ! -L "$managed_path" ]; then
                            continue
                        fi

                        if [ -L "$managed_path" ] \
                            || [ ! -f "$managed_path" ] \
                            || [ "$(stat -c '%U:%G' -- "$managed_path")" != root:root ] \
                            || [ "$(stat -c '%a' -- "$managed_path")" != 644 ]
                        then
                            printf '%s\n' 'An Orbit PHP package source file has unsafe ownership or mode.' >&2
                            exit 1
                        fi
                    done

                    umask 077
                    work_directory=$(mktemp -d)
                    gnupg_home="$work_directory/gnupg"
                    install -d -m 0700 -- "$gnupg_home"
                    downloaded_key="$work_directory/apt.gpg"
                    source_candidate="$work_directory/orbit-php.sources"
                    key_backup="$work_directory/keyring.backup"
                    source_backup="$work_directory/source.backup"
                    had_key=0
                    had_source=0
                    published=0
                    keyring_candidate=''
                    apt_source_candidate=''

                    if [ -f "$keyring_path" ]; then
                        cp -- "$keyring_path" "$key_backup"
                        had_key=1
                    fi

                    if [ -f "$source_path" ]; then
                        cp -- "$source_path" "$source_backup"
                        had_source=1
                    fi

                    restore_source() {
                        status=$?
                        trap - EXIT

                        if [ "$status" -ne 0 ] && [ "$published" -eq 1 ]; then
                            if [ "$had_key" -eq 1 ]; then
                                sudo install -m 0644 -o root -g root -- "$key_backup" "$keyring_path"
                            else
                                sudo rm -f -- "$keyring_path"
                            fi

                            if [ "$had_source" -eq 1 ]; then
                                sudo install -m 0644 -o root -g root -- "$source_backup" "$source_path"
                            else
                                sudo rm -f -- "$source_path"
                            fi
                        fi

                        if [ -n "$keyring_candidate" ]; then
                            sudo rm -f -- "$keyring_candidate"
                        fi

                        if [ -n "$apt_source_candidate" ]; then
                            sudo rm -f -- "$apt_source_candidate"
                        fi

                        rm -rf -- "$work_directory"
                        exit "$status"
                    }
                    trap restore_source EXIT

                    curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 \
                        --output "$downloaded_key" \
                        "$key_url"
                    printf '%s  %s\n' "$expected_sha256" "$downloaded_key" | sha256sum --check --status

                    actual_fingerprints=$(GNUPGHOME="$gnupg_home" gpg --batch --with-colons --show-keys "$downloaded_key" \
                        | awk -F: '$1 == "fpr" { print $10 }' \
                        | sort -u)
                    expected_fingerprints=$(printf '%s\n%s\n' \
                        "$primary_fingerprint" \
                        "$secondary_fingerprint" \
                        | sort -u)
                    if [ "$actual_fingerprints" != "$expected_fingerprints" ] \
                        || ! printf '%s\n' "$actual_fingerprints" | grep -qxF "$sury_signer"
                    then
                        printf '%s\n' 'The Sury PHP signing key identity does not match Orbit pins.' >&2
                        exit 1
                    fi

                    printf 'Types: deb\nURIs: %s\nSuites: %s\nComponents: main\nSigned-By: %s\n' \
                        "$expected_uri" \
                        "$selected_codename" \
                        "$keyring_path" \
                        > "$source_candidate"

                    if [ ! -f "$keyring_path" ] || ! cmp -s -- "$downloaded_key" "$keyring_path"; then
                        keyring_candidate=$(sudo mktemp "${keyring_path}.orbit.XXXXXX")
                        published=1
                        sudo install -m 0644 -o root -g root -- "$downloaded_key" "$keyring_candidate"
                        sudo mv -- "$keyring_candidate" "$keyring_path"
                        keyring_candidate=''
                    fi

                    if [ ! -f "$source_path" ] || ! cmp -s -- "$source_candidate" "$source_path"; then
                        apt_source_candidate=$(sudo mktemp "${source_path}.orbit.XXXXXX")
                        published=1
                        sudo install -m 0644 -o root -g root -- "$source_candidate" "$apt_source_candidate"
                        sudo mv -- "$apt_source_candidate" "$source_path"
                        apt_source_candidate=''
                    fi

                    sudo env DEBIAN_FRONTEND=noninteractive \
                        apt-get -o DPkg::Lock::Timeout=300 update

                    expected_origin="${expected_uri%/} $selected_codename/main"
                    package_architecture=$(dpkg --print-architecture)
                    for package in "$@"; do
                        policy=$(apt-cache policy -- "$package")
                        candidate=$(printf '%s\n' "$policy" | awk '$1 == "Candidate:" { print $2; exit }')
                        if [ -z "$candidate" ] || [ "$candidate" = '(none)' ]; then
                            printf '%s\n' "A required PHP package candidate is unavailable: $package" >&2
                            exit 1
                        fi

                        madison=$(apt-cache madison -- "$package")
                        if printf '%s\n' "$madison" | awk -F '|' \
                            -v candidate="$candidate" \
                            -v package="$package" \
                            -v architecture="$package_architecture" \
                            -v origin="$expected_origin" '
                                function trim(value) {
                                    gsub(/^[[:space:]]+|[[:space:]]+$/, "", value)
                                    return value
                                }
                                NF != 3 || trim($1) != package { malformed = 1 }
                                NF == 3 && trim($1) == package && trim($2) == candidate {
                                    value = trim($3)
                                    prefix = origin " "
                                    if (index(value, prefix) == 1) {
                                        suffix = substr(value, length(prefix) + 1)
                                        count = split(suffix, fields, /[[:space:]]+/)
                                        if (count == 2 && fields[1] == architecture && fields[2] == "Packages") { found = 1 }
                                    }
                                    else { foreign = 1 }
                                }
                                END { exit found && ! foreign && ! malformed ? 0 : 1 }
                            '; then
                            continue
                        fi

                        installed=$(dpkg-query -W -f='${Status} ${Version}\n' -- "$package" 2>/dev/null || true)
                        if ! printf '%s\n' "$installed" | awk -v candidate="$candidate" \
                            '$1 == "install" && $2 == "ok" && $3 == "installed" && $4 == candidate { found = 1 } END { exit found ? 0 : 1 }'
                        then
                            printf '%s\n' "A required PHP package candidate is unavailable from the pinned Sury source: $package" >&2
                            exit 1
                        fi

                        printf '%s\n' "$madison" | awk -F '|' -v candidate="$candidate" -v package="$package" -v architecture="$package_architecture" -v origin="$expected_origin" '
                            function trim(value) { gsub(/^[[:space:]]+|[[:space:]]+$/, "", value); return value }
                            NF != 3 || trim($1) != package { malformed = 1 }
                            NF == 3 {
                                version = trim($2); value = trim($3); prefix = origin " "
                                if (trim($1) != package) { malformed = 1 }
                                else if (version == candidate) { foreign = 1 }
                                else if (index(value, prefix) == 1) {
                                    suffix = substr(value, length(prefix) + 1)
                                    count = split(suffix, fields, /[[:space:]]+/)
                                    if (count == 2 && fields[1] == architecture && fields[2] == "Packages") { found = 1 } else { malformed = 1 }
                                }
                            }
                            END { exit found && ! foreign && ! malformed ? 0 : 1 }
                        '
                    done

                    published=0
                    trap - EXIT
                    rm -rf -- "$work_directory"
                    BASH,
            ),
            step: $failure['source_step'],
            errorCode: $failure['source_error'],
        );
    }

    /**
     * @param list<string> $packages
     * @param array{source_step: string, source_error: string, install_step: string, install_error: string} $failure
     * @mago-expect lint:excessive-parameter-list The executor and stable failure contract stay explicit at this remote boundary.
     */
    private function installProfile(
        Node $node,
        string $version,
        string $profile,
        array $packages,
        AppDevSshExecutor|AppProdSshExecutor $ssh,
        array $failure,
        RoleName $role,
    ): void {
        $pcovSetup = $profile === 'app-dev'
            ? <<<'BASH'
                sudo phpenmod -v "$version" -s cli pcov
                sudo phpdismod -v "$version" -s fpm pcov
                BASH
            : '';
        $pcovVerification = $profile === 'app-dev'
            ? <<<'BASH'
                printf '%s\n' "$cli_modules" | grep -qxF pcov
                BASH
            : <<<'BASH'
                if printf '%s\n' "$cli_modules" | grep -qxF pcov; then
                    exit 1
                fi
                BASH;

        $ssh->execute(
            $node,
            new RemoteCommand(
                arguments: [
                    'bash',
                    '-seu',
                    '--',
                    $version,
                    $profile,
                    base64_encode(new PhpFpmRuntimeIniRenderer()->render($profile)),
                    UbuntuRelease::unsupportedText(),
                    (string) count(UbuntuRelease::forRole($role)),
                    ...array_map(
                        static fn (UbuntuRelease $release): string => $release->value,
                        UbuntuRelease::forRole($role),
                    ),
                    ...$packages,
                ],
                input: <<<'BASH'
                    version=$1
                    profile=$2
                    runtime_ini=$3
                    unsupported_text=$4
                    allowed_count=$5
                    shift 5
                    allowed_codenames=("${@:1:$allowed_count}")
                    shift "$allowed_count"

                    fail_os() {
                        if [ "$#" -eq 2 ] && [ -n "$1" ] && [ -n "$2" ]; then
                            printf 'Node operating system [%s/%s] is not supported.\n' "$1" "$2" >&2
                        else
                            printf '%s\n' "$unsupported_text" >&2
                        fi
                        exit 1
                    }

                    if [ ! -r /etc/os-release ]; then
                        fail_os
                    fi

                    os_id=''
                    os_codename=''
                    id_seen=0
                    codename_seen=0
                    while IFS= read -r os_line || [ -n "$os_line" ]; do
                        case "$os_line" in
                            ID=*) os_key=ID; os_value=${os_line#ID=} ;;
                            VERSION_CODENAME=*) os_key=VERSION_CODENAME; os_value=${os_line#VERSION_CODENAME=} ;;
                            ''|\#*) continue ;;
                            *) continue ;;
                        esac
                        case "$os_value" in
                            \"*\") os_value=${os_value#\"}; os_value=${os_value%\"} ;;
                            \'*\') os_value=${os_value#\'}; os_value=${os_value%\'} ;;
                            *\"|\"*) fail_os ;;
                            *\'|\'*) fail_os ;;
                        esac
                        case "$os_value" in ''|*[!A-Za-z0-9._-]*) fail_os ;; esac
                        if [ "$os_key" = ID ]; then [ "$id_seen" -eq 0 ] || fail_os; os_id=$os_value; id_seen=1
                        else [ "$codename_seen" -eq 0 ] || fail_os; os_codename=$os_value; codename_seen=1
                        fi
                    done < /etc/os-release

                    selected_codename=''
                    for allowed_codename in "${allowed_codenames[@]}"; do
                        if [ "$os_codename" = "$allowed_codename" ]; then selected_codename=$allowed_codename; fi
                    done
                    if [ "$os_id" != ubuntu ] || [ -z "$selected_codename" ]; then
                        fail_os "$os_id" "$os_codename"
                    fi

                    missing_packages=()
                    for package in "$@"; do
                        case "$package" in
                            "php$version-"*) ;;
                            *) exit 1 ;;
                        esac

                        if ! dpkg-query -W -f='${Status}' -- "$package" 2>/dev/null \
                            | grep -qxF 'install ok installed'
                        then
                            missing_packages+=("$package")
                        fi
                    done

                    if [ "${#missing_packages[@]}" -gt 0 ]; then
                        sudo env DEBIAN_FRONTEND=noninteractive \
                            apt-get -o DPkg::Lock::Timeout=300 install \
                            --yes --no-install-recommends -- \
                            "${missing_packages[@]}"
                    fi

                    for package in "$@"; do
                        dpkg-query -W -f='${Status}' -- "$package" \
                            | grep -qxF 'install ok installed'
                    done

                    runtime_module=orbit-runtime
                    runtime_available="/etc/php/$version/mods-available/$runtime_module.ini"
                    runtime_enabled="/etc/php/$version/fpm/conf.d/99-$runtime_module.ini"
                    runtime_work=$(mktemp -d)
                    runtime_candidate="$runtime_work/candidate.ini"
                    runtime_backup="$runtime_work/previous.ini"
                    printf '%s' "$runtime_ini" | base64 --decode > "$runtime_candidate"
                    runtime_had_file=0
                    if [ -f "$runtime_available" ]; then
                        cp -- "$runtime_available" "$runtime_backup"
                        runtime_had_file=1
                    fi
                    runtime_had_link=0
                    if [ -L "$runtime_enabled" ]; then
                        runtime_had_link=1
                    fi
                    runtime_changed=0
                    restore_runtime() {
                        status=$?
                        trap - EXIT
                        if [ "$status" -ne 0 ] && [ "$runtime_changed" = 1 ]; then
                            if [ "$runtime_had_file" = 1 ]; then
                                sudo install -o root -g root -m 0644 -- "$runtime_backup" "$runtime_available"
                            else
                                sudo rm -f -- "$runtime_available"
                            fi
                            if [ "$runtime_had_link" = 0 ]; then
                                sudo rm -f -- "$runtime_enabled"
                            fi
                            if sudo systemctl is-active --quiet "php$version-fpm.service"; then
                                sudo systemctl reload-or-restart "php$version-fpm.service" || true
                            fi
                        fi
                        rm -rf -- "$runtime_work"
                        exit "$status"
                    }
                    trap restore_runtime EXIT
                    if [ "$runtime_had_file" = 0 ] || ! cmp -s -- "$runtime_candidate" "$runtime_available"; then
                        sudo install -o root -g root -m 0644 -- "$runtime_candidate" "$runtime_available"
                        runtime_changed=1
                    fi
                    if [ "$runtime_had_link" = 0 ] || [ "$(readlink -f -- "$runtime_enabled")" != "$runtime_available" ]; then
                        sudo phpenmod -v "$version" -s fpm "$runtime_module"
                        runtime_changed=1
                    fi
                    test -L "$runtime_enabled"
                    test "$(readlink -f -- "$runtime_enabled")" = "$runtime_available"
                    fpm_ini=$(/usr/sbin/php-fpm"$version" -i)
                    printf '%s\n' "$fpm_ini" | grep -qF -- "$runtime_enabled"
                    while IFS= read -r runtime_line || [ -n "$runtime_line" ]; do
                        case "$runtime_line" in ''|';'*) continue ;; esac
                        runtime_key=${runtime_line%%=*}
                        runtime_key=${runtime_key%"${runtime_key##*[! ]}"}
                        runtime_value=${runtime_line#*=}
                        runtime_value=${runtime_value#"${runtime_value%%[! ]*}"}
                        printf '%s\n' "$fpm_ini" | grep -qxF -- "$runtime_key => $runtime_value => $runtime_value"
                    done < "$runtime_candidate"
                    fpm_pcov_before=$(readlink -f -- /etc/php/"$version"/fpm/conf.d/*-pcov.ini 2>/dev/null || true)
                    BASH."\n".$pcovSetup."\n".<<<'BASH'
                    fpm_pcov_after=$(readlink -f -- /etc/php/"$version"/fpm/conf.d/*-pcov.ini 2>/dev/null || true)
                    if [ "$fpm_pcov_before" != "$fpm_pcov_after" ]; then
                        runtime_changed=1
                    fi
                    if [ "$runtime_changed" = 1 ] && sudo systemctl is-active --quiet "php$version-fpm.service"; then
                        sudo systemctl reload-or-restart "php$version-fpm.service"
                    fi
                    trap - EXIT
                    rm -rf -- "$runtime_work"
                    sudo systemctl enable --now "php$version-fpm.service"

                    /usr/bin/php"$version" -v >/dev/null
                    /usr/sbin/php-fpm"$version" -v >/dev/null
                    cli_modules=$(/usr/bin/php"$version" -m | tr '[:upper:]' '[:lower:]')
                    fpm_modules=$(/usr/sbin/php-fpm"$version" -m | tr '[:upper:]' '[:lower:]')
                    for module in bcmath curl gd imagick intl mbstring mysqli pdo_mysql pdo_pgsql redis pdo_sqlite simplexml xml zip; do
                        printf '%s\n' "$cli_modules" | grep -qxF "$module"
                        printf '%s\n' "$fpm_modules" | grep -qxF "$module"
                    done
                    BASH."\n".$pcovVerification."\n".<<<'BASH'
                    if printf '%s\n' "$fpm_modules" | grep -qxF pcov; then
                        exit 1
                    fi

                    sudo systemctl is-enabled --quiet "php$version-fpm.service"
                    sudo systemctl is-active --quiet "php$version-fpm.service"
                    BASH,
            ),
            step: $failure['install_step'],
            errorCode: $failure['install_error'],
        );
    }

    /** @return list<string> */
    private function packages(string $version, string $profile): array
    {
        $suffixes = self::COMMON_SUFFIXES;

        if ($version === '8.4') {
            $suffixes[] = 'opcache';
        }

        if ($profile === 'app-dev') {
            $suffixes[] = 'pcov';
        }

        return array_map(
            static fn (string $suffix): string => "php{$version}-{$suffix}",
            $suffixes,
        );
    }
}
