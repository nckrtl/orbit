<?php

declare(strict_types=1);

use App\Actions\Fleet\ConvergeNodeRecipeAction;
use App\Domain\Fleet\FleetNodeVerification;
use App\Domain\Nodes\RoleName;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolInspectionData;
use App\Domain\Tools\ToolInspector;
use App\Infrastructure\Fleet\NodeShell;
use App\Infrastructure\MachineSoftware\NodeRecipeProgram;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Nodes\NodeBootstrapPackageCatalog;
use App\Infrastructure\Nodes\NodeUpdateLock;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\Support\FakePhpBuildRunner;
use Tests\Support\Fleet\FleetFixtures;
use Tests\Support\Fleet\FleetTestSsh;
use Tests\Support\Fleet\ScriptedSshExecutor;
use Tests\Support\InspectsToolsIndividually;

final class RecipeVerification implements FleetNodeVerification
{
    public int $calls = 0;

    public function __construct(public bool $passed = true) {}

    public function baseline(Node $node): array
    {
        throw new LogicException('Recipe convergence does not tolerate preexisting Doctor issues.');
    }

    public function verify(Node $node, array $baseline, string $pinnedAgentVersion, array $tolerated = []): array
    {
        $this->calls++;

        return ['passed' => $this->passed, 'new_issues' => [], 'preexisting_issues' => [], 'agent_version' => $pinnedAgentVersion, 'presence' => 'matched'];
    }
}

function recipeSsh(?CommandResult $application = null, ?Closure $inspection = null): ScriptedSshExecutor
{
    $ssh = new ScriptedSshExecutor;
    $ssh->on('/^python3 - inspect /', $inspection ?? static function (RemoteCommand $command): CommandResult {
        $payload = json_decode($command->arguments[3], true, flags: JSON_THROW_ON_ERROR);
        $versions = [];
        foreach ($payload['tools'] as $name => $pin) {
            $versions[$name] = ['source' => $pin['source'], 'version' => $pin['version'], 'installed' => $name === 'curl' ? '1.0.0' : $pin['version'], 'changed' => $name === 'curl', 'status' => $name === 'curl' ? 'older_than_recipe' : 'current'];
        }

        return ScriptedSshExecutor::ok(json_encode($versions, JSON_THROW_ON_ERROR));
    });
    $ssh->on('/^sudo timeout .* python3 - apply /', $application ?? ScriptedSshExecutor::ok('{"changed_packages":["curl"],"restarted_services":[],"changed_tools":["curl"],"healthy":true}'));
    app()->instance(NodeShell::class, FleetTestSsh::shell($ssh));
    app()->instance(RemoteProgramRunner::class, new FakePhpBuildRunner);
    app()->instance(NodeUpdateLock::class, new NodeUpdateLock(FleetTestSsh::shell($ssh)));

    return $ssh;
}

it('plans only the production role pins and makes no remote or registry writes in dry-run', function (): void {
    $node = FleetFixtures::node('recipe-prod', [RoleName::AppProd]);
    $node->update(['settings' => ['machine_recipe' => ['version' => 'previous']]]);
    $ssh = recipeSsh();
    $recipe = new RecipeLoader()->load();

    $result = app(ConvergeNodeRecipeAction::class)->execute($node, $recipe, dryRun: true);

    expect($result['applied'])->toBeFalse()
        ->and($result['plan']->recipe)->toBe($recipe->identity)
        ->and($result['plan']->snapshot)->toBe($recipe->snapshot)
        ->and($result['plan']->tools)->toHaveKeys(['curl', 'caddy', 'node', 'pnpm', 'vp-cli'])
        ->not->toHaveKeys(['rust', 'pi', 'php8.4-pcov'])
        ->and($result['plan']->tools['curl'])->toBe(['source' => 'ubuntu', 'version' => $recipe->tools['curl']->version, 'installed' => '1.0.0', 'changed' => true, 'status' => 'older_than_recipe'])
        ->and($result['plan']->tools['caddy']['changed'])->toBeFalse()
        ->and($ssh->commands)->toHaveCount(1)
        ->and($ssh->commands[0]->arguments[0])->toBe('python3')
        ->and($node->refresh()->settings)->toBe(['machine_recipe' => ['version' => 'previous']]);
});

