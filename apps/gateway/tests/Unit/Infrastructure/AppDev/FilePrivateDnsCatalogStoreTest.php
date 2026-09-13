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
        clearstatcache(true, $path);
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
        clearstatcache(true, $path);

        expect($store->refresh())
            ->toBeTrue()
            ->and($store->catalog()->addressFor(new DnsQuestion('commander.test', DnsRecordType::A), $eligible))
            ->toBe('192.168.6.21');
    } finally {
        $files->deleteDirectory($root);
    }
});
