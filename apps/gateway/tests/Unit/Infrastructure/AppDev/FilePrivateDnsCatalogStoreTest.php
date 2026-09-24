<?php

declare(strict_types=1);

use App\Domain\AppDev\DnsQuestion;
use App\Domain\AppDev\DnsRecordType;
use App\Domain\AppDev\DnsRequester;
use App\Infrastructure\AppDev\FilePrivateDnsCatalogStore;
use App\Infrastructure\AppDev\InMemoryPrivateDnsAnswerCache;
use Illuminate\Filesystem\Filesystem;

it('loads published requesters and overrides then keeps the last valid catalog after a broken republish', function (): void {
    $root = sys_get_temp_dir().'/orbit-catalog-store-'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    $files->makeDirectory($root, 0755, true);
    $path = $root.'/catalog.json';
    $cache = new InMemoryPrivateDnsAnswerCache;
    $valid = json_encode([
        'requesters' => ['10.44.0.10' => 12],
        'records' => ['commander.test' => '10.44.0.7'],
        'suffixes' => ['test' => '10.44.0.7'],
        'overrides' => ['node:12' => ['commander.test' => '192.168.6.20']],
    ], JSON_THROW_ON_ERROR);

    try {
        $files->put($path, $valid);
        $store = new FilePrivateDnsCatalogStore($path, $cache);
        $eligible = DnsRequester::registered(12, '10.44.0.10');

        expect($store->requesters()->resolve('10.44.0.10')->nodeId)
            ->toBe(12)
            ->and($store->catalog()->addressFor(new DnsQuestion('commander.test', DnsRecordType::A), $eligible))
            ->toBe('192.168.6.20');

        $files->put($path, '{not-json');
        touch($path, time() + 2);
        expect($store->refresh())
            ->toBeFalse()
            ->and($store->catalog()->addressFor(new DnsQuestion('commander.test', DnsRecordType::A), $eligible))
            ->toBe('192.168.6.20');

        $updated = json_encode([
            'requesters' => ['10.44.0.10' => 12],
            'records' => ['commander.test' => '10.44.0.7'],
            'suffixes' => [],
            'overrides' => ['node:12' => ['commander.test' => '192.168.6.21']],
        ], JSON_THROW_ON_ERROR);
        $files->put($path, $updated);
        touch($path, time() + 4);

        expect($store->refresh())
            ->toBeTrue()
            ->and($store->catalog()->addressFor(new DnsQuestion('commander.test', DnsRecordType::A), $eligible))
            ->toBe('192.168.6.21');
    } finally {
        $files->deleteDirectory($root);
    }
});

it('reloads an atomic replacement with the same timestamp and byte count without caller cache clearing', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'orbit-dns-catalog-');
    $question = new DnsQuestion('sample.orbit', DnsRecordType::A);
    $requester = DnsRequester::unidentified('10.44.0.9');
    try {
        file_put_contents($path, json_encode(['records' => ['sample.orbit' => '10.44.0.2']], JSON_THROW_ON_ERROR));
        $timestamp = filemtime($path);
        $store = new FilePrivateDnsCatalogStore($path);
        expect($store->catalog()->addressFor($question, $requester))->toBe('10.44.0.2');
        file_put_contents($path.'.next', json_encode(['records' => ['sample.orbit' => '10.44.0.1']], JSON_THROW_ON_ERROR));
        touch($path.'.next', $timestamp);
        rename($path.'.next', $path);
        expect($store->refresh())->toBeTrue()
            ->and($store->catalog()->addressFor($question, $requester))->toBe('10.44.0.1')
            ->and($store->refresh())->toBeFalse();
    } finally {
        @unlink($path.'.next');
        unlink($path);
    }
});

it('reloads a replacement that keeps the inode, timestamp, and byte count', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'orbit-dns-catalog-');
    $question = new DnsQuestion('sample.orbit', DnsRecordType::A);
    $requester = DnsRequester::unidentified('10.44.0.9');
    try {
        file_put_contents($path, json_encode(['records' => ['sample.orbit' => '10.44.0.3']], JSON_THROW_ON_ERROR));
        $timestamp = filemtime($path);
        $inode = fileinode($path);
        $store = new FilePrivateDnsCatalogStore($path);
        expect($store->catalog()->addressFor($question, $requester))->toBe('10.44.0.3');

        // Two publications in one second reused the inode on a Gateway; only the content differs.
        file_put_contents($path, json_encode(['records' => ['sample.orbit' => '10.44.0.1']], JSON_THROW_ON_ERROR));
        touch($path, $timestamp);
        clearstatcache(true, $path);

        expect(fileinode($path))->toBe($inode)
            ->and($store->refresh())->toBeTrue()
            ->and($store->catalog()->addressFor($question, $requester))->toBe('10.44.0.1')
            ->and($store->refresh())->toBeFalse();
    } finally {
        unlink($path);
    }
});
