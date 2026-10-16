<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmImageBuildStep;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\IncusTaskVmBaseImage;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Infrastructure\TaskVms\RecipeImageBuildSteps;
use App\Infrastructure\TaskVms\RecipeImagePrograms;
use App\Infrastructure\TaskVms\RecipeImagePruning;
use App\Infrastructure\TaskVms\TaskVmImageBuildChain;
use App\Jobs\TaskVms\ImageBuild\BuildRecipeImageStep;
use App\Jobs\TaskVms\ImageBuild\PromoteImage;
use App\Jobs\TaskVms\RefreshTaskVmBaseImages;
use App\Models\Node;
use App\Models\TaskVmImageBuild;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Symfony\Component\Process\Process;
use Tests\Support\TaskVmSettingsBuilder;

require_once __DIR__.'/../../Support/FakeIncusImageHost.php';

function recipeImageHost(): Node
{
    return Node::query()->create(['name' => 'recipe-beast', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '192.0.2.7', 'wireguard_ip' => '10.44.0.7', 'user' => 'orbit']);
}

function recipeImageTransport(Node $host): FakeIncusImageHost
{
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($host->id)]]);
    $incus = new FakeIncusImageHost;
    $incus->recipeImages = true;
    $incus->images = [IMAGE_STOCK => [TaskVmHost::SourceImage], IMAGE_OLD => [TaskVmHost::BaseImage]];
    app()->instance(SshExecutor::class, $incus);
    app()->instance(SshKeyProvider::class, new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/keys/id_ed25519';
        }

        public function publicKey(): string
        {
            return IMAGE_KEY;
        }
    });
    app()->instance(KnownHostsStore::class, new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/keys/known_hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    });
    $directory = sys_get_temp_dir().'/recipe-image-artifacts';
    @mkdir($directory.'/sandbox-artifacts', 0700, true);
    file_put_contents($directory.'/sandbox-artifacts/pi-test', 'pinned pi bytes');
    config(['orbit.home' => $directory]);

    return $incus;
}

function recipeImageDocument(string $snapshot = '20261014T000000Z'): string
{
    $data = json_decode(file_get_contents(resource_path('machine-software/recipe.json')), true, flags: JSON_THROW_ON_ERROR);
    $data['ubuntu']['snapshot'] = $snapshot;
    $data['tools']['pi']['artifacts']['amd64']['filename'] = 'pi-test';
    $data['tools']['pi']['artifacts']['amd64']['sha256'] = hash('sha256', 'pinned pi bytes');

    return json_encode($data, JSON_THROW_ON_ERROR);
}

function recipeImageCurrent(string $document): void
{
    $directory = sys_get_temp_dir().'/recipe-image-release';
    @mkdir($directory.'/releases/current/apps/gateway/resources/machine-software', 0700, true);
    if (! is_link($directory.'/orbit')) {
        symlink('releases/current', $directory.'/orbit');
    }
    file_put_contents($directory.'/orbit/apps/gateway/resources/machine-software/recipe.json', $document);
    config(['orbit.gateway_checkout' => $directory.'/orbit/apps/gateway']);
}

function runRecipeImageBuild(TaskVmImageBuild $build, bool $promote = true): void
{
    foreach (app(TaskVmImageBuildChain::class)->jobs($build) as $job) {
        if (! $promote && $job instanceof PromoteImage) {
            break;
        }
        $job->handle(app(RecipeImageBuildSteps::class));
        $build->refresh();
    }
}

beforeEach(function (): void {
    Bus::fake();
    Sleep::fake(syncWithCarbon: true);
});

it('keeps aliases for base and every role within the Incus name limit', function (string $prefix): void {
    $identity = 'sha256:0bd780fc6472'.str_repeat('a', 52);
    $aliases = [];
    foreach (['base', 'gateway', 'app-dev', 'app-prod', 'operator'] as $profile) {
        $alias = RecipeImageBuilds::alias($prefix, $identity, $profile);
        expect(strlen($alias))->toBeLessThanOrEqual(64);
        expect($alias)->toEndWith('-recipe-0bd780fc6472-'.$profile);
        $aliases[] = $alias;
    }
    expect(array_unique($aliases))->toHaveCount(5);
})->with([TaskVmHost::BaseImage, 'shark-task-base', str_repeat('a', 254)]);

