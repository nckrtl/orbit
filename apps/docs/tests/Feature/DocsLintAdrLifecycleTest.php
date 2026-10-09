<?php

declare(strict_types=1);

use App\Documentation\AdrLifecycle;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

function adrLifecycleFixture(): string
{
    $root = sys_get_temp_dir().'/orbit-adr-lifecycle-'.bin2hex(random_bytes(8));
    test()->root = $root;
    mkdir($root.'/docs/decisions', 0777, true);
    mkdir($root.'/apps/docs/config', 0777, true);
    file_put_contents($root.'/docs/decisions/overview.mdx', "## Records\n\n## Retired decisions\n\n| Record | Decision | Now in |\n| --- | --- | --- |\n| 0114 | Expand the three-node Incus Cluster | [Topology](/reference/incus-topologies) |\n");
    file_put_contents($root.'/docs/docs.json', json_encode(['redirects' => [
        ['source' => '/decisions/0114-expand-the-three-node-incus-cluster', 'destination' => '/reference/incus-topologies'],
    ]], JSON_THROW_ON_ERROR));
    file_put_contents($root.'/apps/docs/config/adr-legacy-allowlist.php', "<?php return ['0114'];\n");
    file_put_contents($root.'/apps/docs/config/adr-retired-slugs.php', "<?php return ['0114' => ['0114-expand-the-three-node-incus-cluster']];\n");
    file_put_contents($root.'/docs/decisions/0114-judge-task-completion-as-separate-checks.md', "# Tasks\n");
    exec('git -C '.escapeshellarg($root).' init -q -b main');
    exec('git -C '.escapeshellarg($root).' add .');
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm fixture');

    return $root;
}

afterEach(function (): void {
    if (isset($this->root)) {
        new Filesystem()->deleteDirectory($this->root);
    }
});

function expectAdrLifecycleLintFailure(string $root, string $message): void
{
    config()->set('librarian.path', $root.'/docs');
    expect(Artisan::call('orbit:docs-lint', ['--format' => 'json']))->toBe(1);
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect(collect($output['findings'])->where('rule', AdrLifecycle::RULE)->pluck('message'))->toContain($message);
}

it('adr lifecycle rejects a retired slug that still has a file but not a different live 0114', function (): void {
    $root = adrLifecycleFixture();
    expect(new AdrLifecycle($root)->findings())->toBe([]);

    file_put_contents($root.'/docs/decisions/0114-expand-the-three-node-incus-cluster.md', "# Incus\n");
    expectAdrLifecycleLintFailure($root, 'Retired decision 0114-expand-the-three-node-incus-cluster still has a file.');
});

it('adr lifecycle rejects a retired record without a redirect', function (): void {
    $root = adrLifecycleFixture();
    file_put_contents($root.'/docs/docs.json', '{"redirects":[]}');
    expectAdrLifecycleLintFailure($root, 'Retired decision 0114 has no redirect from /decisions/0114-expand-the-three-node-incus-cluster in docs/docs.json.');
});

it('adr lifecycle rejects a wrong-slug redirect even with the live Tasks 0114', function (): void {
    $root = adrLifecycleFixture();
    file_put_contents($root.'/docs/docs.json', '{"redirects":[{"source":"/decisions/0114-typo","destination":"/reference/incus-topologies"}]}');
    file_put_contents($root.'/docs/decisions/0114-expand-the-three-node-incus-cluster.md', "# Incus\n");
    expectAdrLifecycleLintFailure($root, 'Retired decision 0114 has no redirect from /decisions/0114-expand-the-three-node-incus-cluster in docs/docs.json.');
    expectAdrLifecycleLintFailure($root, 'Retired decision 0114-expand-the-three-node-incus-cluster still has a file.');
});

it('adr lifecycle requires the retired slug independently of the redirect', function (): void {
    $root = adrLifecycleFixture();
    file_put_contents($root.'/apps/docs/config/adr-retired-slugs.php', '<?php return [];');
    expectAdrLifecycleLintFailure($root, 'Retired decision 0114 needs its exact source slug.');
});

