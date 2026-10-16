<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use stdClass;

/** A closed data contract, deliberately independent of enrollment and image building. */
final readonly class RecipeLoader
{
    /** @return array<string, string> */
    public static function catalog(): array
    {
        $catalog = array_fill_keys([
            'dnsmasq', 'ca-certificates', 'curl', 'gnupg', 'libnss-resolve', 'openssh-client', 'sudo', 'ufw', 'wireguard',
            'acl', 'attr', 'composer', 'containerd', 'docker.io', 'openssl', 'php-curl', 'php-xml', 'unzip', 'dpkg-repack',
            'openssh-server', 'rsync', 'bind9-dnsutils', 'gcc', 'libc6-dev',
            'libasound2t64', 'libatk-bridge2.0-0t64', 'libatk1.0-0t64', 'libatspi2.0-0t64', 'libcairo2',
            'libcups2t64', 'libdbus-1-3', 'libdrm2', 'libgbm1', 'libglib2.0-0t64', 'libnspr4', 'libnss3',
            'libpango-1.0-0', 'libx11-6', 'libxcb1', 'libxcomposite1', 'libxdamage1', 'libxext6',
            'libxfixes3', 'libxkbcommon0', 'libxrandr2',
        ], 'ubuntu');
        foreach (['git', 'git-man', 'caddy', 'vp', 'vp-cli', 'node', 'pnpm', 'bun', 'rust', 'cargo', 'rust-std', 'clippy', 'rustfmt', 'orbit-agent', 'libgd3', 'php-common'] as $name) {
            $catalog[$name] = 'artifact';
        }
        // The `php<version>-*` pins are artifacts too. The recipe's `php` object lists them, so they have no copy here.
        $catalog['pi'] = 'internal';

        return $catalog;
    }

    public function load(?string $path = null): MachineRecipe
    {
        $path ??= resource_path('machine-software/recipe.json');
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new InvalidArgumentException("Recipe [{$path}]: file is missing or unreadable.");
        }
        try {
            return $this->decode($bytes);
        } catch (InvalidArgumentException|JsonException $exception) {
            throw new InvalidArgumentException("Recipe [{$path}]: {$exception->getMessage()}", previous: $exception);
        }
    }

    public function decode(string $json): MachineRecipe
    {
        $data = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
        $this->rejectDuplicateKeys($json);
        $root = $this->object($data, '$', ['schema_version', 'ubuntu', 'architectures', 'tools', 'common', 'php', 'roles', 'base', 'artifact_mirror', 'git']);
        $mirror = $this->text($root->artifact_mirror, 'artifact_mirror');
        $url = parse_url($mirror);
        $this->require(is_array($url) && ($url['scheme'] ?? '') === 'https' && isset($url['host']) && ! isset($url['user']) && ! isset($url['query']) && ! isset($url['fragment']) && ! str_ends_with($mirror, '/'), 'artifact_mirror: expected an HTTPS base URL without a final slash.');
        $git = $this->object($root->git, 'git', ['packages', 'usn_since']);
        $gitPackages = $this->strings($git->packages, 'git.packages');
        $this->require($gitPackages !== [] && in_array('git', $gitPackages, true) && in_array('git-man', $gitPackages, true), 'git.packages: expected git and its companion git-man.');
        $noticeDate = DateTimeImmutable::createFromFormat('!Y-m-d', $this->text($git->usn_since, 'git.usn_since'));
        $this->require($noticeDate !== false && $noticeDate->format('Y-m-d') === $git->usn_since, 'git.usn_since: expected a real date YYYY-MM-DD.');
        $this->require($root->schema_version === 1, 'schema_version: expected integer 1.');
        $ubuntu = $this->object($root->ubuntu, 'ubuntu', ['release', 'snapshot']);
        $this->require($ubuntu->release === '26.04', 'ubuntu.release: expected supported release 26.04.');
        $snapshot = $this->text($ubuntu->snapshot, 'ubuntu.snapshot');
        $date = DateTimeImmutable::createFromFormat('!Ymd\THis\Z', $snapshot);
        $this->require($date !== false && $date->format('Ymd\THis\Z') === $snapshot, 'ubuntu.snapshot: expected a real UTC date YYYYMMDDTHHMMSSZ.');
        $architectures = $this->strings($root->architectures, 'architectures');
        $this->require($architectures !== [] && array_diff($architectures, ['amd64', 'arm64']) === [], 'architectures: expected non-empty list of amd64 and/or arm64.');
        $rawTools = $this->object($root->tools, 'tools');
        $tools = [];
        $catalog = self::catalog();
        $phpSets = $this->phpSets($root->php, $catalog);
        foreach ($phpSets['versions'] as $set) {
            $catalog = [...$catalog, ...array_fill_keys($set['packages'], 'artifact')];
        }
        foreach (get_object_vars($rawTools) as $name => $raw) {
            $this->require(isset($catalog[$name]), "tools.{$name}: unknown tool.");
            $pin = $this->object($raw, "tools.{$name}");
            $source = $this->text($pin->source ?? null, "tools.{$name}.source");
            $this->require($source === $catalog[$name], "tools.{$name}.source: expected {$catalog[$name]}.");
            $this->object($pin, "tools.{$name}", match ($source) {
                'ubuntu' => ['source', 'version', 'package'],
                'internal' => ['source', 'version', 'source_commit', 'artifacts'],
                default => ['source', 'version', 'artifacts'],
            });
            $version = $this->text($pin->version, "tools.{$name}.version");
            $this->require(preg_match('/^[0-9][0-9A-Za-z.+:~_-]*$/D', $version) === 1 || ($source === 'internal' && preg_match('/^[0-9a-f]{12,40}$/D', $version) === 1), "tools.{$name}.version: expected an exact version, not a channel or constraint.");
            if ($source === 'ubuntu') {
                $this->require($pin->package === $name, "tools.{$name}.package: expected catalog package {$name}.");
                $tools[$name] = new ToolPin($source, $version, $name);

                continue;
            }
            if ($source === 'internal') {
                $commit = $this->text($pin->source_commit, "tools.{$name}.source_commit");
                $this->require(preg_match('/^[0-9a-f]{12,40}$/D', $commit) === 1, "tools.{$name}.source_commit: expected 12 to 40 lowercase hexadecimal characters.");
            }
            $rawArtifacts = $this->object($pin->artifacts, "tools.{$name}.artifacts");
            $keys = array_keys(get_object_vars($rawArtifacts));
            $this->require($keys !== [] && array_diff($keys, $architectures) === [], "tools.{$name}.artifacts: unsupported or missing architectures.");
            if ($source !== 'internal') {
                $this->require(array_diff($architectures, $keys) === [], "tools.{$name}.artifacts: every declared architecture needs a pin.");
            }
            // This seed has no Pi arm64 binary. Do not silently assign amd64 bytes to that profile.
            $this->require($name !== 'pi' || $keys === ['amd64'], 'tools.pi.artifacts: this recipe version supports Pi on amd64 only.');
            $artifacts = [];
            foreach (get_object_vars($rawArtifacts) as $architecture => $rawArtifact) {
                $field = "tools.{$name}.artifacts.{$architecture}";
                $artifact = $this->object($rawArtifact, $field);
                $algorithm = isset($artifact->sha256) ? 'sha256' : 'sha512';
                $locationKey = $source === 'internal' ? 'filename' : 'url';
                $this->object($artifact, $field, [$locationKey, $algorithm]);
                $this->require($source !== 'internal' || $algorithm === 'sha256', "{$field}: internal pins require sha256.");
                $digest = $this->text($artifact->{$algorithm}, "{$field}.{$algorithm}");
                $length = $algorithm === 'sha256' ? 64 : 128;
                $this->require(preg_match('/^[a-f0-9]{'.$length.'}$/D', $digest) === 1, "{$field}.{$algorithm}: expected {$length} lowercase hexadecimal characters.");
                $location = $this->text($artifact->{$locationKey}, "{$field}.{$locationKey}");
                if ($source === 'internal') {
                    $this->require(preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $location) === 1, "{$field}.filename: expected a simple filename, not a path.");
                } else {
                    $url = parse_url($location);
                    $this->require(is_array($url) && ($url['scheme'] ?? '') === 'https' && isset($url['host']) && ! isset($url['user']) && ! isset($url['pass']) && ! isset($url['fragment']), "{$field}.url: expected an HTTPS release URL.");
                    $this->require(preg_match('~(?:latest|stable|/lts(?:/|$)|/install(?:[./]|$))~i', $location) !== 1 && preg_match('~(?:^|[/_-])v?'.preg_quote(str_ends_with($location, '.deb') ? (preg_replace('/^[0-9]+:/', '', $version) ?? $version) : $version, '~').'(?=$|[/_]|-(?:linux|x86_64|aarch64)(?:[-./]|$)|\\.tar\\.|\\.tgz$|\\.zip$|\\.deb$)~D', rawurldecode($location)) === 1, "{$field}.url: expected the exact version in an immutable release URL, not an installer/channel.");
                }
                $artifacts[$architecture] = new ArtifactPin($location, $algorithm, $digest);
            }
            $tools[$name] = new ToolPin($source, $version, artifacts: $artifacts);
        }
        $this->require(array_diff(array_keys($catalog), array_keys($tools)) === [], 'tools: missing catalog tools: '.implode(', ', array_diff(array_keys($catalog), array_keys($tools))));
        $php = $this->phpBuilds($phpSets, $tools, $ubuntu->release);
        $this->require(($tools[$phpSets['rollback']] ?? null)?->source === 'ubuntu', 'php.rollback: expected an Ubuntu tool that repacks installed packages, such as dpkg-repack.');
        $common = $this->references($root->common, 'common', $tools, $php, $phpSets['rollback']);
        $this->gitBuild($tools, $common, $ubuntu->release, $gitPackages);
        $roles = $this->object($root->roles, 'roles');
        $roleNames = array_keys(get_object_vars($roles));
        $required = ['gateway', 'app-dev', 'app-prod', 'operator'];
        $allowed = [...$required, 'vpn', 'router', 'ingress', 'metrics', 'database', 'websocket', 'analytics', NodeRecipeProgram::CiRunnerProfile];
        $this->require(array_diff($required, $roleNames) === [] && array_diff($roleNames, $allowed) === [], 'roles: expected the four required profiles and only known Node roles.');
        $this->require(array_diff($phpSets['pcov'], [...$roleNames, 'base']) === [], 'php.pcov: expected recipe profiles.');
        $this->require(array_diff($phpSets['opentelemetry'], ['app-dev']) === [], 'php.opentelemetry: only the app-dev profile may load OpenTelemetry.');
        // Purging PHP packages outside the recipe is for machines that run only CI jobs, never for a site's Node.
        $this->require(array_diff($phpSets['purge_extras'], [NodeRecipeProgram::CiRunnerProfile]) === [], 'php.purge_extras: only the ci-runner profile may purge PHP packages.');
        $profiles = [];
        $used = $common;
        $usedPhp = [];
        foreach ([...get_object_vars($roles), 'base' => $root->base] as $role => $raw) {
            $extra = $this->references($raw, "profiles.{$role}", $tools, $php, $phpSets['rollback']);
            $this->require(array_intersect($common, $extra) === [], "profiles.{$role}: repeats a common tool.");
            foreach ($this->strings($raw, "profiles.{$role}") as $entry) {
                if (str_starts_with($entry, 'php:')) {
                    $usedPhp[] = substr($entry, 4);
                }
            }
            $used = [...$used, ...$extra];
            $effective = array_values(array_unique([...$common, ...$extra]));
            if (! in_array($role, $phpSets['opentelemetry'], true)) {
                $effective = array_values(array_filter($effective, static fn (string $name): bool => preg_match('/^php\d+\.\d+-opentelemetry$/D', $name) !== 1));
            }
            sort($effective, SORT_STRING);
            $profiles[$role] = $effective;
        }
        foreach (array_keys($php) as $version) {
            $this->require(in_array((string) $version, $usedPhp, true), "php.versions.{$version}: no profile names php:{$version}.");
        }
        $this->require(array_diff(array_keys($tools), $used) === [], 'tools: unused tool entries.');
        // Locations of internal bytes are deliberately not software identity.
        $identityData = $root;
        foreach ($identityData->tools as $pin) {
            if ($pin->source === 'internal') {
                foreach ($pin->artifacts as $artifact) {
                    unset($artifact->filename);
                }
            }
        }
        $canonical = json_encode($this->canonical($identityData), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return new MachineRecipe('sha256:'.hash('sha256', $canonical), $snapshot, $architectures, $tools, $profiles, $canonical, $json, $php, $phpSets['ci'],
            new PhpPolicy($phpSets['rollback'], $phpSets['pcov'], $phpSets['purge_extras'], $phpSets['opentelemetry']), $mirror, $gitPackages);
    }

    /**
     * Structure of the `php` object, before the pins exist. See ADR 0216.
     *
     * @param  array<string, string>  $catalog
     * @return array{ci: string, shared: list<string>, rollback: string, pcov: list<string>, opentelemetry: list<string>, purge_extras: list<string>, versions: array<string, array{packages: list<string>, extensions: list<string>}>}
     */
    private function phpSets(mixed $value, array $catalog): array
    {
        $raw = $this->object($value, 'php', ['ci', 'shared', 'rollback', 'pcov', 'opentelemetry', 'purge_extras', 'versions']);
        $rollback = $this->text($raw->rollback, 'php.rollback');
        $this->require(($catalog[$rollback] ?? null) === 'ubuntu', 'php.rollback: expected an Ubuntu tool that repacks installed packages, such as dpkg-repack.');
        $pcov = $this->strings($raw->pcov, 'php.pcov');
        $opentelemetry = $this->strings($raw->opentelemetry, 'php.opentelemetry');
        $purge = $this->strings($raw->purge_extras, 'php.purge_extras');
        $shared = $this->strings($raw->shared, 'php.shared');
        $this->require($shared !== [], 'php.shared: expected the shared PHP pins.');
        foreach ($shared as $name) {
            $this->require(($catalog[$name] ?? null) === 'artifact' && preg_match('/^php\d+\.\d+-/', $name) !== 1, "php.shared: [{$name}] is not a shared artifact pin.");
        }
        $versions = [];
        foreach (get_object_vars($this->object($raw->versions, 'php.versions')) as $version => $rawSet) {
            $field = "php.versions.{$version}";
            $this->require(preg_match('/^[0-9]+\.[0-9]+$/D', (string) $version) === 1, "{$field}: expected a PHP version such as 8.5.");
            $set = $this->object($rawSet, $field, ['packages', 'extensions']);
            $packages = $this->strings($set->packages, "{$field}.packages");
            foreach ($packages as $name) {
                $this->require(preg_match('/^php'.preg_quote((string) $version, '/').'-[a-z0-9]+$/D', $name) === 1, "{$field}.packages: [{$name}] is not a php{$version}- package.");
            }
            foreach (['cli', 'common', 'fpm'] as $required) {
                $this->require(in_array("php{$version}-{$required}", $packages, true), "{$field}.packages: missing php{$version}-{$required}.");
            }
            $extensions = $this->strings($set->extensions, "{$field}.extensions");
            $sorted = $extensions;
            sort($sorted, SORT_STRING);
            $this->require($sorted === $extensions, "{$field}.extensions: expected a sorted list.");
            foreach ($extensions as $extension) {
                $this->require(preg_match('/^[a-z0-9_]+(?: [a-z0-9_]+)?$/D', $extension) === 1, "{$field}.extensions: [{$extension}] is not a lower-case module name.");
            }
            $this->require(in_array('core', $extensions, true) && in_array('pcov', $extensions, true), "{$field}.extensions: expected core and pcov.");
            $this->require(in_array('opentelemetry', $extensions, true) === in_array("php{$version}-opentelemetry", $packages, true), "{$field}: OpenTelemetry package and extension must match.");
            $versions[(string) $version] = ['packages' => $packages, 'extensions' => $extensions];
        }
        $this->require($versions !== [], 'php.versions: expected at least one PHP version.');
        $ci = $this->text($raw->ci, 'php.ci');
        $this->require(isset($versions[$ci]), "php.ci: [{$ci}] is not a declared PHP version.");

        return ['ci' => $ci, 'shared' => $shared, 'rollback' => $rollback, 'pcov' => $pcov, 'opentelemetry' => $opentelemetry, 'purge_extras' => $purge, 'versions' => $versions];
    }

    /**
     * One exact build per version: the PHP core pins share the `php<version>-common` version, and every pin is built
     * for the recipe's Ubuntu release.
     *
     * @param  array{ci: string, shared: list<string>, rollback: string, pcov: list<string>, opentelemetry: list<string>, purge_extras: list<string>, versions: array<string, array{packages: list<string>, extensions: list<string>}>}  $sets
     * @param  array<string, ToolPin>  $tools
     * @return array<string, PhpBuild>
     */
    private function phpBuilds(array $sets, array $tools, string $release): array
    {
        $builds = [];
        foreach ($sets['versions'] as $version => $set) {
            $field = "php.versions.{$version}";
            $core = $tools["php{$version}-common"]->version;
            $upstream = explode('-', preg_replace('/^[0-9]+:/', '', $core) ?? $core, 2)[0];
            $this->require(preg_match('/^'.preg_quote($version, '/').'\.[0-9]+$/D', $upstream) === 1, "{$field}: php{$version}-common [{$core}] is not a PHP {$version} build.");
            foreach ($set['packages'] as $name) {
                $pinned = $tools[$name]->version;
                $this->require(! str_starts_with($pinned, $version.'.') || $pinned === $core, "{$field}: [{$name}] is version [{$pinned}], but the PHP {$version} core pins are [{$core}]. Change every pin of one PHP version together.");
            }
            $packages = [...$set['packages'], ...$sets['shared']];
            sort($packages, SORT_STRING);
            foreach ($packages as $name) {
                $pinned = $tools[$name]->version;
                $this->require(str_contains($pinned, '+ubuntu'.$release), "{$field}: [{$name}] version [{$pinned}] is not built for Ubuntu {$release}.");
            }
            $builds[$version] = new PhpBuild($version, $upstream, $packages, $set['extensions']);
        }

        return $builds;
    }

    /**
     * @param  array<string, ToolPin>  $tools
     * @param  list<string>  $packages
     * @param  list<string>  $common
     */
    private function gitBuild(array $tools, array $common, string $release, array $packages): void
    {
        foreach ($packages as $name) {
            $this->require(isset($tools[$name]) && $tools[$name]->source === 'artifact', "git.packages: missing artifact tool [{$name}].");
        }
        $version = $tools['git']->version;
        $this->require(str_contains($version, '~ubuntu'.$release.'.'), "git: expected a git-core build for Ubuntu {$release}.");
        $filenameVersion = preg_replace('/^[0-9]+:/', '', $version) ?? $version;
        foreach ($packages as $name) {
            $this->require($tools[$name]->version === $version, 'git: all Git packages must pin the same build.');
            $this->require(in_array($name, $common, true), "common: missing {$name}.");
            foreach ($tools[$name]->artifacts as $architecture => $artifact) {
                $debArchitecture = $name === 'git-man' ? 'all' : $architecture;
                $expected = 'https://ppa.launchpadcontent.net/git-core/ppa/ubuntu/pool/main/g/git/'.$name.'_'.$filenameVersion.'_'.$debArchitecture.'.deb';
                $this->require($artifact->algorithm === 'sha256' && $artifact->location === $expected, "tools.{$name}: expected a SHA-256-pinned git-core PPA Debian artifact.");
            }
        }
        $digests = array_map(static fn (ArtifactPin $pin): string => $pin->digest, $tools['git-man']->artifacts);
        $this->require(count(array_unique($digests)) === 1, 'git-man: architecture-independent artifacts must have the same digest.');
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new InvalidArgumentException($message);
        }
    }

    /** @param ?list<string> $keys */
    private function object(mixed $value, string $field, ?array $keys = null): stdClass
    {
        $this->require($value instanceof stdClass, "{$field}: expected an object.");
        if (! $value instanceof stdClass) {
            throw new InvalidArgumentException("{$field}: expected an object.");
        }
        if ($keys !== null) {
            $actual = array_keys(get_object_vars($value));
            $this->require(array_diff($keys, $actual) === [] && array_diff($actual, $keys) === [], "{$field}: expected exactly fields ".implode(', ', $keys).'; got '.implode(', ', $actual).'.');
        }

        return $value;
    }

    private function text(mixed $value, string $field): string
    {
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("{$field}: expected a non-empty string.");
        }

        return $value;
    }

    /** @return list<string> */
    private function strings(mixed $value, string $field): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("{$field}: expected a list.");
        }
        $result = [];
        foreach ($value as $item) {
            $result[] = $this->text($item, $field);
        }
        $this->require(count(array_unique($result)) === count($result), "{$field}: duplicate list entry.");

        return $result;
    }

    /**
     * A `php:<version>` entry expands to that version's PHP set. A PHP pin is never named on its own.
     *
     * @param  array<string, ToolPin>  $tools
     * @param  array<string, PhpBuild>  $php
     * @return list<string>
     */
    private function references(mixed $value, string $field, array $tools, array $php, string $rollback): array
    {
        $phpPins = [];
        foreach ($php as $build) {
            $phpPins = [...$phpPins, ...$build->packages];
        }
        $names = [];
        foreach ($this->strings($value, $field) as $name) {
            if (str_starts_with($name, 'php:')) {
                $build = $php[substr($name, 4)] ?? null;
                $this->require($build instanceof PhpBuild, "{$field}: unknown PHP set [{$name}].");
                if ($build instanceof PhpBuild) {
                    // Every machine with a PHP set can repack its installed build for a rollback.
                    $names = [...$names, ...$build->packages, $rollback];
                }

                continue;
            }
            $this->require(isset($tools[$name]), "{$field}: unknown or missing tool [{$name}].");
            $this->require(! in_array($name, $phpPins, true) && preg_match('/^php\d+\.\d+-/', $name) !== 1, "{$field}: names the PHP pin [{$name}]; name its set, php:<version>, instead.");
            $names[] = $name;
        }

        return array_values(array_unique($names));
    }

    private function canonical(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);
            $result = new stdClass;
            foreach ($properties as $key => $property) {
                $result->{$key} = $this->canonical($property);
            }

            return $result;
        }
        if (is_array($value)) {
            return array_map($this->canonical(...), $value);
        }
        $this->require(! is_float($value), 'Floating-point numbers are not allowed.');

        return $value;
    }

    /** json_decode accepts duplicate keys, so scan the already syntax-checked tokens before using its result. */
    private function rejectDuplicateKeys(string $json): void
    {
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\\[\\]:,]/s', $json, $matches);
        $stack = [];
        $tokens = $matches[0];
        foreach ($tokens as $index => $token) {
            if ($token === '{' || $token === '[') {
                $stack[] = [];
            } elseif ($token === '}' || $token === ']') {
                array_pop($stack);
            } elseif (str_starts_with($token, '"') && ($tokens[$index + 1] ?? '') === ':') {
                $key = $this->text(json_decode($token, true, flags: JSON_THROW_ON_ERROR), 'object key');
                $last = count($stack) - 1;
                $this->require(! isset($stack[$last][$key]), "Duplicate object key [{$key}].");
                $stack[$last][$key] = true;
            }
        }
    }
}
