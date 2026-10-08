<?php

declare(strict_types=1);

use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\LifecycleStep;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Domain\Projects\TiaBaselineSetup;
use App\Domain\Projects\TiaBaselineSource;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\LifecycleSshExecutor;
use Tests\Support\TiaBaselineTestSource;

pest()->group('subprocess');

function tia_setup_source(): void
{
    app()->instance(TiaBaselineSource::class, new TiaBaselineTestSource);
}

function tia_setup_checkout(string $target): void
{
    File::ensureDirectoryExists(test()->sandbox.'/vendor/bin');
    file_put_contents(test()->sandbox.'/vendor/bin/pest', '<?php echo '.var_export($target."\n", true).';');
}

describe('TIA baseline setup', function (): void {
    beforeEach(function (): void {
        $this->sandbox = sys_get_temp_dir().'/orbit-tia-test-'.bin2hex(random_bytes(8));
        mkdir($this->sandbox, 0700);
    });
    afterEach(function (): void {
        File::deleteDirectory($this->sandbox);
    });

    it('restores after dependency setup and preserves local results on retry', function (): void {
        tia_setup_source();
        $project = Project::query()->create(['name' => 'TIA', 'slug' => 'tia', 'repository_url' => 'git@github.com:acme/shop.git']);
        $node = Node::query()->create(['name' => 'tia', 'wireguard_ip' => '192.0.2.8', 'public_ssh_host' => '192.0.2.8']);
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'tia', 'checkout_path' => $this->sandbox, 'status' => 'active']);
        $target = $this->sandbox.'/.pest/tia';
        $steps = new ProjectLifecycleStepStore;
        $steps->create($project, LifecyclePhase::Setup, new LifecycleStep('dependencies', 'mkdir -p vendor/bin; printf %s '.escapeshellarg('<?php echo '.var_export($target."\n", true).';').' > vendor/bin/pest', 10), null, null);
        $steps->create($project, LifecyclePhase::Setup, new LifecycleStep('tia', LifecycleStep::RestoreTiaBaseline, 30), null, null);
        $transport = new LifecycleSshExecutor(local: true);

        expect($transport->runner()->run($instance, LifecyclePhase::Setup))->toBeTrue();
        expect(file_get_contents($target.'/graph.json'))->toContain(str_repeat('a', 40));
        expect(file_get_contents($target.'/js-module-graph.cache.json'))->toBe('{}');
        expect($transport->inputs[1]['command'])->not->toBe(LifecycleStep::RestoreTiaBaseline);
        file_put_contents($target.'/graph.json', '{"local":"progress"}');
        $transport->runner()->run($instance, LifecyclePhase::Setup);
        expect(file_get_contents($target.'/graph.json'))->toBe('{"local":"progress"}');
    });

    it('refuses teardown without downloading or executing the builtin', function (): void {
        $project = Project::query()->create(['name' => 'TIA', 'slug' => 'tia', 'repository_url' => 'git@github.com:acme/shop.git']);
        $node = Node::query()->create(['name' => 'tia', 'wireguard_ip' => '192.0.2.8', 'public_ssh_host' => '192.0.2.8']);
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'tia', 'checkout_path' => $this->sandbox, 'status' => 'active']);
        (new ProjectLifecycleStepStore)->create($project, LifecyclePhase::Teardown, new LifecycleStep('tia', LifecycleStep::RestoreTiaBaseline, 30), null, null);
        $transport = new LifecycleSshExecutor;
        expect(fn () => $transport->runner()->run($instance, LifecyclePhase::Teardown))->toThrow(ResourceOperationException::class, 'setup-only');
        expect($transport->inputs)->toBe([]);
    });

    it('rejects an outside cache path or symlink without changing the destination', function (bool $symlink): void {
        tia_setup_source();
        $target = $this->sandbox.'/cache';
        if ($symlink) {
            mkdir($this->sandbox.'/original');
            file_put_contents($this->sandbox.'/original/keep', 'unchanged');
            symlink($this->sandbox.'/original', $target);
        } else {
            $target = '/tmp/unowned-tia-cache';
        }
        tia_setup_checkout($target);
        $command = app(TiaBaselineSetup::class)->command(new Project, 30);
        $process = new Process(['bash', '-c', $command], $this->sandbox);
        $process->run();
        expect($process->getExitCode())->toBe(1);
        if ($symlink) {
            expect(file_get_contents($this->sandbox.'/original/keep'))->toBe('unchanged');
        }
    })->with([true, false]);

    it('does not publish while another restore holds the cache lock', function (): void {
        tia_setup_source();
        $target = $this->sandbox.'/cache';
        mkdir($target);
        tia_setup_checkout($target);
        $lock = $this->sandbox.'/.orbit-tia-'.hash('sha256', $target).'.lock';
        $holder = new Process(['python3', '-c', 'import fcntl, sys, time; lock=open(sys.argv[1], "w"); fcntl.flock(lock, fcntl.LOCK_EX); print("ready", flush=True); time.sleep(30)', $lock]);
        $holder->start();
        try {
            $holder->waitUntil(fn (): bool => str_contains($holder->getOutput(), 'ready'));
            $command = app(TiaBaselineSetup::class)->command(new Project, 30);
            $process = new Process(['bash', '-c', $command], $this->sandbox);
            $process->run();
            expect($process->getExitCode())->toBe(75)->and(scandir($target))->toBe(['.', '..']);
        } finally {
            $holder->stop();
        }
    });

    it('retains an empty cache if validation fails before publication', function (): void {
        $target = $this->sandbox.'/cache';
        mkdir($target);
        tia_setup_checkout($target);
        $program = file_get_contents(resource_path('instances/tia-baseline.py'));
        $process = new Process(['python3', '-c', $program], $this->sandbox);
        $process->setInput(json_encode(['graph.json' => base64_encode('invalid')], JSON_THROW_ON_ERROR));
        $process->run();
        expect($process->getExitCode())->toBe(1)->and(scandir($target))->toBe(['.', '..']);
        expect(glob($this->sandbox.'/.orbit-tia-stage-*'))->toBe([]);
    });
});