it('installs OpenTelemetry only in app-dev image profiles', function (string $profile, bool $enabled): void {
    $node = recipeImageHost();
    $incus = recipeImageTransport($node);
    $host = app(TaskVmSettings::class)->hosts[0];
    $shell = app(IncusTaskVmBaseImage::class)->shell($host, $node, 'recipe-builder');

    app(RecipeImagePrograms::class)->apply($shell, new RecipeLoader()->load(), $profile, 'packages');

    $calls = array_values(array_filter($incus->guest, static fn ($call): bool => $call->argv[0] === 'python3'));
    expect($calls)->toHaveCount(1);
    $payload = json_decode($calls[0]->argv[3], true, flags: JSON_THROW_ON_ERROR);
    expect($payload['php']['input']['opentelemetry'])->toBe($enabled);
    foreach (['8.4', '8.5'] as $version) {
        expect(isset($payload['tools']["php{$version}-opentelemetry"]))->toBe($enabled);
        expect(in_array("php{$version}-opentelemetry", array_column($payload['php']['input']['versions'][$version]['packages'], 'name'), true))->toBe($enabled);
        expect($payload['php']['input']['versions'][$version]['extensions'])->not->toContain('opentelemetry');
    }
})->with(['app-dev' => ['app-dev', true], 'app-prod' => ['app-prod', false], 'base' => ['base', false], 'ci-runner' => ['ci-runner', false]]);

it('identifies publish failures as host commands without requesting a guest log', function (): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    $incus->fail = 'publish';

    expect(fn () => runRecipeImageBuild($build))->toThrow(TaskVmException::class);

    $failed = array_find($build->refresh()->steps, fn (array $step): bool => $step['program'] === 'base:publish');
    expect($failed['error'])->toContain('`incus publish`', 'Error: publish failed', 'Publishing runs on Incus host [recipe-beast].')
        ->not->toContain('Builder log unavailable', '/var/log/orbit/recipe-images/base-publish.log');
});

