<?php

declare(strict_types=1);

use Orbit\Sdk\Requests\GitHub\DestroyGitHubAppRequest;
use Orbit\Sdk\Requests\GitHub\InstallGitHubAppRequest;
use Orbit\Sdk\Requests\GitHub\ShowGitHubAppRequest;
use Saloon\Enums\Method;

it('exposes the three GitHub App routes', function (string $class, Method $method, string $path): void {
    $request = new $class;

    expect($request->getMethod())->toBe($method)->and($request->resolveEndpoint())->toBe($path);
})->with([
    [InstallGitHubAppRequest::class, Method::POST,   '/api/v1/github/app/install'],
    [ShowGitHubAppRequest::class,    Method::GET,    '/api/v1/github/app'],
    [DestroyGitHubAppRequest::class, Method::DELETE, '/api/v1/github/app'],
]);

it('sends only the supplied install fields', function (): void {
    expect(new InstallGitHubAppRequest('orbit-acme', 'acme')->body()->all())
        ->toBe('{"name":"orbit-acme","owner":"acme"}')
        ->and(new InstallGitHubAppRequest(name: 'orbit')->body()->all())
        ->toBe('{"name":"orbit"}')
        ->and(new InstallGitHubAppRequest(owner: 'acme')->body()->all())
        ->toBe('{"owner":"acme"}');
});

it('sends an empty JSON object when no install field is supplied', function (): void {
    $request = new InstallGitHubAppRequest;

    expect($request->body()->all())
        ->toBe('{}')
        ->and($request->headers()->get('Content-Type'))
        ->toBe('application/json');
});