it('does not add operator-only tools to a Node with a production role', function (): void {
    $node = FleetFixtures::node('recipe-operator-filter', [RoleName::AppProd]);
    $document = json_decode(file_get_contents(resource_path('machine-software/recipe.json')), true, flags: JSON_THROW_ON_ERROR);
    $document['roles']['operator'][] = 'rust';
    $recipe = new RecipeLoader()->decode(json_encode($document, JSON_THROW_ON_ERROR));

    $pins = app(NodeRecipeProgram::class)->tools($node, $recipe);

    expect(array_keys($pins))->not->toContain('rust');
});

it('unions multiple role tool sets without including base-only tools', function (): void {
    $node = FleetFixtures::node('recipe-multi', [RoleName::Gateway, RoleName::AppDev]);
    $recipe = new RecipeLoader()->load();

    $pins = app(NodeRecipeProgram::class)->tools($node, $recipe);

    expect(array_keys($pins))->toContain('caddy', 'curl', 'php8.4-pcov', 'php8.5-fpm')
        ->not->toContain('rust', 'orbit-agent', 'pi')
        ->and(array_keys($pins))->toHaveCount(count($recipe->profile('app-dev')));
});

it('refuses a missing service role profile before SSH', function (): void {
    $node = FleetFixtures::node('recipe-vpn', [RoleName::Vpn]);
    $ssh = recipeSsh();
    $document = json_decode(file_get_contents(resource_path('machine-software/recipe.json')), true, flags: JSON_THROW_ON_ERROR);
    unset($document['roles']['vpn']);
    $document['base'][] = 'dnsmasq';
    $incomplete = new RecipeLoader()->decode(json_encode($document, JSON_THROW_ON_ERROR));

    expect(fn () => app(ConvergeNodeRecipeAction::class)->execute($node, $incomplete))
        ->toThrow(InvalidArgumentException::class, 'Unknown role/profile [vpn]');
    expect($ssh->commands)->toBe([]);
});

it('selects each service role prerequisite without development-only software', function (RoleName $role): void {
    $node = FleetFixtures::node('recipe-service');
    $clusterId = null;
    if (in_array($role, [RoleName::Router, RoleName::Ingress], true)) {
        $clusterId = Cluster::query()->create(['name' => 'recipe-cluster'])->id;
        $node->update(['cluster_id' => $clusterId]);
    }
    NodeRole::query()->create(['node_id' => $node->id, 'cluster_id' => $clusterId, 'role' => $role, 'status' => 'active']);
    $recipe = new RecipeLoader()->load();

    $pins = app(NodeRecipeProgram::class)->tools($node, $recipe);

    $php = $role === RoleName::WebSocket ? [...$recipe->php('8.5')->packagesFor(false), 'dpkg-repack'] : [];
    $expected = array_unique([...$recipe->profile('operator'), ...new NodeBootstrapPackageCatalog()->forRole($node, $role), ...$php]);
    sort($expected);
    expect(array_keys($pins))->toBe($expected)->not->toContain('rust', 'node', 'pi');
})->with([RoleName::Vpn, RoleName::Router, RoleName::Ingress, RoleName::Metrics, RoleName::Database, RoleName::WebSocket, RoleName::Analytics]);

it('gives every role that runs PHP the whole recipe PHP 8.5 set', function (RoleName $role): void {
    $node = FleetFixtures::node('recipe-php-'.$role->value, [$role]);
    $recipe = new RecipeLoader()->load();

    $pins = array_keys(app(NodeRecipeProgram::class)->tools($node, $recipe));

    expect($pins)->toContain(...$recipe->php('8.5')->packagesFor(false))
        ->not->toContain('php8.4-cli')
        ->and($recipe->phpExtensions($pins))->toBe(['8.5' => $recipe->php('8.5')->cliExtensions()]);
})->with([RoleName::AppProd, RoleName::WebSocket, RoleName::Gateway]);