describe('recipe image triggers', function (): void {
    it('starts a deployed recipe immediately and reuses unchanged running and succeeded builds', function (): void {
        $host = recipeImageHost();
        recipeImageTransport($host);
        recipeImageCurrent(recipeImageDocument());
        app()->call([new RefreshTaskVmBaseImages, 'handle']);
        $build = TaskVmImageBuild::query()->sole();
        expect($build->recipe_identity)->toBe(new RecipeLoader()->decode(recipeImageDocument())->identity);
        expect($build->recipe_json)->toBe(recipeImageDocument());
        app()->call([new RefreshTaskVmBaseImages, 'handle']);
        expect(TaskVmImageBuild::query()->count())->toBe(1);
        runRecipeImageBuild($build);
        app()->call([new RefreshTaskVmBaseImages, 'handle']);
        expect(TaskVmImageBuild::query()->count())->toBe(1);
        Bus::assertDispatchedTimes(BuildRecipeImageStep::class, 1);
    });

    it('builds and smoke-tests only one base on each host while preserving guest DNS', function (): void {
        $first = recipeImageHost();
        $incus = recipeImageTransport($first);
        $second = Node::query()->create(['name' => 'recipe-shark', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '192.0.2.8', 'wireguard_ip' => '10.44.0.8', 'user' => 'orbit']);
        TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($first->id), TaskVmSettingsBuilder::host($second->id, ['imageAlias' => 'shark-task-base'])]]);
        recipeImageCurrent(recipeImageDocument());
        $builds = app(RecipeImageBuilds::class)->reconcile();
        expect($builds)->toHaveCount(2);
        foreach ($builds as $build) {
            runRecipeImageBuild($build);
            expect(array_keys($build->images))->toBe(['base']);
            expect(app(TaskVmImageBuildChain::class)->jobs($build))->toHaveCount(14);
            expect($build->state)->toBe(TaskVmImageBuildState::Succeeded);
            foreach ($build->images as $profile => $image) {
                expect($incus->imageProperties[$image['fingerprint']])->toBe(['orbit.recipe' => $build->recipe_identity, 'orbit.profile' => $profile]);
                expect($image)->toMatchArray(['project' => 'orbit-tasks', 'type' => 'virtual-machine']);
                expect(strlen($image['alias']))->toBeLessThanOrEqual(64);
            }
        }
        foreach ($incus->guest as $call) {
            expect(json_encode([$call->argv, $call->input], JSON_THROW_ON_ERROR))->not->toContain('resolved.conf', 'resolvectl');
        }
        $launches = array_values(array_filter($incus->commands, static fn ($command): bool => ($command->arguments[5] ?? '') === 'launch'));
        expect($launches)->toHaveCount(4);
        $base = $builds[0]->images['base']['fingerprint'];
        expect(array_filter($launches, static fn ($command): bool => $command->arguments[count($command->arguments) - 2] === $base))->toHaveCount(1);
        Bus::assertDispatchedTimes(BuildRecipeImageStep::class, 2);
    });

    it('keeps previous images on a base smoke failure and resumes from the failed phase', function (): void {
        $host = recipeImageHost();
        $incus = recipeImageTransport($host);
        recipeImageCurrent(recipeImageDocument());
        $build = app(RecipeImageBuilds::class)->reconcile()[0];
        $incus->fail = 'recipe base:verify';
        expect(fn () => runRecipeImageBuild($build))->toThrow(TaskVmException::class);
        expect($build->refresh()->state)->toBe(TaskVmImageBuildState::Failed);
        expect($incus->images[IMAGE_OLD])->toContain(TaskVmHost::BaseImage);
        expect($build->promoted_at)->toBeNull();
        $incus->fail = null;
        $chain = app(TaskVmImageBuildChain::class);
        $chain->resume($host, $build->id);
        $remaining = false;
        foreach ($chain->jobs($build->refresh()) as $job) {
            $remaining = $remaining || $job->program() === 'base:smoke';
            if ($remaining) {
                $job->handle(app(RecipeImageBuildSteps::class));
            }
        }
        expect($build->refresh()->state)->toBe(TaskVmImageBuildState::Succeeded);
    });

    it('fences an older build before promotion while the newer recipe waits for its host', function (): void {
        $host = recipeImageHost();
        $incus = recipeImageTransport($host);
        recipeImageCurrent(recipeImageDocument());
        $old = app(RecipeImageBuilds::class)->reconcile()[0];
        runRecipeImageBuild($old, promote: false);
        recipeImageCurrent(recipeImageDocument('20261015T000000Z'));
        expect(app(RecipeImageBuilds::class)->reconcile())->toBe([]);
        expect(fn () => new PromoteImage($old->id)->handle(app(RecipeImageBuildSteps::class)))
            ->toThrow(TaskVmException::class, 'newer recipe');
        expect($incus->images[IMAGE_OLD])->toContain(TaskVmHost::BaseImage);
        $new = app(RecipeImageBuilds::class)->reconcile()[0];
        runRecipeImageBuild($new);
        expect($new->refresh()->recipe_generation)->toBe(2);
        expect($incus->images[$new->image_fingerprint])->toContain(TaskVmHost::BaseImage);
        expect(fn () => app(TaskVmImageBuildChain::class)->resume($host, $old->id))->toThrow(TaskVmException::class, 'newer recipe');
    });

    it('keeps the stored recipe during a release and prints image locations from the manual commands', function (): void {
        $host = recipeImageHost();
        recipeImageTransport($host);
        recipeImageCurrent(recipeImageDocument());
        $this->artisan('task-vms:build-recipe')->expectsOutputToContain('recipe-beast')->assertSuccessful();
        $build = TaskVmImageBuild::query()->sole();
        $identity = $build->recipe_identity;
        recipeImageCurrent(recipeImageDocument('20261015T000000Z'));
        runRecipeImageBuild($build, promote: false);
        expect($build->recipe_identity)->toBe($identity);
        $this->artisan('task-vms:image-builds')->expectsOutputToContain($identity)
            ->expectsOutputToContain($build->images['base']['alias'])->expectsOutputToContain('base:smoke')->assertSuccessful();
        expect(DB::table('task_vm_image_recipe')->value('generation'))->toBe(1);
    });
});

it('runs image installer regressions without host changes or downloads', function (): void {
    $process = new Process(['python3', base_path('tests/Fixtures/machine-software/build_image_test.py'),
        resource_path('remote/lib/recipe.py'), resource_path('machine-software/build-image.py')]);
    $process->run();
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
});

