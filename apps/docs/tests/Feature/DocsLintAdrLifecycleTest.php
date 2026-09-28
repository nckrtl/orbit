<?php

declare(strict_types=1);

use App\Documentation\AdrLifecycle;
use Illuminate\Support\Facades\Artisan;

function adrLifecycleFixture(): string
{
    $root = sys_get_temp_dir().'/orbit-adr-lifecycle-'.bin2hex(random_bytes(8));
    mkdir($root.'/docs/decisions', 0777, true);
    mkdir($root.'/apps/docs/config', 0777, true);
    file_put_contents($root.'/docs/decisions/overview.mdx', "## Records\n\n## Retired decisions\n\n| Record | Decision | Now in |\n| --- | --- | --- |\n| 0114 | Expand the three-node Incus Cluster | [Topology](/reference/incus-topologies) |\n");
    file_put_contents($root.'/docs/docs.json', json_encode(['redirects' => [
        ['source' => '/decisions/0114-expand-the-three-node-incus-cluster', 'destination' => '/reference/incus-topologies'],
    ]], JSON_THROW_ON_ERROR));
    file_put_contents($root.'/apps/docs/config/adr-legacy-allowlist.php', "<?php return ['0114'];\n");
    file_put_contents($root.'/apps/docs/config/adr-retired-slugs.php', "<?php return ['0114' => '0114-expand-the-three-node-incus-cluster'];\n");
    file_put_contents($root.'/docs/decisions/0114-judge-task-completion-as-separate-checks.md', "# Tasks\n");
    exec('git -C '.escapeshellarg($root).' init -q -b main');
    exec('git -C '.escapeshellarg($root).' add .');
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm fixture');

    return $root;
}

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
    file_put_contents($root.'/docs/decisions/0179-unlisted.md', "# Legacy\n");
    expectAdrLifecycleLintFailure($root, 'Unretired legacy ADR 0179 is not in the allowlist.');
    file_put_contents($root.'/apps/docs/config/adr-legacy-allowlist.php', "<?php return ['0114', '0179'];\n");
    expectAdrLifecycleLintFailure($root, 'Legacy ADR allowlist cannot add 0179.');
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
    exec('git -C '.escapeshellarg($root).' merge -q --no-commit --no-ff -s ours other > /dev/null');
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
