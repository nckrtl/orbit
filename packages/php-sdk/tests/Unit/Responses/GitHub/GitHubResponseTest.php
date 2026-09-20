<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Responses\GitHub\GitHubAppInstallResponse;
use Orbit\Sdk\Responses\GitHub\GitHubAppResponse;

function github_app_payload(array $overrides = []): array
{
    return [
        'name' => 'orbit-acme',
        'slug' => 'orbit-acme',
        'app_id' => 4242,
        'owner' => 'acme',
        'url' => 'https://github.com/apps/orbit-acme',
        'settings_url' => 'https://github.com/organizations/acme/settings/apps/orbit-acme',
        'installations' => [[
            'id' => 9001,
            'account' => 'acme',
            'type' => 'organization',
            'repositories' => 'selected',
            'suspended' => false,
        ]],
        ...$overrides,
    ];
}

it('maps the GitHub App and its installations', function (): void {
    $response = GitHubAppResponse::fromGatewayData(github_app_payload(), 'req');

    expect($response->name)->toBe('orbit-acme')
        ->and($response->appId)->toBe(4242)
        ->and($response->settingsUrl)->toBe('https://github.com/organizations/acme/settings/apps/orbit-acme')
        ->and($response->installations)->toBe([[
            'id' => 9001,
            'account' => 'acme',
            'type' => 'organization',
            'repositories' => 'selected',
            'suspended' => false,
        ]])
        ->and($response->toArray())->toBe([...github_app_payload(), 'request_id' => 'req']);
});

it('maps a GitHub App without installations', function (): void {
    expect(GitHubAppResponse::fromGatewayData(github_app_payload(['installations' => []]), 'req')->installations)
        ->toBe([]);
});

it('rejects a malformed GitHub App', function (array $overrides): void {
    expect(static fn (): GitHubAppResponse => GitHubAppResponse::fromGatewayData(github_app_payload($overrides), 'req'))
        ->toThrow(GatewayApiException::class);
})->with([
    'empty name' => [['name' => '']],
    'string app id' => [['app_id' => '4242']],
    'zero app id' => [['app_id' => 0]],
    'missing owner' => [['owner' => null]],
    'non-http url' => [['url' => 'javascript:alert(1)']],
    'missing settings url' => [['settings_url' => null]],
    'missing installations' => [['installations' => null]],
    'keyed installations' => [['installations' => ['a' => []]]],
    'unknown installation type' => [['installations' => [['id' => 1, 'account' => 'acme', 'type' => 'bot', 'repositories' => 'all', 'suspended' => false]]]],
    'unknown repository selection' => [['installations' => [['id' => 1, 'account' => 'acme', 'type' => 'user', 'repositories' => 'none', 'suspended' => false]]]],
    'non-boolean suspended' => [['installations' => [['id' => 1, 'account' => 'acme', 'type' => 'user', 'repositories' => 'all', 'suspended' => 0]]]],
    'scalar installation' => [['installations' => ['acme']]],
]);

it('keeps the request ID on a malformed GitHub App', function (): void {
    try {
        GitHubAppResponse::fromGatewayData(github_app_payload(['slug' => null]), '11111111-1111-4111-8111-111111111111');
    } catch (GatewayApiException $exception) {
        expect($exception->requestId())->toBe('11111111-1111-4111-8111-111111111111');

        return;
    }

    $this->fail('Expected a GatewayApiException.');
});

it('maps both install steps', function (string $step, array $accounts): void {
    $response = GitHubAppInstallResponse::fromGatewayData([
        'step' => $step,
        'url' => 'https://10.44.0.1/api/v1/github/app/register?state=abc',
        'accounts' => $accounts,
    ], 'req');

    expect($response->toArray())->toBe([
        'step' => $step,
        'url' => 'https://10.44.0.1/api/v1/github/app/register?state=abc',
        'accounts' => $accounts,
        'request_id' => 'req',
    ]);
})->with([
    ['register', []],
    ['install', ['acme', 'nckrtl']],
]);

it('rejects a malformed install step', function (array $data): void {
    expect(static fn (): GitHubAppInstallResponse => GitHubAppInstallResponse::fromGatewayData($data, 'req'))
        ->toThrow(GatewayApiException::class);
})->with([
    'unknown step' => [['step' => 'done', 'url' => 'https://github.com/apps/orbit', 'accounts' => []]],
    'missing url' => [['step' => 'install', 'accounts' => []]],
    'non-http url' => [['step' => 'install', 'url' => 'file:///etc/passwd', 'accounts' => []]],
    'missing accounts' => [['step' => 'install', 'url' => 'https://github.com/apps/orbit']],
    'keyed accounts' => [['step' => 'install', 'url' => 'https://github.com/apps/orbit', 'accounts' => ['a' => 'acme']]],
    'non-string account' => [['step' => 'install', 'url' => 'https://github.com/apps/orbit', 'accounts' => [7]]],
]);