it('retries a discarded failure without replacing already published recipe images', function (): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    $incus->fail = 'recipe base:verify';
    expect(fn () => runRecipeImageBuild($build))->toThrow(TaskVmException::class);
    $base = $build->refresh()->image_fingerprint;
    app(IncusTaskVmBaseImage::class)->sweep(app(TaskVmSettings::class)->host($host->id), $host, discardFailed: true);
    $incus->fail = null;
    $this->artisan('task-vms:build-recipe', ['--retry' => true])->assertSuccessful();
    runRecipeImageBuild($build->refresh());
    expect($build->state)->toBe(TaskVmImageBuildState::Succeeded);
    expect($build->image_fingerprint)->toBe($base);
    expect($build->attempt)->toBe(2);
});

it('reactivates verified images when the recipe is explicitly reverted without rebuilding them', function (): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $first = app(RecipeImageBuilds::class)->reconcile()[0];
    runRecipeImageBuild($first);
    recipeImageCurrent(recipeImageDocument('20261015T000000Z'));
    runRecipeImageBuild(app(RecipeImageBuilds::class)->reconcile()[0]);
    recipeImageCurrent(recipeImageDocument());
    $reactivated = app(RecipeImageBuilds::class)->reconcile()[0];
    $jobs = app(TaskVmImageBuildChain::class)->jobs($reactivated);
    foreach (array_slice($jobs, -2) as $job) {
        $job->handle(app(RecipeImageBuildSteps::class));
    }
    expect($reactivated->refresh()->state)->toBe(TaskVmImageBuildState::Succeeded);
    expect($incus->images[$first->image_fingerprint])->toContain(TaskVmHost::BaseImage);
    expect(TaskVmImageBuild::query()->count())->toBe(2);
});

it('lets the first recipe worker acknowledge a submission before the dispatcher reply arrives', function (): void {
    $host = recipeImageHost();
    recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    $build->update(['submission_pending_at' => now()->toISOString()]);
    $job = app(TaskVmImageBuildChain::class)->jobs($build)[0];
    $job->handle(app(RecipeImageBuildSteps::class));
    expect($build->refresh()->current_program)->toBe('base:reset');
    expect($build->submission_pending_at)->toBeNull();
});

it('automatically retries a missed queue submission without retrying a failed image phase', function (): void {
    $host = recipeImageHost();
    recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    $build->update(['submission_pending_at' => now()->subMinutes(11)->toISOString()]);
    app(TaskVmImageBuildChain::class)->recoverSubmissions();
    expect($build->refresh()->state)->toBe(TaskVmImageBuildState::Failed);
    app(RecipeImageBuilds::class)->reconcile();
    expect($build->refresh()->state)->toBe(TaskVmImageBuildState::Running);
    expect($build->attempt)->toBe(2);
});

it('records a bounded redacted output tail and a real builder log for failed recipe phases', function (string $phase): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    $incus->fail = 'recipe base:'.$phase;
    $incus->recipeFailure = new CommandResult(1, "stdout diagnostic\n".implode("\n", array_map(fn (int $n): string => "line {$n}", range(1, 60))),
        "API_TOKEN=do-not-store-this\nAuthorization: Bearer do-not-store-that\napt dependency conflict", 1, false);

    expect(fn () => runRecipeImageBuild($build))->toThrow(TaskVmException::class);

    $logPhase = $phase === 'verify' ? 'smoke' : $phase;
    $failed = array_find($build->refresh()->steps, fn (array $step): bool => $step['program'] === 'base:'.$logPhase);
    expect($failed['error'])->toContain('apt dependency conflict', 'line 60', '[REDACTED]',
        '/var/log/orbit/recipe-images/base-'.$logPhase.'.log', $build->builder_vm_name)
        ->not->toContain('stdout diagnostic', 'line 1'."\n", 'do-not-store-this', 'do-not-store-that');
    expect(mb_strlen($failed['error']))->toBeLessThanOrEqual(4000);
    $logs = array_filter($incus->commands, static fn ($command): bool => str_contains((string) $command->input, 'apt dependency conflict'));
    expect($logs)->not->toBeEmpty();
    foreach ($logs as $log) {
        expect($log->input)->toContain('[REDACTED]')->not->toContain('do-not-store-this', 'do-not-store-that');
    }
})->with(['packages', 'javascript', 'rust', 'agent', 'verify']);

