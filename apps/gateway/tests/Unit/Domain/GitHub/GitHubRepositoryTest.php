<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitReadEnvironment;

describe('GitHubRepository', function (): void {
    it('reads the owner and name from each github.com origin form', function (string $origin): void {
        $repository = GitHubRepository::fromOrigin($origin);

        expect($repository)->not->toBeNull()
            ->and($repository?->owner)->toBe('acme')
            ->and($repository?->name)->toBe('shop.api');
    })->with([
        'https://github.com/acme/shop.api',
        'https://github.com/acme/shop.api.git',
        'https://github.com/acme/shop.api/',
        'git@github.com:acme/shop.api.git',
        'ssh://git@github.com/acme/shop.api.git',
    ]);

    it('ignores origins on other hosts and malformed paths', function (string $origin): void {
        expect(GitHubRepository::fromOrigin($origin))->toBeNull();
    })->with([
        'https://gitlab.com/acme/shop',
        'https://github.com.evil.test/acme/shop',
        'https://github.com/acme',
        'https://github.com/acme/shop/extra',
        'https://github.com/acme/..',
        'http://github.com/acme/shop',
        '/srv/git/shop.git',
    ]);
});

describe('GitReadEnvironment', function (): void {
    it('carries the token as Git configuration and rewrites SSH origins to HTTPS', function (): void {
        $environment = GitReadEnvironment::forGitHubToken('ghs_sentinel');

        expect($environment->variables)->toBe([
            'GIT_CONFIG_COUNT' => '3',
            'GIT_CONFIG_KEY_0' => 'http.https://github.com/.extraheader',
            'GIT_CONFIG_VALUE_0' => 'Authorization: Basic '.base64_encode('x-access-token:ghs_sentinel'),
            'GIT_CONFIG_KEY_1' => 'url.https://github.com/.insteadOf',
            'GIT_CONFIG_VALUE_1' => 'git@github.com:',
            'GIT_CONFIG_KEY_2' => 'url.https://github.com/.insteadOf',
            'GIT_CONFIG_VALUE_2' => 'ssh://git@github.com/',
        ]);
    });

    it('scopes the variables to the wrapped command', function (): void {
        $preamble = GitReadEnvironment::forGitHubToken('ghs_sentinel')->bashPreamble();
        $output = [];
        exec('bash -seu -c '.escapeshellarg(
            $preamble.'git_read printenv GIT_CONFIG_KEY_0; printenv GIT_CONFIG_COUNT || echo unset; echo "$git_read_sudo"',
        ), $output, $status);

        expect($status)->toBe(0)
            ->and($output)->toBe([
                'http.https://github.com/.extraheader',
                'unset',
                '--preserve-env=GIT_CONFIG_COUNT,GIT_CONFIG_KEY_0,GIT_CONFIG_VALUE_0,GIT_CONFIG_KEY_1,GIT_CONFIG_VALUE_1,GIT_CONFIG_KEY_2,GIT_CONFIG_VALUE_2',
            ]);
    });

    it('defines a pass-through wrapper when there is no token', function (): void {
        $output = [];
        exec('bash -seu -c '.escapeshellarg(
            GitReadEnvironment::none()->bashPreamble().'git_read echo read; echo "[$git_read_sudo]"',
        ), $output, $status);

        expect($status)->toBe(0)->and($output)->toBe(['read', '[]']);
    });

    it('hides the token from debug output', function (): void {
        expect(print_r(GitReadEnvironment::forGitHubToken('ghs_sentinel'), true))
            ->not->toContain('ghs_sentinel')
            ->not->toContain(base64_encode('x-access-token:ghs_sentinel'));
    });
});