it('adr lifecycle checks both retired slugs with a shared number while the allowlist resolves to the live file', function (): void {
    $root = adrLifecycleFixture();
    $incus = '0114-expand-the-three-node-incus-cluster';
    $tasks = '0114-judge-task-completion-as-separate-checks';
    // The retired Incus slug does not make the live Tasks ADR an allowlist violation.
    expect(new AdrLifecycle($root)->findings())->toBe([]);

    unlink($root.'/docs/decisions/'.$tasks.'.md');
    expect(collect(new AdrLifecycle($root)->findings())->pluck('message'))->toContain('Legacy ADR 0114 is no longer live; remove it from the allowlist.');
    file_put_contents($root.'/apps/docs/config/adr-legacy-allowlist.php', '<?php return [];');
    file_put_contents($root.'/docs/decisions/overview.mdx', "## Retired decisions\n\n| 0114 | Expand the three-node Incus Cluster | [Topology](/reference/incus-topologies) |\n| 0114 | Judge task completion as separate checks | [Tasks](/reference/tasks) |\n");
    file_put_contents($root.'/apps/docs/config/adr-retired-slugs.php', "<?php return ['0114' => ['{$incus}', '{$tasks}']];\n");
    file_put_contents($root.'/docs/docs.json', json_encode(['redirects' => [
        ['source' => '/decisions/'.$incus, 'destination' => '/reference/incus-topologies'],
        ['source' => '/decisions/'.$tasks, 'destination' => '/reference/tasks'],
    ]], JSON_THROW_ON_ERROR));
    expect(new AdrLifecycle($root)->findings())->toBe([]);

    file_put_contents($root.'/docs/decisions/overview.mdx', "## Retired decisions\n\n| 0114 | Expand the three-node Incus Cluster | [Topology](/reference/incus-topologies) |\n");
    expectAdrLifecycleLintFailure($root, 'Retired slug 0114 has no row in the decisions overview.');
    file_put_contents($root.'/docs/decisions/overview.mdx', "## Retired decisions\n\n| 0114 | Expand the three-node Incus Cluster | [Topology](/reference/incus-topologies) |\n| 0114 | Judge task completion as separate checks | [Tasks](/reference/tasks) |\n");
    file_put_contents($root.'/apps/docs/config/adr-retired-slugs.php', "<?php return ['0114' => ['{$incus}']];\n");
    expectAdrLifecycleLintFailure($root, 'Retired decision 0114 needs its exact source slug.');
    file_put_contents($root.'/apps/docs/config/adr-retired-slugs.php', "<?php return ['0114' => ['{$incus}', '{$tasks}']];\n");

    foreach ([$incus, $tasks] as $slug) {
        file_put_contents($root.'/docs/docs.json', json_encode(['redirects' => [
            ['source' => '/decisions/'.($slug === $incus ? $tasks : $incus), 'destination' => '/reference/tasks'],
        ]], JSON_THROW_ON_ERROR));
        expectAdrLifecycleLintFailure($root, "Retired decision 0114 has no redirect from /decisions/{$slug} in docs/docs.json.");
        file_put_contents($root.'/docs/decisions/'.$slug.'.md', "# Retired\n");
        expectAdrLifecycleLintFailure($root, "Retired decision {$slug} still has a file.");
        unlink($root.'/docs/decisions/'.$slug.'.md');
    }
});

it('adr lifecycle rejects new ADRs missing status or principle', function (): void {
    $root = adrLifecycleFixture();
    $path = $root.'/docs/decisions/0181-new-decision.md';
    file_put_contents($path, "# Decision\n\n## Status\n\nProposed.\n");
    expectAdrLifecycleLintFailure($root, 'ADR 0180+ must say In progress. in its Status section.');
    file_put_contents($path, "# Decision\n\n## Status\n\nIn progress.\n");
    expectAdrLifecycleLintFailure($root, 'ADR 0180+ must include a Principle: line in its Status section.');
    file_put_contents($path, "# Decision\n\n## Status\n\nIn progress.\n\nPrinciple: lean.\n");
    expect(new AdrLifecycle($root)->findings())->toBe([]);
});

it('adr lifecycle rejects an older unlisted ADR and an addition to the committed allowlist', function (): void {
    $root = adrLifecycleFixture();
    file_put_contents($root.'/docs/decisions/0175-unlisted.md', "# Legacy\n");
    expectAdrLifecycleLintFailure($root, 'Unretired legacy ADR 0175 is not in the allowlist.');
    file_put_contents($root.'/apps/docs/config/adr-legacy-allowlist.php', "<?php return ['0114', '0179'];\n");
    expectAdrLifecycleLintFailure($root, 'Legacy ADR allowlist cannot add 0179.');
});

it('adr lifecycle accepts a gap number that follows the in-progress rules', function (): void {
    $root = adrLifecycleFixture();
    $path = $root.'/docs/decisions/0176-outer-loop.md';
    file_put_contents($path, "# Decision\n\n## Status\n\nProposed.\n");
    expectAdrLifecycleLintFailure($root, 'ADR 0180+ must say In progress. in its Status section.');
    file_put_contents($path, "# Decision\n\n## Status\n\nIn progress.\n");
    expectAdrLifecycleLintFailure($root, 'ADR 0180+ must include a Principle: line in its Status section.');
    file_put_contents($path, "# Decision\n\n## Status\n\nIn progress.\n\nPrinciple: lean.\n");
    foreach (['0007', '0020'] as $gap) {
        file_put_contents($root."/docs/decisions/{$gap}-gap.md", "# Decision\n\n## Status\n\nIn progress.\n\nPrinciple: lean.\n");
    }
    expect(new AdrLifecycle($root)->findings())->toBe([]);
});

