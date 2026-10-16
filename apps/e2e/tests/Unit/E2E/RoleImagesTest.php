<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\RoleImages;
use App\E2E\Value\RoleImage;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyTarget;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Process\Process;
use Tests\Support\FakeIncus;

beforeEach(function (): void {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
});

it('resolves all roles to the same verified recipe base', function (): void {
    $incus = new FakeIncus()->install();
    $identity = 'sha256:'.str_repeat('a', 64);
    $base = $incus->addImage('orbit-e2e-base', properties: ['orbit.recipe' => $identity, 'orbit.profile' => 'base']);
    $images = new RoleImages(new IncusHost);

    expect($images->resolve(RoleImage::cases()))->toBe(['gateway' => $base, 'app-dev' => $base, 'app-prod' => $base, 'operator' => $base]);
    expect($images->status()[0])->toMatchArray(['state' => 'current', 'fingerprint' => $base, 'recipe_identity' => $identity]);
});

it('assembles topology pnpm preparation with the remote recipe and artifact helpers', function (): void {
    $incus = new FakeIncus()->install();
    $incus->respond(static function (string $instance, array $argv): ?array {
        if ($argv[0] === 'sh' && str_contains($argv[2] ?? '', 'boot_id')) {
            return ["boot-ready\n", '', 0];
        }

        return null;
    });
    $target = TopologyTarget::for('TST-9', attemptId(), TopologyRecipe::compose(['app-dev']));
    foreach ($target->instances() as $instance) {
        $incus->instances[$instance] = ['type' => 'virtual-machine', 'status' => 'Running', 'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'], 'devices' => ['root' => ['type' => 'disk', 'path' => '/', 'pool' => 'orbit-e2e']]];
    }

    new RoleImages(new IncusHost(pool: 'orbit-e2e'), settleIntervalMicroseconds: 0)->settle($target);

    $preparations = array_values(array_filter($incus->execs, static fn (array $exec): bool => $exec['argv'][0] === 'python3'));
    expect($preparations)->toHaveCount(count($target->instances()));
    foreach ($preparations as $preparation) {
        expect($preparation['stdin'])->toBe($preparations[0]['stdin']);
        expect(json_decode($preparation['argv'][2], true, flags: JSON_THROW_ON_ERROR))->toHaveKeys(['version', 'artifacts']);
    }

    // Load the real shared library, not the guest-only entry point. This catches missing transitive helpers
    // without running an installer, downloading artifacts, or needing an orbit account on the test host.
    $probe = <<<'PY'
import sys
source = sys.stdin.read()
compile(source, '<pnpm-preparation>', 'exec')
library, separator, _ = source.partition('# Appended to the recipe')
assert separator
exec(library)
for name in ('vite_scope', 'run', 'download', 'install_javascript', 'artifact_candidates'):
    assert callable(globals()[name]), name
assert artifact_candidates('pnpm', {'url': 'https://example.test/pnpm.tgz', 'sha512': 'test'}, '') == ['https://example.test/pnpm.tgz']
PY;
    $process = new Process(['python3', '-c', $probe]);
    $process->setInput($preparations[0]['stdin']);
    $process->run();
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
});

it('rejects legacy, missing, or invalid base images', function (string $defect): void {
    $incus = new FakeIncus()->install();
    if ($defect !== 'missing') {
        $incus->addImage($defect === 'legacy' ? 'orbit-e2e-gateway' : 'orbit-e2e-base', $defect === 'container' ? 'container' : 'virtual-machine', [
            'orbit.recipe' => $defect === 'recipe' ? 'invalid' : 'sha256:'.str_repeat('a', 64),
            'orbit.profile' => $defect === 'profile' ? 'gateway' : 'base',
        ]);
    }
    $images = new RoleImages(new IncusHost);

    expect(fn () => $images->resolve(RoleImage::cases()))->toThrow(RuntimeException::class, 'Pre-recipe groups must drain or be cancelled');
    expect($images->status()[0]['state'])->toBe('missing');
})->with(['legacy', 'missing', 'container', 'recipe', 'profile']);
