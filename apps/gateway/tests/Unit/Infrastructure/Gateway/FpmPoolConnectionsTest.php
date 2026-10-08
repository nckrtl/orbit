<?php

declare(strict_types=1);

use App\Infrastructure\Gateway\FpmPoolConnections;

it('counts connections on the unix and TCP listen sockets of every enabled pool, and nothing else', function (): void {
    $root = sys_get_temp_dir().'/orbit-fpm-connections-'.bin2hex(random_bytes(4));
    mkdir($root.'/pool.d', 0700, true);
    mkdir($root.'/net', 0700, true);
    file_put_contents($root.'/pool.d/orbit-gateway.conf', "[orbit-gateway]\nlisten = /run/php/orbit-gateway.sock\n");
    file_put_contents($root.'/pool.d/other.conf', "[other]\nlisten = 127.0.0.1:9000\n");
    file_put_contents($root.'/pool.d/www.conf.disabled', "[www]\nlisten = /run/php/www.sock\n");
    file_put_contents($root.'/net/unix', implode("\n", [
        'Num       RefCount Protocol Flags    Type St Inode Path',
        '0000000000000000: 00000002 00000000 00010000 0001 01 1001 /run/php/orbit-gateway.sock',
        '0000000000000000: 00000003 00000000 00000000 0001 03 1002 /run/php/orbit-gateway.sock',
        '0000000000000000: 00000003 00000000 00000000 0001 03 1003 /run/php/orbit-gateway.sock',
        '0000000000000000: 00000003 00000000 00000000 0001 03 1004 /run/php/www.sock',
        '0000000000000000: 00000003 00000000 00000000 0001 03 1005',
    ])."\n");
    file_put_contents($root.'/net/tcp', implode("\n", [
        '  sl  local_address rem_address   st tx_queue rx_queue tr tm->when retrnsmt   uid  timeout inode',
        '   0: 0100007F:2328 00000000:0000 0A 00000000:00000000 00:00000000 00000000  1000        0 1',
        '   1: 0100007F:2328 0100007F:D431 01 00000000:00000000 00:00000000 00000000  1000        0 2',
        '   2: 0100007F:1F90 0100007F:D432 01 00000000:00000000 00:00000000 00000000  1000        0 3',
    ])."\n");
    file_put_contents($root.'/net/tcp6', "  sl  local_address remote_address st\n");

    try {
        $count = new FpmPoolConnections($root.'/pool.d', $root.'/net')->count();
    } finally {
        exec('rm -rf '.escapeshellarg($root));
    }

    // Two connected on the Gateway socket, one established on port 9000 (0x2328); not the listener, the disabled pool, or port 8080.
    expect($count)->toBe(3);
});