it('adr lifecycle refuses to reuse a retired number', function (): void {
    $root = adrLifecycleFixture();
    file_put_contents($root.'/docs/decisions/overview.mdx', "## Records\n\n## Retired decisions\n\n| Record | Decision | Now in |\n| --- | --- | --- |\n| 0114 | Expand the three-node Incus Cluster | [Topology](/reference/incus-topologies) |\n| 0010 | Record decisions before implementation issues | [Guide](/contributor-guide) |\n");
    file_put_contents($root.'/apps/docs/config/adr-retired-slugs.php', "<?php return ['0114' => ['0114-expand-the-three-node-incus-cluster'], '0010' => ['0010-record-decisions-before-implementation-issues']];\n");
    file_put_contents($root.'/docs/docs.json', json_encode(['redirects' => [
        ['source' => '/decisions/0114-expand-the-three-node-incus-cluster', 'destination' => '/reference/incus-topologies'],
        ['source' => '/decisions/0010-record-decisions-before-implementation-issues', 'destination' => '/contributor-guide'],
    ]], JSON_THROW_ON_ERROR));
    file_put_contents($root.'/docs/decisions/0010-new-slug.md', "# Decision\n\n## Status\n\nIn progress.\n\nPrinciple: lean.\n");
    expectAdrLifecycleLintFailure($root, 'Retired ADR number 0010 cannot be reused.');
});

it('adr lifecycle accepts independent allowlist removals merged from sibling branches', function (): void {
    $root = adrLifecycleFixture();
    foreach (['0051', '0052'] as $number) {
        file_put_contents($root.'/docs/decisions/'.$number.'-legacy.md', "# Legacy\n");
    }
    file_put_contents($root.'/apps/docs/config/adr-legacy-allowlist.php', "<?php return ['0114', '0051', '0052'];\n");
    exec('git -C '.escapeshellarg($root).' add .');
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm seeded');
    exec('git -C '.escapeshellarg($root).' branch other');
    file_put_contents($root.'/apps/docs/config/adr-legacy-allowlist.php', "<?php return ['0114', '0052'];\n");
    exec('git -C '.escapeshellarg($root).' add .');
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm remove-0051');
    exec('git -C '.escapeshellarg($root).' checkout -q other');
    file_put_contents($root.'/apps/docs/config/adr-legacy-allowlist.php', "<?php return ['0114', '0051'];\n");
    exec('git -C '.escapeshellarg($root).' add .');
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm remove-0052');
    exec('git -C '.escapeshellarg($root).' checkout -q main');
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test merge -q --no-commit --no-ff -s ours other > /dev/null', result_code: $mergeStatus);
    expect($mergeStatus)->toBe(0);
    file_put_contents($root.'/apps/docs/config/adr-legacy-allowlist.php', "<?php return ['0114'];\n");
    exec('git -C '.escapeshellarg($root).' add .');
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm merge-removals');
    exec('git -C '.escapeshellarg($root).' rev-list --parents -n 1 HEAD', $parents);
    expect(explode(' ', $parents[0]))->toHaveCount(3);

    expect(collect(new AdrLifecycle($root)->findings())->pluck('message')->all())->not->toContain(
        'Legacy ADR allowlist cannot add 0051.',
        'Legacy ADR allowlist cannot add 0052.',
    );
});

it('adr lifecycle rejects a committed side-branch restoration merged into an unchanged allowance', function (): void {
    $root = adrLifecycleFixture();
    exec('git -C '.escapeshellarg($root).' checkout -q -b side');
    file_put_contents($root.'/apps/docs/config/adr-legacy-allowlist.php', "<?php return [];\n");
    exec('git -C '.escapeshellarg($root).' add .');
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm shrink');
    file_put_contents($root.'/apps/docs/config/adr-legacy-allowlist.php', "<?php return ['0114'];\n");
    exec('git -C '.escapeshellarg($root).' add .');
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm restore');
    exec('git -C '.escapeshellarg($root).' checkout -q main');
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test merge -q --no-ff -m merged side > /dev/null');
    exec('git -C '.escapeshellarg($root).' rev-list --parents -n 1 HEAD', $parents);
    expect(explode(' ', $parents[0]))->toHaveCount(3);

    expectAdrLifecycleLintFailure($root, 'Legacy ADR allowlist cannot add 0114.');
    file_put_contents($root.'/apps/docs/config/adr-legacy-allowlist.php', "<?php return [];\n");
    exec('git -C '.escapeshellarg($root).' add .');
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm corrected');
    expect(collect(new AdrLifecycle($root)->findings())->pluck('message'))->not->toContain('Legacy ADR allowlist cannot add 0114.');
});

it('adr lifecycle refuses to restore an entry removed from the committed allowlist', function (): void {
    $root = adrLifecycleFixture();
    file_put_contents($root.'/apps/docs/config/adr-legacy-allowlist.php', "<?php return [];\n");
    exec('git -C '.escapeshellarg($root).' add .');
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm shrink');
    file_put_contents($root.'/apps/docs/config/adr-legacy-allowlist.php', "<?php return ['0114'];\n");
    exec('git -C '.escapeshellarg($root).' add .');
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm restore');
    expectAdrLifecycleLintFailure($root, 'Legacy ADR allowlist cannot add 0114.');
    file_put_contents($root.'/apps/docs/config/adr-legacy-allowlist.php', "<?php return [];\n");
    exec('git -C '.escapeshellarg($root).' add .');
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm corrected');
    expect(collect(new AdrLifecycle($root)->findings())->pluck('message'))->not->toContain('Legacy ADR allowlist cannot add 0114.');
});
