<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubRepository;
use App\Domain\Tasks\TaskReviewTrust;

it('authorizes only the named repository and positive numeric accounts', function (): void {
    $repository = GitHubRepository::fromOrigin('https://github.com/acme/orbit.git');
    $trust = TaskReviewTrust::fromConfig($repository, ['acme/orbit' => [42, 7, 42], 'other/orbit' => [99]]);

    expect($trust->valid)->toBeTrue();
    expect($trust->accountIds)->toBe([7, 42]);
    expect(TaskReviewTrust::fromConfig($repository, ['other/orbit' => [42]])->accountIds)->toBe([]);
    expect(TaskReviewTrust::fromConfig($repository, ['acme/orbit' => [7, 42]])->revision)->toBe($trust->revision);
    expect(TaskReviewTrust::fromConfig($repository, ['acme/orbit' => [7]])->revision)->not->toBe($trust->revision);
});

it('fails closed on malformed operator trust without converting names or roles', function (mixed $config): void {
    $trust = TaskReviewTrust::fromConfig(GitHubRepository::fromOrigin('https://github.com/acme/orbit.git'), $config);

    expect($trust->valid)->toBeFalse();
    expect($trust->accountIds)->toBe([]);
})->with([
    'not a map' => [null],
    'string config' => ['42'],
    'wildcard repository' => [['acme/*' => [42]]],
    'wildcard owner' => [['*/orbit' => [42]]],
    'wildcard global' => [['*' => [42]]],
    'case alias' => [['Acme/orbit' => [42]]],
    'url scope' => [['https://github.com/acme/orbit' => [42]]],
    'login' => [['acme/orbit' => ['reviewer-renamed']]],
    'numeric string' => [['acme/orbit' => ['42']]],
    'role' => [['acme/orbit' => ['admin']]],
    'association' => [['acme/orbit' => ['MEMBER']]],
    'wildcard account' => [['acme/orbit' => ['*']]],
    'zero' => [['acme/orbit' => [0]]],
    'negative' => [['acme/orbit' => [-1]]],
    'float' => [['acme/orbit' => [42.0]]],
    'boolean' => [['acme/orbit' => [true]]],
    'mixed valid and invalid' => [['acme/orbit' => [42, 'admin']]],
    'null repository entry' => [['acme/orbit' => null]],
    'account map' => [['acme/orbit' => ['id' => 42]]],
]);

it('isolates valid repository trust from malformed unrelated scopes', function (string|int $scope): void {
    $repository = GitHubRepository::fromOrigin('https://github.com/acme/orbit.git');
    $trust = TaskReviewTrust::fromConfig($repository, ['acme/orbit' => [42], 'acme/widgets' => [7], $scope => [99]]);

    expect($trust->valid)->toBeTrue();
    expect($trust->accountIds)->toBe([42]);
    expect($trust->revision)->toBe(TaskReviewTrust::fromConfig($repository, ['acme/orbit' => [42]])->revision);
})->with(['Other/widgets', 'other/widgets.git', 'https://github.com/other/widgets', 'other/*', '*/widgets', 'garbage', 42]);

it('disables only the repository named by an invalid alias', function (): void {
    $config = ['acme/orbit' => [42], 'other/widgets' => [7], 'Other/widgets' => [7]];

    expect(TaskReviewTrust::fromConfig(GitHubRepository::fromOrigin('https://github.com/acme/orbit.git'), $config)->accountIds)->toBe([42]);
    expect(TaskReviewTrust::fromConfig(GitHubRepository::fromOrigin('https://github.com/other/widgets.git'), $config)->valid)->toBeFalse();
});

it('rejects relevant alias and wildcard scopes even alongside a valid named entry', function (string $scope): void {
    $trust = TaskReviewTrust::fromConfig(GitHubRepository::fromOrigin('https://github.com/acme/orbit.git'), ['acme/orbit' => [42], $scope => [7]]);

    expect($trust->valid)->toBeFalse();
    expect($trust->accountIds)->toBe([]);
})->with(['Acme/orbit', 'acme/orbit.git', 'https://github.com/acme/orbit', 'acme/*', '*/orbit', '*']);

it('keeps absent and revoked trust valid but empty and isolates repository account lists', function (): void {
    $repository = GitHubRepository::fromOrigin('https://github.com/acme/orbit.git');

    expect(TaskReviewTrust::fromConfig($repository, [])->accountIds)->toBe([]);
    expect(TaskReviewTrust::fromConfig($repository, ['acme/orbit' => []])->valid)->toBeTrue();
    expect(TaskReviewTrust::fromConfig($repository, ['other/orbit' => ['invalid'], 'acme/orbit' => [42]])->accountIds)->toBe([42]);
});
