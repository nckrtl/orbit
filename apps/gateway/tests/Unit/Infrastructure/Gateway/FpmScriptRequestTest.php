<?php

declare(strict_types=1);

use App\Infrastructure\Gateway\FpmScriptRequest;

/** A FastCGI record as PHP-FPM writes it. */
function fastcgi_record(int $type, string $content): string
{
    return pack('CCnnCx', 1, $type, 1, strlen($content), 0).$content;
}

describe(FpmScriptRequest::class, function (): void {
    it('sends one FastCGI request for the script and returns the body of the answer', function (): void {
        [$client, $pool] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($pool, fastcgi_record(6, "Content-Type: application/json\r\n\r\n{\"reset\":true}").fastcgi_record(3, pack('NCx3', 0, 0)));

        $body = new FpmScriptRequest(connect: static fn () => $client)->request('/home/orbit/releases/0123456789ab/apps/gateway/resources/fpm/opcache-reset.php', 'status=1');
        $request = stream_get_contents($pool);

        expect($body)->toBe('{"reset":true}')
            ->and(ord($request[1]))->toBe(1)
            ->and($request)->toContain('SCRIPT_FILENAME/home/orbit/releases/0123456789ab/apps/gateway/resources/fpm/opcache-reset.php')
            ->and($request)->toContain('QUERY_STRINGstatus=1')
            ->and(substr($request, -8))->toBe(fastcgi_record(5, ''));
    });

    it('fails on an error status from the pool', function (): void {
        [$client, $pool] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($pool, fastcgi_record(6, "Status: 404 Not Found\r\nContent-Type: text/html\r\n\r\nFile not found.").fastcgi_record(3, pack('NCx3', 0, 0)));

        expect(fn () => new FpmScriptRequest(connect: static fn () => $client)->request('/missing.php'))
            ->toThrow(RuntimeException::class, '404');
    });
});
