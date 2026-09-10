<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

function planWorkspace(): string
{
    $root = sys_get_temp_dir().'/orbit-plan-'.bin2hex(random_bytes(8));
    mkdir($root.'/.loop', 0700, true);
    new Process(['git', 'init', '--quiet', $root])->mustRun();
    copy(dirname(__DIR__).'/Fixtures/feature-plan.md', $root.'/.loop/plan.md');

    return $root;
}

function lintPlan(string $root, string $action, array $options = []): int
{
    return Artisan::call('orbit:plan-lint', ['action' => $action, 'issue' => 'ORB-999', '--worktree' => $root, ...$options]);
}

it('requires an explicit receipt and detects stale plan, flow, issue, and validator bindings', function (): void {
    $root = planWorkspace();
    try {
        expect(lintPlan($root, 'check'))->toBe(0);
        expect($root.'/.loop/plan-lint.json')->not->toBeFile();
        expect(lintPlan($root, 'verify'))->toBe(1);
        expect(lintPlan($root, 'record'))->toBe(0);
        $receipt = file_get_contents($root.'/.loop/plan-lint.json');
        expect(lintPlan($root, 'verify'))->toBe(0);
        expect(lintPlan($root, 'record'))->toBe(0);
        expect(file_get_contents($root.'/.loop/plan-lint.json'))->toBe($receipt);

        foreach (['issue', 'flow', 'validator_sha256', 'plan_sha256', 'schema'] as $field) {
            $changed = json_decode($receipt, true, flags: JSON_THROW_ON_ERROR);
            $changed[$field] = 'wrong';
            file_put_contents($root.'/.loop/plan-lint.json', json_encode($changed));
            expect(lintPlan($root, 'verify'))->toBe(1);
        }
        file_put_contents($root.'/.loop/plan-lint.json', $receipt);
        file_put_contents($root.'/.loop/plan.md', "\nA changed plan.\n", FILE_APPEND);
        expect(lintPlan($root, 'verify'))->toBe(1);
        expect(Artisan::output())->toContain('Stale or invalid receipt');
        expect(lintPlan($root, 'record'))->toBe(0);
        file_put_contents($root.'/.loop/flow.json', '{"schema":1,"flow":"proof"}');
        expect(lintPlan($root, 'verify'))->toBe(1);
        expect(Artisan::output())->toContain('Flow header');
    } finally {
        File::deleteDirectory($root);
    }
});

it('removes old success on failed recording and fails closed on corrupt receipts', function (): void {
    $root = planWorkspace();
    try {
        expect(lintPlan($root, 'record'))->toBe(0);
        file_put_contents($root.'/.loop/plan-lint.json', '{broken');
        expect(lintPlan($root, 'verify'))->toBe(1);
        expect(lintPlan($root, 'record'))->toBe(0);
        file_put_contents($root.'/.loop/plan.md', "\n{{UNFINISHED}}\n", FILE_APPEND);
        expect(lintPlan($root, 'record'))->toBe(1);
        expect($root.'/.loop/plan-lint.json')->not->toBeFile();
    } finally {
        File::deleteDirectory($root);
    }
});

it('checks the submitted artifact without changing HEAD or the real index', function (): void {
    $root = planWorkspace();
    $run = fn (array $args): string => new Process(['git', '-C', $root, ...$args])->mustRun()->getOutput();
    try {
        $run(['-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '--allow-empty', '-m', 'base']);
        file_put_contents($root.'/staged.txt', 'Preserve the index.');
        $run(['add', 'staged.txt']);
        $head = $run(['rev-parse', 'HEAD']);
        $index = $run(['diff', '--cached']);
        expect(lintPlan($root, 'record'))->toBe(0);
        $run(['config', 'user.name', 'Test']);
        $run(['config', 'user.email', 'test@example.com']);
        $save = new Process([dirname(__DIR__, 4).'/bin/loop-artifacts', 'save', 'ORB-999'], $root)->mustRun()->getOutput();
        $artifact = json_decode($save, true, flags: JSON_THROW_ON_ERROR)['artifacts'];

        expect(lintPlan($root, 'verify', ['--artifact' => $artifact]))->toBe(0);
        file_put_contents($root.'/.loop/plan.md', "\nUpdated notes.\n", FILE_APPEND);
        expect(lintPlan($root, 'record'))->toBe(0);
        expect(lintPlan($root, 'verify', ['--artifact' => $artifact]))->toBe(1);
        expect(Artisan::output())->toContain('Saved artifact differs');
        expect($run(['rev-parse', 'HEAD']))->toBe($head);
        expect($run(['diff', '--cached']))->toBe($index);
    } finally {
        File::deleteDirectory($root);
    }
});

it('refuses symlink inputs without overwriting their targets', function (string $file): void {
    $root = planWorkspace();
    $target = $root.'/untouched';
    file_put_contents($target, 'Keep this.');
    try {
        if (is_file($root.'/.loop/'.$file)) {
            unlink($root.'/.loop/'.$file);
        }
        symlink($target, $root.'/.loop/'.$file);

        expect(lintPlan($root, 'record'))->toBe(1);
        expect(file_get_contents($target))->toBe('Keep this.');
    } finally {
        File::deleteDirectory($root);
    }
})->with(['plan.md', 'plan-lint.json', 'flow.json']);
