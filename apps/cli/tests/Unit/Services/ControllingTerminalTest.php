<?php

declare(strict_types=1);

use App\Exceptions\LocalNodeSetupException;
use App\Services\NodeSetup\ControllingTerminal;

it('rejects a missing controlling terminal', function (): void {
    expect(class_exists(ControllingTerminal::class))->toBeTrue();

    $terminal = new ControllingTerminal('/orbit-test/missing-tty');

    expect($terminal->isAvailable())->toBeFalse();
    expect(fn () => $terminal->open())
        ->toThrow(LocalNodeSetupException::class, 'A controlling terminal is required.');
});

it('writes the approved summary and requires an affirmative terminal response', function (): void {
    expect(class_exists(ControllingTerminal::class))->toBeTrue();

    $streams = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($streams)->toBeArray()->toHaveCount(2);
    [$commandStream, $peerStream] = $streams;
    fwrite($peerStream, "yes\n");

    $terminal = new ControllingTerminal(
        availabilityProbe: static fn (): bool => true,
        opener: static fn () => $commandStream,
    );

    expect($terminal->isAvailable())->toBeTrue();
    expect($terminal->confirm('Install the app-dev role on this Mac.'))->toBeTrue();

    stream_set_blocking($peerStream, false);
    expect(stream_get_contents($peerStream))
        ->toBe("Install the app-dev role on this Mac.\nContinue? [y/N] ");

    fclose($peerStream);
});

it('treats every non-affirmative terminal response as a decline', function (string $response): void {
    expect(class_exists(ControllingTerminal::class))->toBeTrue();

    $streams = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($streams)->toBeArray()->toHaveCount(2);
    [$commandStream, $peerStream] = $streams;
    fwrite($peerStream, $response."\n");

    $terminal = new ControllingTerminal(
        availabilityProbe: static fn (): bool => true,
        opener: static fn () => $commandStream,
    );

    expect($terminal->confirm('Safe summary'))->toBeFalse();

    fclose($peerStream);
})->with(['', 'no', 'unexpected']);