/** Simulate the four role images published before the base-only chain. */
function recipeImageLegacyRoles(TaskVmImageBuild $build, FakeIncusImageHost $incus): void
{
    $images = $build->images;
    foreach (['gateway', 'app-dev', 'app-prod', 'operator'] as $profile) {
        $alias = RecipeImageBuilds::alias(TaskVmHost::BaseImage, $build->recipe_identity, $profile);
        $fingerprint = hash('sha256', $alias);
        $incus->images[$fingerprint] = [$alias];
        $images[$profile] = ['alias' => $alias, 'fingerprint' => $fingerprint, 'project' => 'orbit-tasks', 'type' => 'virtual-machine'];
    }
    $build->update(['images' => $images]);
}

it('prunes every profile of older identities while keeping the previous recipe and images in use', function (): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $old = app(RecipeImageBuilds::class)->reconcile()[0];
    runRecipeImageBuild($old);
    recipeImageLegacyRoles($old, $incus);
    $used = $old->images['app-dev']['fingerprint'];
    $shared = $old->images['operator']['fingerprint'];
    $incus->instances['tvm-running-task'] = $used;
    $incus->images[$shared][] = 'operator-kept';
    recipeImageCurrent(recipeImageDocument('20261015T000000Z'));
    $previous = app(RecipeImageBuilds::class)->reconcile()[0];
    runRecipeImageBuild($previous);
    recipeImageLegacyRoles($previous, $incus);
    recipeImageCurrent(recipeImageDocument('20261016T000000Z'));
    $current = app(RecipeImageBuilds::class)->reconcile()[0];

    runRecipeImageBuild($current);

    expect($current->state)->toBe(TaskVmImageBuildState::Succeeded);
    foreach ([$current, $previous] as $kept) {
        expect($incus->images)->toHaveKey($kept->images['base']['fingerprint']);
    }
    foreach (['gateway', 'app-dev', 'app-prod', 'operator'] as $profile) {
        expect($incus->images)->not->toHaveKey($previous->images[$profile]['fingerprint']);
    }
    expect($incus->images[$used])->toBe([$old->images['app-dev']['alias']]);
    expect($incus->images[$shared])->toBe(['operator-kept']);
    expect($incus->images)->toHaveKeys([$used, $shared, IMAGE_STOCK]);
    foreach (['base', 'gateway', 'app-prod'] as $profile) {
        expect($incus->images)->not->toHaveKey($old->images[$profile]['fingerprint']);
    }
    unset($incus->instances['tvm-running-task']);
    app(RecipeImagePruning::class)->prune($current);
    expect($incus->images)->not->toHaveKey($used);
});

it('resumes a failed final prune without rebuilding or moving the base alias again', function (): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    foreach (['20261014T000000Z', '20261015T000000Z'] as $snapshot) {
        recipeImageCurrent(recipeImageDocument($snapshot));
        runRecipeImageBuild(app(RecipeImageBuilds::class)->reconcile()[0]);
    }
    recipeImageCurrent(recipeImageDocument('20261016T000000Z'));
    $current = app(RecipeImageBuilds::class)->reconcile()[0];
    $incus->fail = 'image delete';

    expect(fn () => runRecipeImageBuild($current))->toThrow(TaskVmException::class);
    expect($current->refresh()->current_step)->toBe(TaskVmImageBuildStep::Prune)
        ->and($current->state)->toBe(TaskVmImageBuildState::Failed)
        ->and($current->promoted_at)->not->toBeNull();
    $incus->fail = null;
    $incus->commands = [];
    app(TaskVmImageBuildChain::class)->resume($host, $current->id);
    runRecipeImageBuild($current->refresh());

    expect($current->state)->toBe(TaskVmImageBuildState::Succeeded);
    foreach ($incus->commands as $command) {
        expect(array_slice($command->arguments, 5, 2))->not->toBe(['image', 'alias']);
        expect($command->arguments)->not->toContain('publish', 'launch', 'PATCH');
    }
});

it('keeps old build rows readable but refuses to resume the removed build path', function (): void {
    $host = recipeImageHost();
    recipeImageTransport($host);
    $legacy = TaskVmImageBuild::query()->create(['host_node_id' => $host->id, 'state' => 'failed',
        'current_step' => 'upgrade-packages', 'source_alias' => TaskVmHost::SourceImage, 'from_stock' => true]);

    $this->artisan('task-vms:image-builds')->expectsOutputToContain(TaskVmHost::SourceImage)->assertSuccessful();
    expect(fn () => app(TaskVmImageBuildChain::class)->resume($host, $legacy->id))->toThrow(TaskVmException::class, 'Legacy image builds cannot resume');
});