it('installs the PHP build with the one installer after the other tools, strictly and without PCOV on the Gateway', function (): void {
    $node = FleetFixtures::node('recipe-php-payload', [RoleName::Gateway]);
    $ssh = recipeSsh();
    $php = app(RemoteProgramRunner::class);
    app()->instance(FleetNodeVerification::class, new RecipeVerification);
    $recipe = new RecipeLoader()->load();

    $result = app(ConvergeNodeRecipeAction::class)->execute($node, $recipe);

    $apply = collect($ssh->commands)->first(fn (RemoteCommand $command): bool => in_array('apply', $command->arguments, true));
    $payload = json_decode((string) $apply->arguments[array_key_last($apply->arguments)], true, flags: JSON_THROW_ON_ERROR);
    expect(array_keys($payload['tools']))->not->toContain('php8.5-fpm', 'php8.5-pcov', 'php-common')->toContain('caddy', 'dpkg-repack')
        ->and(array_column($php->inputs, 'mode'))->toBe(['plan', 'plan', 'apply'])
        ->and($php->inputs[2])->toMatchArray(['pcov' => false, 'extras' => 'refuse', 'fpm' => 'all'])
        ->and(array_keys($php->inputs[2]['versions']))->toBe(['8.5'])
        ->and($result['changes']['php'])->toMatchArray(['changed' => [], 'stale_masters' => []]);
});

it('plans both pinned OpenTelemetry packages for an app-dev Node like Beast', function (): void {
    $node = FleetFixtures::node('recipe-beast', [RoleName::AppDev]);
    $ssh = recipeSsh();
    $php = app(RemoteProgramRunner::class);

    $result = app(ConvergeNodeRecipeAction::class)->execute($node, new RecipeLoader()->load(), dryRun: true);

    expect($result['applied'])->toBeFalse()
        ->and($result['plan']->tools)->toHaveKeys(['php8.4-opentelemetry', 'php8.5-opentelemetry'])
        ->and($php->inputs)->toHaveCount(1)
        ->and($php->inputs[0])->toMatchArray(['mode' => 'plan', 'opentelemetry' => true, 'extras' => 'refuse']);
    foreach (['8.4', '8.5'] as $version) {
        expect(array_column($php->inputs[0]['versions'][$version]['packages'], 'name'))->toContain("php{$version}-opentelemetry");
        expect($php->inputs[0]['versions'][$version]['extensions'])->not->toContain('opentelemetry');
    }
    expect($ssh->commands)->toHaveCount(1);
});

it('refuses extra PHP packages or another PHP source before any change, also in a dry run', function (bool $dryRun): void {
    $node = FleetFixtures::node('recipe-php-extras', [RoleName::AppProd]);
    $ssh = recipeSsh();
    app()->instance(RemoteProgramRunner::class, new FakePhpBuildRunner(['plan' => ['extra_packages', ['packages' => ['php8.5-opentelemetry']]]]));

    expect(fn () => app(ConvergeNodeRecipeAction::class)->execute($node, new RecipeLoader()->load(), dryRun: $dryRun))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('node.recipe_php_refused')
            ->and($exception->getMessage())->toContain('php8.5-opentelemetry', 'Nothing was changed'));
    expect(collect($ssh->commands)->contains(fn (RemoteCommand $command): bool => in_array('apply', $command->arguments, true)))->toBeFalse();
})->with(['apply' => [false], 'dry run' => [true]]);

