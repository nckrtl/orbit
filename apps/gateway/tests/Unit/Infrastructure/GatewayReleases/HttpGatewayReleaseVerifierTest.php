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
    ]), 'https://gateway.test', '/no/such/ca.pem');

    expect(fn () => $verifier->verify(str_repeat('b', 40)))
        ->toThrow(GatewayReleaseException::class, 'dev');
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