it('rebuilds a reverted identity after its images were pruned', function (): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    $first = null;
    foreach (['20261014T000000Z', '20261015T000000Z', '20261016T000000Z'] as $snapshot) {
        recipeImageCurrent(recipeImageDocument($snapshot));
        $build = app(RecipeImageBuilds::class)->reconcile()[0];
        $first ??= $build;
        runRecipeImageBuild($build);
    }
    expect($incus->images)->not->toHaveKey($first->image_fingerprint);
    recipeImageCurrent(recipeImageDocument());

    $reverted = app(RecipeImageBuilds::class)->reconcile()[0];
    expect($reverted->images)->toBe([])->and($reverted->current_step)->toBeNull();
    runRecipeImageBuild($reverted);

    expect($reverted->id)->toBe($first->id)->and($reverted->state)->toBe(TaskVmImageBuildState::Succeeded);
    expect($incus->images[$reverted->image_fingerprint])->toContain(TaskVmHost::BaseImage);
});

it('prunes superseded failed recipes but keeps images without host-local ownership records', function (): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $old = app(RecipeImageBuilds::class)->reconcile()[0];
    runRecipeImageBuild($old);
    $old->update(['state' => TaskVmImageBuildState::Failed]);
    $foreign = Node::query()->create(['name' => 'recipe-shark', 'status' => 'active', 'public_ssh_host' => '192.0.2.8']);
    $identity = 'sha256:'.str_repeat('f', 64);
    TaskVmImageBuild::query()->create(['host_node_id' => $foreign->id, 'recipe_identity' => $identity, 'state' => 'succeeded']);
    $incus->images[IMAGE_USED] = [RecipeImageBuilds::alias(TaskVmHost::BaseImage, $identity, 'base')];
    foreach (['20261015T000000Z', '20261016T000000Z'] as $snapshot) {
        recipeImageCurrent(recipeImageDocument($snapshot));
        $current = app(RecipeImageBuilds::class)->reconcile($host->id)[0];
        runRecipeImageBuild($current);
    }

    expect($incus->images)->not->toHaveKey($old->image_fingerprint)->toHaveKey(IMAGE_USED);
});

it('retires current recipe role aliases without deleting its base or another running build', function (): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $current = app(RecipeImageBuilds::class)->reconcile()[0];
    runRecipeImageBuild($current);
    recipeImageLegacyRoles($current, $incus);
    $roles = $current->images;
    $sharedAlias = $roles['gateway']['alias'];
    unset($incus->images[$roles['gateway']['fingerprint']]);
    $incus->images[$current->image_fingerprint][] = $sharedAlias;
    $roles['gateway']['fingerprint'] = $current->image_fingerprint;
    $current->update(['images' => $roles]);
    $running = TaskVmImageBuild::query()->create(['host_node_id' => $host->id,
        'recipe_identity' => 'sha256:'.str_repeat('e', 64), 'state' => 'running']);
    recipeImageLegacyRoles($running, $incus);

    app(RecipeImagePruning::class)->prune($current);

    expect($incus->images[$current->image_fingerprint])->toBe([$roles['base']['alias'], TaskVmHost::BaseImage]);
    foreach (['app-dev', 'app-prod', 'operator'] as $profile) {
        expect($incus->images)->not->toHaveKey($roles[$profile]['fingerprint']);
    }
    foreach ($running->images as $image) {
        expect($incus->images[$image['fingerprint']])->toBe([$image['alias']]);
    }
    $running->delete();
    recipeImageCurrent(recipeImageDocument('20261015T000000Z'));
    runRecipeImageBuild(app(RecipeImageBuilds::class)->reconcile()[0]);
    recipeImageCurrent(recipeImageDocument());
    $reactivated = app(RecipeImageBuilds::class)->reconcile()[0];
    expect($reactivated->current_step)->toBe(TaskVmImageBuildStep::Promote)
        ->and($reactivated->image_fingerprint)->toBe($current->image_fingerprint);
});
