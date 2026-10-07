<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Infrastructure\GatewayReleases\HttpGatewayReleaseVerifier;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;

it('accepts an up Gateway whose status version is the release commit', function (): void {
    $sha = str_repeat('a', 40);
    $verifier = new HttpGatewayReleaseVerifier(new ScriptedReleaseHttp([
        new CommandResult(0, 'ok', '', 1, false),
        new CommandResult(0, json_encode(['data' => ['status' => 'ok', 'version' => $sha]], JSON_THROW_ON_ERROR), '', 1, false),
    ]), 'https://gateway.test', '/no/such/ca.pem');

    expect($verifier->verify($sha))->toBe(['status' => 'ok', 'version' => $sha]);
});

it('refuses a status version that is not the release commit', function (): void {
    $verifier = new HttpGatewayReleaseVerifier(new ScriptedReleaseHttp([
        new CommandResult(0, 'ok', '', 1, false),
        new CommandResult(0, json_encode(['data' => ['status' => 'ok', 'version' => 'dev']], JSON_THROW_ON_ERROR), '', 1, false),
    ]), 'https://gateway.test', '/no/such/ca.pem', backoff: []);

    expect(fn () => $verifier->verify(str_repeat('b', 40)))
        ->toThrow(GatewayReleaseException::class, 'dev');
});

it('refuses a version that is only a prefix of the commit, such as a hand-set APP_VERSION', function (string $version): void {
    $sha = '1'.str_repeat('a', 39);
    $verifier = new HttpGatewayReleaseVerifier(new ScriptedReleaseHttp([
        new CommandResult(0, 'ok', '', 1, false),
        new CommandResult(0, json_encode(['data' => ['status' => 'ok', 'version' => $version]], JSON_THROW_ON_ERROR), '', 1, false),
    ]), 'https://gateway.test', '/no/such/ca.pem', backoff: []);

    expect(fn () => $verifier->verify($sha))->toThrow(GatewayReleaseException::class);
})->with(['1', '1aaaaaa', '1aaaaaaaaaaaa']);

it('accepts the 12-digit release id as the version', function (): void {
    $sha = str_repeat('c', 40);
    $verifier = new HttpGatewayReleaseVerifier(new ScriptedReleaseHttp([
        new CommandResult(0, 'ok', '', 1, false),
        new CommandResult(0, json_encode(['data' => ['status' => 'ok', 'version' => substr($sha, 0, 12)]], JSON_THROW_ON_ERROR), '', 1, false),
    ]), 'https://gateway.test', '/no/such/ca.pem');

    expect($verifier->verify($sha)['version'])->toBe(substr($sha, 0, 12));
});

it('tries a failed check again with backoff before it fails', function (): void {
    $sha = str_repeat('d', 40);
    $slept = [];
    $verifier = new HttpGatewayReleaseVerifier(new ScriptedReleaseHttp([
        new CommandResult(28, '', 'curl: (28) Operation timed out', 1, false),
        new CommandResult(28, '', 'curl: (28) Operation timed out', 1, false),
        new CommandResult(0, 'ok', '', 1, false),
        new CommandResult(0, json_encode(['data' => ['status' => 'ok', 'version' => $sha]], JSON_THROW_ON_ERROR), '', 1, false),
    ]), 'https://gateway.test', '/no/such/ca.pem', backoff: [2, 4, 8], sleep: static function (int $seconds) use (&$slept): void {
        $slept[] = $seconds;
    });

    expect($verifier->verify($sha)['status'])->toBe('ok')
        ->and($slept)->toBe([2, 4]);
});

final class ScriptedReleaseHttp implements ProcessRunner
{
    /** @param list<CommandResult> $results */
    public function __construct(private array $results) {}

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $result = array_shift($this->results);

        if (! $result instanceof CommandResult) {
            throw new RuntimeException('No scripted HTTP result.');
        }

        return $result;
    }
}