it('stops with the installer outcome when the PHP build rolls back and records no convergence', function (string $outcome, array $data, string $message): void {
    $node = FleetFixtures::node('recipe-php-rollback-'.$outcome, [RoleName::AppProd]);
    recipeSsh();
    app()->instance(RemoteProgramRunner::class, new FakePhpBuildRunner(['apply' => [$outcome, $data]]));
    $verification = new RecipeVerification;
    app()->instance(FleetNodeVerification::class, $verification);

    expect(fn () => app(ConvergeNodeRecipeAction::class)->execute($node, new RecipeLoader()->load()))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('node.recipe_php_failed')
            ->and($exception->getMessage())->toContain('orbit-shop-php8.5-fpm.service', $message));
    expect($verification->calls)->toBe(0)
        ->and($node->refresh()->settings)->toBe([]);
})->with([
    'a checked restore' => ['rolled_back', ['unit' => 'orbit-shop-php8.5-fpm.service', 'reason' => 'the service did not start', 'restored' => true], 'restored the previous PHP build and checked it'],
    'a failed restore' => ['rollback_failed', ['unit' => 'orbit-shop-php8.5-fpm.service', 'reason' => 'the service did not start; the rollback failed too'], 'The rollback of the previous PHP build failed too'],
]);

it('loads PCOV and purges extra PHP packages only for the ci-runner profile', function (): void {
    $sabre = FleetFixtures::node('recipe-php-sabre');
    recipeSsh();
    $php = app(RemoteProgramRunner::class);
    app()->instance(FleetNodeVerification::class, new RecipeVerification);

    app(ConvergeNodeRecipeAction::class)->execute($sabre, new RecipeLoader()->load(), profile: 'ci-runner');
    $dev = FleetFixtures::node('recipe-php-dev', [RoleName::AppDev]);
    app(ConvergeNodeRecipeAction::class)->execute($dev, new RecipeLoader()->load());

    $applies = array_values(array_filter($php->inputs, static fn (array $input): bool => $input['mode'] === 'apply'));
    expect($applies[0])->toMatchArray(['pcov' => true, 'opentelemetry' => false, 'extras' => 'purge'])
        ->and($applies[1])->toMatchArray(['pcov' => true, 'opentelemetry' => true, 'extras' => 'refuse']);
});

it('converges a roleless CI runner with the ci-runner profile and records the profile', function (): void {
    $this->freezeTime();
    $node = FleetFixtures::node('recipe-sabre');
    recipeSsh();
    app()->instance(FleetNodeVerification::class, new RecipeVerification);
    $recipe = new RecipeLoader()->load();

    $result = app(ConvergeNodeRecipeAction::class)->execute($node, $recipe, profile: 'ci-runner');
    $later = app(ConvergeNodeRecipeAction::class)->execute($node->refresh(), $recipe, dryRun: true);

    expect(array_keys($result['plan']->tools))->toContain(...$recipe->php('8.5')->packagesFor(false))
        ->and($node->settings['machine_recipe'])->toBe(['version' => $recipe->identity, 'snapshot' => $recipe->snapshot, 'converged_at' => now()->toIso8601String(), 'profile' => 'ci-runner'])
        ->and(array_keys($later['plan']->tools))->toBe(array_keys($result['plan']->tools))
        ->and(array_keys(app(NodeRecipeProgram::class)->tools(FleetFixtures::node('recipe-operator'), $recipe)))->not->toContain('php8.5-cli');
});

it('keeps the ci-runner profile but no success marker when a run fails', function (array $settings, ?string $requested): void {
    $node = FleetFixtures::node('recipe-sabre-fail');
    $node->update(['settings' => $settings]);
    recipeSsh(ScriptedSshExecutor::fail(stdout: 'recipe-stage:packages'));
    app()->instance(FleetNodeVerification::class, new RecipeVerification);

    expect(fn () => app(ConvergeNodeRecipeAction::class)->execute($node, new RecipeLoader()->load(), profile: $requested))
        ->toThrow(ResourceOperationException::class, 'stopped at recipe-stage:packages');
    expect($node->refresh()->settings)->toBe(['machine_recipe' => ['profile' => 'ci-runner']])
        ->and(NodeRecipeProgram::recordedProfile($node))->toBe('ci-runner');
})->with([
    'a recorded profile' => [['machine_recipe' => ['version' => 'previous', 'profile' => 'ci-runner']], null],
    'a first run that names the profile' => [[], 'ci-runner'],
]);

