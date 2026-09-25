<?php

declare(strict_types=1);

use App\Infrastructure\AppDev\AppDevSite;
use App\Infrastructure\AppInstances\DevelopmentCaddyAccessCommand;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\LinuxContainer;

it('leaves out sites that have no checkout to grant access to', function (): void {
    $site = static fn (string $checkoutPath, ?string $localHttpUpstream = null): AppDevSite => new AppDevSite(
        nodeId: 1,
        nodeAddress: '10.44.0.7',
        scope: 'node-1',
        checkoutPath: $checkoutPath,
        documentRoot: 'public',
        phpVersion: '8.5',
        domain: 'example.test',
        localHttpUpstream: $localHttpUpstream,
    );

    $command = new DevelopmentCaddyAccessCommand()->command(collect([
        $site('/apps/served'),
        $site(''),
        $site('/apps/proxied', 'http://127.0.0.1:4788'),
    ]));

    expect($site('/apps/proxied', 'http://127.0.0.1:4788')->isProxy())->toBeTrue()
        ->and($command->arguments)->toContain('/apps/served')
        ->and($command->arguments)->not->toContain('/apps/proxied', '');
});

it('serves a nested Web root while protecting source and preserving shared parent modes', function (): void {
    if (LinuxContainer::delegate($this)) {
        return;
    }

    $root = development_caddy_access_fixture();

    try {
        $homeMode = fileperms($root) & 0o777;
        $sourceMode = fileperms("$root/checkout/.env") & 0o777;
        $command = new DevelopmentCaddyAccessCommand()->command(collect([development_caddy_access_site("$root/checkout", 'web/site')]));
        $process = new Process($command->arguments)->setInput($command->input);
        $process->mustRun();
        $process->mustRun();

        expect(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$root/checkout/web/site/index.html"])->mustRun()->getOutput())
            ->toBe('first page');
        foreach (['.env', 'source.txt', '.git/config', 'web/private.txt'] as $private) {
            expect(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$root/checkout/$private"])->run())
                ->toBe(1);
        }
        file_put_contents("$root/checkout/web/site/next.html", 'next page');
        expect(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$root/checkout/web/site/next.html"])->mustRun()->getOutput())
            ->toBe('next page');
        clearstatcache();
        expect(fileperms($root) & 0o007)->toBe($homeMode & 0o007);
        expect(fileperms("$root/checkout/.env") & 0o777)->toBe($sourceMode);
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('refuses unsafe Web root links before changing file access', function (string $kind): void {
    if (LinuxContainer::delegate($this)) {
        return;
    }

    $root = development_caddy_access_fixture();

    try {
        mkdir("$root/outside");
        file_put_contents("$root/outside/private", 'outside');
        if ($kind === 'root') {
            new Filesystem()->deleteDirectory("$root/checkout/web/site");
            symlink("$root/outside", "$root/checkout/web/site");
        } else {
            symlink("$root/outside/private", "$root/checkout/web/site/leak");
        }
        $before = new Process(['getfacl', '-cp', "$root/checkout", "$root/outside"])->mustRun()->getOutput();
        $command = new DevelopmentCaddyAccessCommand()->command(collect([development_caddy_access_site("$root/checkout", 'web/site')]));

        expect(new Process($command->arguments)->setInput($command->input)->run())->not->toBe(0);
        expect(new Process(['getfacl', '-cp', "$root/checkout", "$root/outside"])->mustRun()->getOutput())->toBe($before);
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
})->with(['root', 'descendant']);

it('permits only Laravel public storage without exposing private storage', function (): void {
    if (LinuxContainer::delegate($this)) {
        return;
    }

    $root = development_caddy_access_fixture();

    try {
        mkdir("$root/checkout/public");
        mkdir("$root/checkout/storage/app/public", 0o700, true);
        file_put_contents("$root/checkout/storage/app/public/photo.txt", 'public photo');
        file_put_contents("$root/checkout/storage/app/private.txt", 'private photo');
        symlink('../storage/app/public', "$root/checkout/public/storage");
        $command = new DevelopmentCaddyAccessCommand()->command(collect([development_caddy_access_site("$root/checkout", 'public')]));
        new Process($command->arguments)->setInput($command->input)->mustRun();

        expect(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$root/checkout/public/storage/photo.txt"])->mustRun()->getOutput())
            ->toBe('public photo');
        expect(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$root/checkout/storage/app/private.txt"])->run())
            ->toBe(1);
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('keeps both Web roots readable when a Git worktree is nested inside another checkout', function (): void {
    if (LinuxContainer::delegate($this)) {
        return;
    }

    $root = development_caddy_access_fixture();

    try {
        $nested = "$root/checkout/.worktrees/feature";
        mkdir("$nested/public", 0o700, true);
        new Process(['git', 'init', '--quiet', $nested])->mustRun();
        file_put_contents("$nested/public/index.html", 'nested page');
        file_put_contents("$nested/.env", 'nested secret');
        $command = new DevelopmentCaddyAccessCommand()->command(collect([
            development_caddy_access_site($nested, 'public'),
            development_caddy_access_site("$root/checkout", 'web/site'),
        ]));
        new Process($command->arguments)->setInput($command->input)->mustRun();

        expect(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$nested/public/index.html"])->mustRun()->getOutput())
            ->toBe('nested page');
        expect(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$root/checkout/web/site/index.html"])->mustRun()->getOutput())
            ->toBe('first page');
        expect(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$nested/.env"])->run())->toBe(1);
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('restores prior ACLs or retains its snapshot when recovery also fails', function (bool $failRecovery): void {
    if (LinuxContainer::delegate($this)) {
        return;
    }

    $root = development_caddy_access_fixture();

    try {
        mkdir("$root/bin");
        file_put_contents("$root/bin/setfacl", <<<'BASH'
            #!/bin/bash
            for argument in "$@"; do
                if [ "$argument" = 'u:caddy:r-X' ]; then exit 1; fi
            done
            exec /usr/bin/setfacl "$@"
            BASH);
        chmod("$root/bin/setfacl", 0o700);
        if ($failRecovery) {
            file_put_contents("$root/bin/sudo", <<<'BASH'
                #!/bin/bash
                for argument in "$@"; do
                    case "$argument" in --restore=*) exit 1 ;; esac
                done
                exec /usr/bin/sudo "$@"
                BASH);
            chmod("$root/bin/sudo", 0o700);
        }
        $before = new Process(['getfacl', '-R', '-p', $root])->mustRun()->getOutput();
        $command = new DevelopmentCaddyAccessCommand()->command(collect([
            development_caddy_access_site("$root/checkout", 'web/site'),
        ]));

        expect(new Process($command->arguments, env: ['PATH' => "$root/bin:".getenv('PATH'), 'TMPDIR' => $root])
            ->setInput($command->input)->run())->not->toBe(0);
        if ($failRecovery) {
            $snapshots = new Filesystem()->glob("$root/tmp.*");
            expect($snapshots)->toHaveCount(1);
            expect(fileperms($snapshots[0]) & 0o777)->toBe(0o600);
            new Process(['sudo', '-n', 'setfacl', '--restore='.$snapshots[0]])->mustRun();
            unlink($snapshots[0]);
        }
        expect(new Process(['getfacl', '-R', '-p', $root])->mustRun()->getOutput())->toBe($before);
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
})->with([false, true]);

function development_caddy_access_fixture(): string
{
    $root = sys_get_temp_dir().'/orbit-caddy-access-'.Str::uuid();
    mkdir("$root/checkout/web/site", 0o700, true);
    new Process(['git', 'init', '--quiet', "$root/checkout"])->mustRun();
    file_put_contents("$root/checkout/web/site/index.html", 'first page');
    file_put_contents("$root/checkout/.env", 'SECRET=value');
    file_put_contents("$root/checkout/source.txt", 'private source');
    file_put_contents("$root/checkout/web/private.txt", 'private sibling');

    return $root;
}

function development_caddy_access_site(string $checkout, string $webRoot): AppDevSite
{
    return new AppDevSite(1, '10.44.0.2', 'app-instance-1', $checkout, $webRoot, null, 'hello.alpha.test');
}
