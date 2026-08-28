<?php

declare(strict_types=1);

use App\E2E\RefreshRequestStore;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;

describe('RefreshRequestStore', function () {
    it('coalesces refresh requests to the newest SHA and clears only that request', function () {
        $store = new RefreshRequestStore(new AtomicJsonStore(
            new StatePaths(sys_get_temp_dir().'/orbit-request-'.bin2hex(random_bytes(4))),
        ));
        $older = str_repeat('a', 40);
        $newest = str_repeat('b', 40);

        $store->request($older);
        $store->request($newest);

        expect($store->pending())->toBe($newest);
        $store->clear($older);
        expect($store->pending())->toBe($newest);
        $store->clear($newest);
        expect($store->pending())->toBeNull();
    });
});