it('refuses the ci-runner profile on a Node with roles and any other profile name before SSH', function (array $roles, string $profile): void {
    $node = FleetFixtures::node('recipe-profile-'.count($roles).$profile, $roles);
    $ssh = recipeSsh();

    expect(fn () => app(ConvergeNodeRecipeAction::class)->execute($node, new RecipeLoader()->load(), profile: $profile))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('node.recipe_profile_invalid'));
    expect($ssh->commands)->toBe([]);
})->with([
    'a Node with a role' => [[RoleName::AppDev], 'ci-runner'],
    'an unknown profile' => [[], 'base'],
]);

it('records the complete identity only after inventory health and Doctor pass and preserves other settings', function (): void {
    $this->freezeTime();
    $node = FleetFixtures::node('recipe-pass');
    $node->update(['settings' => ['t3' => ['keep' => true], 'machine_recipe' => ['version' => 'previous']]]);
    $ssh = recipeSsh();
    $verification = new RecipeVerification;
    app()->instance(FleetNodeVerification::class, $verification);
    $recipe = new RecipeLoader()->load();

    $result = app(ConvergeNodeRecipeAction::class)->execute($node, $recipe);

    expect($result['applied'])->toBeTrue()
        ->and($result['changes']['changed_packages'])->toBe(['curl'])
        ->and($verification->calls)->toBe(1)
        ->and($node->refresh()->settings)->toBe([
            't3' => ['keep' => true],
            'machine_recipe' => ['version' => $recipe->identity, 'snapshot' => $recipe->snapshot, 'converged_at' => now()->toIso8601String()],
        ]);
    expect($ssh->lines()[1])->toStartWith('sudo bash -seu')
        ->and($ssh->lines()[2])->toStartWith('sudo timeout ')
        ->and($ssh->lines()[3])->toContain('systemctl stop');
});

it('stops after health or package failure with no retry rollback Doctor or success marker', function (string $stage): void {
    $node = FleetFixtures::node('recipe-fail');
    $node->update(['settings' => ['machine_recipe' => ['version' => 'previous']]]);
    $ssh = recipeSsh(ScriptedSshExecutor::fail(stdout: 'recipe-stage:'.$stage, stderr: 'raw manager output must stay private'));
    $verification = new RecipeVerification;
    app()->instance(FleetNodeVerification::class, $verification);

    expect(fn () => app(ConvergeNodeRecipeAction::class)->execute($node, new RecipeLoader()->load()))
        ->toThrow(ResourceOperationException::class, 'stopped at recipe-stage:'.$stage);
    expect($verification->calls)->toBe(0)
        ->and($node->refresh()->settings)->toBe([])
        ->and($ssh->commands)->toHaveCount(4);
})->with(['packages', 'health', 'restart', 'inventory', 'download']);

it('stops on Doctor failure without rollback or recording the recipe', function (): void {
    $node = FleetFixtures::node('recipe-doctor-fail');
    $ssh = recipeSsh();
    $verification = new RecipeVerification(passed: false);
    app()->instance(FleetNodeVerification::class, $verification);

    expect(fn () => app(ConvergeNodeRecipeAction::class)->execute($node, new RecipeLoader()->load()))
        ->toThrow(ResourceOperationException::class, 'stopped at Doctor');
    expect($node->refresh()->settings)->toBe([])
        ->and($verification->calls)->toBe(1)
        ->and($ssh->commands)->toHaveCount(4);
});

it('refuses conflicting managed apt intent before any SSH or mutation', function (): void {
    $node = FleetFixtures::node('recipe-conflict', [RoleName::AppProd]);
    $manager = ToolManagerRecord::query()->create(['node_id' => $node->id, 'name' => 'apt', 'status' => 'active']);
    Tool::query()->create(['node_id' => $node->id, 'tool_manager_id' => $manager->id, 'package' => 'git', 'version_constraint' => '<1.0', 'status' => 'installed']);
    $ssh = recipeSsh();

    expect(fn () => app(ConvergeNodeRecipeAction::class)->execute($node, new RecipeLoader()->load()))
        ->toThrow(ResourceOperationException::class, 'conflicts with managed Tool');
    expect($ssh->commands)->toBe([]);
});

