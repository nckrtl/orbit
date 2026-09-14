<?php

declare(strict_types=1);

use App\Domain\DatabaseConnections\DockerPublishedPort;

it('parses Docker published port mappings', function (string $spec, int $published, int $container, ?string $bind): void {
    $mapping = DockerPublishedPort::parse($spec);

    expect($mapping)
        ->toBeInstanceOf(DockerPublishedPort::class)
        ->and($mapping?->publishedPort)
        ->toBe($published)
        ->and($mapping?->containerPort)
        ->toBe($container)
        ->and($mapping?->bindAddress)
        ->toBe($bind)
        ->and($mapping?->matches($published))
        ->toBeTrue()
        ->and($mapping?->matches($container))
        ->toBeTrue();
})->with([
    'loopback' => ['127.0.0.1:3307:3306/tcp', 3307, 3306, '127.0.0.1'],
    'unbound' => ['3307:3306/tcp', 3307, 3306, null],
    'wireguard' => ['10.44.0.8:5433:5432', 5433, 5432, '10.44.0.8'],
]);
