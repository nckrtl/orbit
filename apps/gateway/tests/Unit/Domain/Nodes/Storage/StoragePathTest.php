<?php

declare(strict_types=1);

use App\Domain\Nodes\Storage\StoragePath;

it('parses a normalized absolute path and compares directory boundaries', function (): void {
    $root = StoragePath::parse('/srv/orbit/instances');
    $child = StoragePath::parse('/srv/orbit/instances/acme');
    $other = StoragePath::parse('/srv/orbit-extra');

    expect($root->contains($child))
        ->toBeTrue()
        ->and($root->overlaps($child))
        ->toBeTrue()
        ->and($root->overlaps($other))
        ->toBeFalse()
        ->and($child->stripSuffix('acme')->value)
        ->toBe('/srv/orbit/instances');
});

it('rejects paths that are not normalized absolute directories', function (string $path): void {
    expect(StoragePath::tryParse($path))->toBeNull();
})->with([
    'relative' => 'srv/orbit',
    'root' => '/',
    'trailing separator' => '/srv/orbit/',
    'repeated separator' => '/srv//orbit',
    'dot segment' => '/srv/./orbit',
    'parent segment' => '/srv/../orbit',
    'empty' => '',
]);