it('checks the existing managed tool inventory before Doctor and leaves tool intent unchanged', function (): void {
    $node = FleetFixtures::node('recipe-tool-fail');
    $manager = ToolManagerRecord::query()->create(['node_id' => $node->id, 'name' => 'composer', 'status' => 'active']);
    $tool = Tool::query()->create(['node_id' => $node->id, 'tool_manager_id' => $manager->id, 'package' => 'vendor/tool', 'status' => 'installed']);
    $ssh = recipeSsh();
    $inspector = new class implements ToolInspector
    {
        use InspectsToolsIndividually;

        public function inspect(Tool $tool): ToolInspectionData
        {
            return new ToolInspectionData(false, null);
        }
    };
    app()->instance(ToolInspector::class, $inspector);
    $verification = new RecipeVerification;
    app()->instance(FleetNodeVerification::class, $verification);

    expect(fn () => app(ConvergeNodeRecipeAction::class)->execute($node, new RecipeLoader()->load()))
        ->toThrow(ResourceOperationException::class, 'managed Tool');
    expect($tool->refresh()->status->value)->toBe('installed')
        ->and($node->refresh()->settings)->toBe([])
        ->and($verification->calls)->toBe(0)
        ->and($ssh->commands)->toHaveCount(4);
});

it('runs the command dry-run by Node name or ID without changing the Node', function (bool $useId): void {
    $node = FleetFixtures::node('recipe-command', [RoleName::AppProd]);
    $ssh = recipeSsh();

    $exit = Artisan::call('node:converge-recipe', ['node' => $useId ? (string) $node->id : $node->name, '--dry-run' => true]);
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($output['applied'])->toBeFalse()
        ->and($output['plan']['tools']['curl']['changed'])->toBeTrue();
    expect($ssh->commands)->toHaveCount(1)
        ->and($node->refresh()->settings)->toBeNull();
})->with([false, true]);

it('refuses a different requested identity before reaching a Node', function (): void {
    $this->artisan('node:converge-recipe', ['node' => 'missing', '--recipe' => 'sha256:wrong', '--dry-run' => true])
        ->expectsOutputToContain('does not match the checked-out recipe')->assertFailed();
});

it('lists mixed recipe identities from the registry without SSH', function (): void {
    $current = FleetFixtures::node('current');
    $current->update(['settings' => ['machine_recipe' => ['version' => 'sha256:current', 'snapshot' => '20261014T000000Z']]]);
    FleetFixtures::node('pending');

    $exit = Artisan::call('node:converge-recipe', ['--status' => true]);
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($output[0]['machine_recipe']['version'])->toBe('sha256:current')
        ->and($output[1]['machine_recipe'])->toBeNull();
});

it('runs the remote program regression tests without host mutations or network access', function (): void {
    $process = new Process(['python3', base_path('tests/Fixtures/machine-software/converge_node_test.py'), resource_path('machine-software/converge-node.py')]);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
});

it('keeps newer Ubuntu versions visible in the plan result and recorded status', function (): void {
    $node = FleetFixtures::node('recipe-newer');
    $recipe = new RecipeLoader()->load();
    $newer = ['curl' => ['installed' => '8.18.0-1ubuntu2.8', 'version' => $recipe->tools['curl']->version]];
    recipeSsh(ScriptedSshExecutor::ok(json_encode(['changed_packages' => [], 'healthy' => true, 'newer_than_recipe' => $newer], JSON_THROW_ON_ERROR)), static function (RemoteCommand $command): CommandResult {
        $payload = json_decode($command->arguments[3], true, flags: JSON_THROW_ON_ERROR);
        $tools = [];
        foreach ($payload['tools'] as $name => $pin) {
            $tools[$name] = ['source' => $pin['source'], 'version' => $pin['version'], 'installed' => $name === 'curl' ? '8.18.0-1ubuntu2.8' : $pin['version'], 'changed' => false, 'status' => $name === 'curl' ? 'newer_than_recipe' : 'current'];
        }

        return ScriptedSshExecutor::ok(json_encode($tools, JSON_THROW_ON_ERROR));
    });
    app()->instance(FleetNodeVerification::class, new RecipeVerification);

    $result = app(ConvergeNodeRecipeAction::class)->execute($node, $recipe);

    expect($result['plan']->tools['curl'])->toMatchArray(['changed' => false, 'status' => 'newer_than_recipe', 'installed' => '8.18.0-1ubuntu2.8'])
        ->and($result['changes']['newer_than_recipe'])->toBe($newer)
        ->and($node->refresh()->settings['machine_recipe']['newer_than_recipe'])->toBe($newer);
});

