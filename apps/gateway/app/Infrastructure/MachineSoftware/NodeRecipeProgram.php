<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\Fleet\NodeShell;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;
use RuntimeException;

/** Fixed recipe operations over the same pinned SSH transport as fleet convergence. */
final readonly class NodeRecipeProgram
{
    /** The recipe profile of a roleless Node that runs CI, such as Sabre. It replaces `operator`. */
    public const string CiRunnerProfile = 'ci-runner';

    public function __construct(private NodeShell $shell, private PhpBuildInstaller $php) {}

    /**
     * The Node record names the profile of a roleless Node: `settings.machine_recipe.profile`, or the one requested now.
     */
    public function profile(Node $node, ?string $requested = null): ?string
    {
        $node->load('roles');
        if ($node->roles->isNotEmpty() && $requested === null) {
            return null;
        }
        $recorded = self::recordedProfile($node);
        $profile = $requested ?? $recorded;
        if ($profile === null) {
            return null;
        }
        if ($profile !== self::CiRunnerProfile) {
            throw new ResourceOperationException('node.recipe_profile_invalid', 'The only recipe profile a Node can select is ci-runner.', 422);
        }
        if ($node->roles->isNotEmpty() || $node->platform !== 'linux') {
            throw new ResourceOperationException('node.recipe_profile_invalid', 'Only a Node without roles can use the ci-runner profile; its roles select its profiles.', 422);
        }

        return $profile;
    }

    /** The profile that an earlier convergence recorded in `settings.machine_recipe.profile`. */
    public static function recordedProfile(Node $node): ?string
    {
        $recipe = $node->settings['machine_recipe'] ?? null;
        $profile = is_array($recipe) ? $recipe['profile'] ?? null : null;

        return is_string($profile) ? $profile : null;
    }

    /** @return array<string, ToolPin> */
    public function tools(Node $node, MachineRecipe $recipe, ?string $profile = null): array
    {
        $architecture = match ($node->architecture) {
            'x86_64', 'amd64' => 'amd64',
            'aarch64', 'arm64' => 'arm64',
            default => throw new ResourceOperationException('node.recipe_architecture', 'The Node architecture is not supported by the recipe.', 422),
        };
        $node->load('roles');
        $names = $node->roles->isEmpty() ? $recipe->profile($profile ?? 'operator', $architecture) : [];
        foreach ($node->roles as $role) {
            if ($role->status !== LifecycleStatus::Active) {
                throw new ResourceOperationException('node.recipe_role_inactive', 'Every assigned role must be active before recipe convergence.', 409);
            }
            $names = [...$names, ...$recipe->profile($role->role->value, $architecture)];
        }
        $tools = [];
        foreach (array_unique($names) as $name) {
            $pin = $recipe->tools[$name] ?? throw new ResourceOperationException('node.recipe_pin_missing', "The recipe has no pin for role prerequisite [{$name}]. Nothing was changed.", 422);
            if ($pin->source !== 'ubuntu' && ! isset($pin->artifacts[$architecture])) {
                throw new ResourceOperationException('node.recipe_artifact_missing', "The recipe has no {$architecture} artifact for [{$name}].", 422);
            }
            $tools[$name] = $pin;
        }
        ksort($tools);

        return $tools;
    }

    /**
     * @param  array<string, ToolPin>  $tools
     * @return array{tools: array<string, array{source: string, version: string, installed: ?string, changed: bool, status: string, refusal?: string}>, actions: list<string>}
     */
    public function inspect(Node $node, MachineRecipe $recipe, array $tools): array
    {
        $report = $this->run($node, $recipe, $tools, 'inspect');
        $assessment = [];
        foreach ($tools as $name => $pin) {
            $value = $report[$name] ?? null;
            if (! is_array($value) || ($value['source'] ?? null) !== $pin->source || ($value['version'] ?? null) !== $pin->version
                || ! array_key_exists('installed', $value) || ($value['installed'] !== null && ! is_string($value['installed']))
                || ! is_bool($value['changed'] ?? null) || ! is_string($value['status'] ?? null)) {
                throw new RuntimeException('Recipe inspection returned an invalid assessment.');
            }
            $assessment[$name] = ['source' => $pin->source, 'version' => $pin->version, 'installed' => $value['installed'], 'changed' => $value['changed'], 'status' => $value['status']];
            if ($value['status'] === 'unsupported_newer') {
                $refusal = $value['refusal'] ?? 'origin:'.$name;
                $allowed = array_map(static fn (string $reason): string => 'origin:'.$name.$reason, [
                    '', ':dpkg-log-missing', ':snapshot-unavailable', ':snapshot-unproved', ':snapshot-lookup-limit',
                ]);
                if (! in_array($refusal, $allowed, true)) {
                    throw new RuntimeException('Recipe inspection returned an invalid refusal.');
                }
                $assessment[$name]['refusal'] = $refusal;
            }
        }

        $actions = $report['_actions'] ?? [];
        if (! is_array($actions) || ! array_is_list($actions) || array_diff($actions, ['repair_ubuntu_sources', 'retire_git_source', 'pin_git']) !== []) {
            throw new RuntimeException('Recipe inspection returned invalid planned actions.');
        }

        return ['tools' => $assessment, 'actions' => $actions];
    }

    /**
     * Checks the PHP build before any change: another PHP apt source, or PHP packages outside the recipe that this
     * profile does not purge, refuse the convergence before a download. Dry runs call it too.
     *
     * @param  array<string, ToolPin>  $tools
     * @return array<string, mixed> The installer's plan, or [] without a PHP set.
     */
    public function phpPreflight(Node $node, MachineRecipe $recipe, array $tools, ?string $profile = null): array
    {
        $versions = self::phpVersions($recipe, $tools);
        if ($versions === []) {
            return [];
        }
        $result = $this->php->run($node, $recipe, $versions, 'plan', $this->pcov($node, $recipe, $profile), $this->extras($recipe, $profile), 'all', $this->opentelemetry($node, $recipe, $profile));
        if ($result->outcome !== 'planned') {
            throw new ResourceOperationException('node.recipe_php_refused', PhpBuildInstaller::describe($result).' Nothing was changed.', 409);
        }

        return $result->data;
    }

    /**
     * @param  array<string, ToolPin>  $tools
     * @return array<string, mixed>
     */
    public function apply(Node $node, MachineRecipe $recipe, array $tools, ?string $profile = null): array
    {
        $this->phpPreflight($node, $recipe, $tools, $profile);
        $versions = self::phpVersions($recipe, $tools);
        // The PHP build has one installer, php.install-build (ADR 0216); the recipe program installs the rest.
        $others = array_diff_key($tools, array_flip(self::phpPackages($recipe, $versions)));
        $report = $this->run($node, $recipe, $others, 'apply');
        if (($report['healthy'] ?? false) !== true) {
            throw new ResourceOperationException('node.recipe_health_failed', 'Recipe convergence did not return a successful health check. No automatic rollback was attempted.', 502);
        }
        if ($versions === []) {
            return $report;
        }
        $result = $this->php->run($node, $recipe, $versions, 'apply', $this->pcov($node, $recipe, $profile), $this->extras($recipe, $profile), 'all', $this->opentelemetry($node, $recipe, $profile));
        if (! in_array($result->outcome, PhpBuildInstaller::Succeeded, true)) {
            // Only the installer's own check of the old versions proves a restore (ADR 0216).
            $restored = match (true) {
                ($result->data['restored'] ?? false) === true => 'The installer restored the previous PHP build and checked it.',
                $result->outcome === 'rollback_failed' => 'The rollback of the previous PHP build failed too. The Node may run a mixed PHP build; inspect its PHP packages and PHP-FPM now.',
                default => 'The PHP packages were not changed.',
            };
            throw new ResourceOperationException('node.recipe_php_failed', 'Recipe convergence stopped at the PHP build: '.PhpBuildInstaller::describe($result).' '.$restored, 502);
        }
        $names = static fn (mixed $list): array => is_array($list) ? array_values(array_filter($list, is_string(...))) : [];
        $report['changed_packages'] = array_values(array_unique([...$names($report['changed_packages'] ?? null), ...$names($result->data['changed'] ?? null)]));
        $report['php'] = $result->data;

        return $report;
    }

    /**
     * The PHP versions whose whole set the tools hold.
     *
     * @param  array<string, ToolPin>  $tools
     * @return list<string>
     */
    public static function phpVersions(MachineRecipe $recipe, array $tools): array
    {
        return array_map(strval(...), array_keys($recipe->phpExtensions(array_keys($tools))));
    }

    /**
     * @param  list<string>  $versions
     * @return list<string>
     */
    private static function phpPackages(MachineRecipe $recipe, array $versions): array
    {
        $names = [];
        foreach ($versions as $version) {
            $names = [...$names, ...$recipe->php($version)->packages];
        }

        return array_values(array_unique($names));
    }

    /** PCOV loads only for the recipe's PCOV profiles, such as app-dev and ci-runner. */
    private function pcov(Node $node, MachineRecipe $recipe, ?string $profile): bool
    {
        $profiles = $node->roles->map(fn ($role): string => $role->role->value)->all();
        if ($profile !== null) {
            $profiles[] = $profile;
        }

        return array_intersect($profiles, $recipe->phpPolicy->pcovProfiles) !== [];
    }

    private function opentelemetry(Node $node, MachineRecipe $recipe, ?string $profile): bool
    {
        $profiles = $node->roles->map(fn ($role): string => $role->role->value)->all();
        if ($profile !== null) {
            $profiles[] = $profile;
        }

        return array_intersect($profiles, $recipe->phpPolicy->opentelemetryProfiles) !== [];
    }

    /**
     * Only the profiles that the recipe lets purge, the ci-runner profile, remove PHP packages outside the recipe.
     *
     * @return 'purge'|'refuse'
     */
    private function extras(MachineRecipe $recipe, ?string $profile): string
    {
        return $profile !== null && in_array($profile, $recipe->phpPolicy->purgeProfiles, true) ? 'purge' : 'refuse';
    }

    /**
     * @param  array<string, ToolPin>  $tools
     * @return array<string, mixed>
     */
    private function run(Node $node, MachineRecipe $recipe, array $tools, string $mode): array
    {
        $program = file_get_contents(resource_path('remote/lib/recipe.py'));
        if ($program === false) {
            throw new RuntimeException('The recipe converge program is missing.');
        }
        $program = file_get_contents(resource_path('remote/lib/artifacts.py'))."\n".$program."\nif __name__ == '__main__':\n    sys.exit(main())\n";
        $data = json_decode($recipe->json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data) || ! is_array($data['tools'] ?? null)) {
            throw new RuntimeException('The recipe document is invalid.');
        }
        $payload = json_encode([
            'snapshot' => $recipe->snapshot,
            'git_packages' => $recipe->gitPackages, 'artifact_mirror' => $recipe->artifactMirror,
            'architecture' => in_array($node->architecture, ['x86_64', 'amd64'], true) ? 'amd64' : 'arm64',
            'tools' => array_intersect_key($data['tools'], $tools),
        ], JSON_THROW_ON_ERROR);
        $result = $this->shell->run($node, new RemoteCommand(
            $mode === 'inspect' ? ['python3', '-', $mode, $payload] : ['sudo', 'timeout', '--signal=TERM', '--kill-after=10', '840', 'python3', '-', $mode, $payload],
            input: $program,
            maxOutputBytes: 65536,
            timeout: 900,
        ));
        if (! $result->succeeded() || $result->truncated) {
            // Only the fixed packages-stage envelope can expose output, after redaction and bounding.
            $diagnostic = json_decode($result->stdout, true);
            $failure = is_array($diagnostic) ? $diagnostic['failure'] ?? null : null;
            if (is_array($failure) && ($failure['stage'] ?? null) === 'recipe-stage:packages') {
                $detail = new CommandActivityInputSanitizer()->redactText(json_encode($failure, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
                throw new ResourceOperationException('node.recipe_failed', 'Recipe convergence stopped at recipe-stage:packages. '.mb_substr($detail, 0, 30000).' The Node may be partly changed. No automatic rollback was attempted; inspect the Node before retrying.', 502);
            }
            $stage = trim($result->stdout);
            if ($stage === 'recipe-stage:docker-provider:docker-ce') {
                throw new ResourceOperationException('node.recipe_docker_provider', 'docker-provider:docker-ce: Docker CE requires an explicit planned-window migration. Run php artisan node:migrate-docker '.$node->id.' --dry-run on the Gateway. Nothing was changed by recipe convergence.', 409);
            }
            if (preg_match('/^recipe-stage:[a-z-]+(?::[A-Za-z0-9@_.:-]+)?$/D', $stage) !== 1) {
                $stage = 'recipe-stage:remote';
            }
            if (preg_match('/^recipe-stage:platform:([A-Za-z0-9._-]+):([A-Za-z0-9._-]+)$/D', $stage, $platform) === 1) {
                throw new ResourceOperationException('node.recipe_platform_unsupported', "Node operating system [{$platform[1]}/{$platform[2]}] is not supported.", 422);
            }
            throw new ResourceOperationException('node.recipe_failed', "Recipe convergence stopped at {$stage}. The Node may be partly changed. No automatic rollback was attempted; inspect the Node before retrying.", 502);
        }
        $report = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($report)) {
            throw new RuntimeException('Recipe convergence returned an invalid report.');
        }

        $fields = [];
        foreach ($report as $key => $value) {
            if (! is_string($key)) {
                throw new RuntimeException('Recipe convergence returned an invalid report key.');
            }
            $fields[$key] = $value;
        }

        return $fields;
    }
}
