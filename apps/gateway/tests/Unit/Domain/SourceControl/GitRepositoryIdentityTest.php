<?php

declare(strict_types=1);

use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Models\App as OrbitApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('derives one identity from equivalent supported repository origins', function (string $repository): void {
    expect(GitRepositoryIdentity::derive($repository))->toBe('github.com/acme/site');
})->with([
    'scp-like SSH with suffix' => ['git@github.com:acme/site.git'],
    'SSH URL with suffix' => ['ssh://git@github.com/acme/site.git'],
    'SSH URL with another user' => ['ssh://deploy@github.com/acme/site'],
    'SSH URL with a port' => ['ssh://git@github.com:2222/acme/site.git'],
    'HTTPS URL with suffix' => ['https://github.com/acme/site.git'],
    'HTTPS URL without suffix' => ['https://github.com/acme/site'],
    'HTTPS URL with a port' => ['https://github.com:8443/acme/site.git'],
    'case-insensitive host' => ['https://GITHUB.COM/acme/site.git'],
]);

it('keeps different repository hosts and paths distinct', function (
    string $repository,
    string $identity,
): void {
    expect(GitRepositoryIdentity::derive($repository))->toBe($identity);
})->with([
    'different host' => ['https://gitlab.com/acme/site.git', 'gitlab.com/acme/site'],
    'different path' => ['https://github.com/acme/other.git', 'github.com/acme/other'],
    'case-sensitive path' => ['https://github.com/Acme/site.git', 'github.com/Acme/site'],
]);

it('finds at most one App from equivalent checkout origins', function (
    string $storedRepository,
    string $checkoutOrigin,
): void {
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => $storedRepository,
        'main_branch' => 'main',
        'root' => 'public',
    ]);

    expect(OrbitApp::findByRepositoryOrigin($checkoutOrigin))
        ->not->toBeNull()
        ->id->toBe($app->id)->and(OrbitApp::findByRepositoryOrigin('https://github.com/acme/missing.git'))->toBeNull();
})->with([
    'SSH-created and HTTPS checkout' => [
        'git@github.com:acme/site.git',
        'https://github.com/acme/site',
    ],
    'HTTPS-created and SSH checkout' => [
        'https://github.com/acme/site.git',
        'ssh://deploy@github.com/acme/site.git',
    ],
]);

it('uses a bounded error for an invalid credential-bearing origin', function (): void {
    $credential = 'repository-identity-secret';
    $repository = "ssh://git:{$credential}@example.test/acme/site.git";
    $exception = null;

    try {
        OrbitApp::findByRepositoryOrigin($repository);
    } catch (\InvalidArgumentException $caught) {
        $exception = $caught;
    }

    $debugOutput = print_r([
        'message' => $exception?->getMessage(),
        'trace' => $exception?->getTrace(),
    ], return: true);

    expect($exception)
        ->toBeInstanceOf(\InvalidArgumentException::class)
        ->and($exception?->getMessage())
        ->toBe('The Git repository origin is invalid.')
        ->and($debugOutput)
        ->not->toContain($credential, $repository);
});
