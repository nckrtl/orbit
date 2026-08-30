<?php

declare(strict_types=1);

use App\E2E\Value\ProofFixtures;
use App\E2E\Value\ProofPlan;
use App\E2E\Value\TopologyProfile;
use Symfony\Component\Process\Process;

describe('NCK-82 proof plan', function (): void {
    it('proves the staged fixture on every role through its guest path only', function (): void {
        $root = dirname(__DIR__, 3).'/resources/proof/NCK-82';
        $plan = ProofPlan::fromFile("{$root}/plan.json");
        $fixture = ProofFixtures::guestPath('fixture-check.sh');

        expect(is_executable("{$root}/fixture-check.sh"))
            ->toBeTrue()
            ->and(new Process(['bash', '-n', "{$root}/fixture-check.sh"])->run())
            ->toBe(0)
            ->and($plan->setup)
            ->toBe([])
            ->and($plan->postDeploymentActions)
            ->toBe([])
            ->and(array_column($plan->acceptance, 'node'))
            ->toBe(TopologyProfile::ROLES);

        foreach ($plan->acceptance as $action) {
            expect($action['argv'])->toBe([$fixture, $action['node']]);
        }

        foreach (scandir($root) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                expect(ProofFixtures::isFixtureName($name))->toBeTrue();
            }
        }
    });
});