it('uses the installed Pest cache path without running tests', function (): void {
    $sandbox = sys_get_temp_dir().'/orbit-tia-native-'.bin2hex(random_bytes(8));
    mkdir($sandbox, 0700);
    mkdir($sandbox.'/tests');
    file_put_contents($sandbox.'/tests/Pest.php', '<?php');
    mkdir($sandbox.'/vendor/bin', 0700, true);
    File::copy(base_path('vendor/pest-plugins.json'), $sandbox.'/vendor/pest-plugins.json');
    file_put_contents($sandbox.'/vendor/autoload.php', '<?php return require '.var_export(base_path('vendor/autoload.php'), true).';');
    file_put_contents($sandbox.'/vendor/bin/pest', '<?php $GLOBALS["_composer_bin_dir"] = __DIR__; $GLOBALS["_composer_autoload_path"] = dirname(__DIR__)."/autoload.php"; require '.var_export(base_path('vendor/nckrtl/pestphp-monorepo/bin/pest'), true).';');
    tia_setup_source();
    try {
        $discovery = new Process(['php', 'vendor/bin/pest', '--baseline'], $sandbox, ['HOME' => $sandbox]);
        $discovery->mustRun();
        $output = trim($discovery->getOutput());
        $payload = json_decode($output, true);
        expect(is_array($payload) ? $payload['raw'][0] : $output)->toStartWith($sandbox.'/');
        $command = app(TiaBaselineSetup::class)->command(new Project, 30);
        $process = new Process(['bash', '-c', $command], $sandbox, ['HOME' => $sandbox]);
        $process->mustRun();
        $graphs = glob($sandbox.'/.pest/tia/*/graph.json');
        expect($graphs)->toHaveCount(1);
        expect(file_get_contents($graphs[0]))->toContain(str_repeat('a', 40));
    } finally {
        File::deleteDirectory($sandbox);
    }
});
