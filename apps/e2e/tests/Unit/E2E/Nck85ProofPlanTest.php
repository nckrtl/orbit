<?php

declare(strict_types=1);

use App\E2E\Value\ProofFixtures;
use App\E2E\Value\ProofPlan;
use Symfony\Component\Process\Process;

describe('NCK-85 proof plan', function (): void {
    it('proves orbit by name on every checkout role through the staged fixture and a bare argv', function (): void {
        $root = dirname(__DIR__, 3).'/resources/proof/NCK-85';
        $plan = ProofPlan::fromFile("{$root}/plan.json");
        $fixture = ProofFixtures::guestPath('orbit-on-path.sh');

        expect(is_executable("{$root}/orbit-on-path.sh"))
            ->toBeTrue()
            ->and(new Process(['bash', '-n', "{$root}/orbit-on-path.sh"])->run())
            ->toBe(0)
            ->and($plan->setup)
            ->toBe([])
            ->and($plan->postDeploymentActions)
            ->toBe([])
            ->and(array_map(static fn (array $action): array => [$action['node'], $action['argv']], $plan->acceptance))
            ->toBe([
                ['gateway', [$fixture, 'gateway']],
                ['app-dev', [$fixture, 'app-dev']],
                ['app-dev', ['orbit', 'status']],
            ]);

        foreach (scandir($root) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                expect(ProofFixtures::isFixtureName($name))->toBeTrue();
            }
        }
    });
});