it('retains redacted package command output in the failed convergence result', function (): void {
    $node = FleetFixtures::node('recipe-package-output');
    recipeSsh(ScriptedSshExecutor::fail(stdout: json_encode(['failure' => [
        'stage' => 'recipe-stage:packages', 'packages' => ['curl=8.18.0-1ubuntu2.7'],
        'command' => ['apt-get', 'install', 'curl=8.18.0-1ubuntu2.7'], 'exit_code' => 100,
        'stdout' => 'curl: dependency conflict',
        'stderr' => 'Failed to fetch https://user:secret@example.test/pkg API_TOKEN=private-token',
    ]], JSON_THROW_ON_ERROR)));

    $exit = Artisan::call('node:converge-recipe', ['node' => $node->name]);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($result['message'])->toContain('recipe-stage:packages', 'curl=8.18.0-1ubuntu2.7', 'apt-get', 'dependency conflict', '[REDACTED]')
        ->and($result['automatic_rollback'])->toBeFalse();
    expect($result['message'])->not->toContain('user:secret');
    expect($result['message'])->not->toContain('private-token');
    expect($node->refresh()->settings)->toBe([]);
});

it('shows a source repair and an unsupported newer Ubuntu build as planned actions without applying them', function (?string $reason): void {
    $node = FleetFixtures::node('recipe-refused');
    $node->update(['settings' => ['machine_recipe' => ['version' => 'previous']]]);
    $ssh = recipeSsh(inspection: static function (RemoteCommand $command) use ($reason): CommandResult {
        expect($command->timeout)->toBe(900.0);
        $payload = json_decode($command->arguments[3], true, flags: JSON_THROW_ON_ERROR);
        $tools = [];
        foreach ($payload['tools'] as $name => $pin) {
            $tools[$name] = ['source' => $pin['source'], 'version' => $pin['version'], 'installed' => $name === 'curl' ? '99.0-foreign' : $pin['version'], 'changed' => false, 'status' => $name === 'curl' ? 'unsupported_newer' : 'current'];
        }
        if ($reason !== null) {
            $tools['curl']['refusal'] = 'origin:curl:'.$reason;
        }

        return ScriptedSshExecutor::ok(json_encode([...$tools, '_actions' => ['repair_ubuntu_sources', 'retire_git_source', 'pin_git']], JSON_THROW_ON_ERROR));
    });

    $exit = Artisan::call('node:converge-recipe', ['node' => $node->name, '--dry-run' => true]);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($result['applied'])->toBeFalse()
        ->and($result['plan']['actions'])->toBe(['repair_ubuntu_sources', 'retire_git_source', 'pin_git'])
        ->and($result['plan']['tools']['curl'])->toMatchArray(['status' => 'unsupported_newer', 'changed' => false, 'refusal' => 'origin:curl'.($reason === null ? '' : ':'.$reason)])
        ->and($node->refresh()->settings['machine_recipe']['version'])->toBe('previous');
    expect(array_filter($ssh->lines(), static fn (string $line): bool => str_contains($line, ' apply ')))->toBe([]);
})->with([null, 'dpkg-log-missing', 'snapshot-unavailable', 'snapshot-unproved', 'snapshot-lookup-limit']);
