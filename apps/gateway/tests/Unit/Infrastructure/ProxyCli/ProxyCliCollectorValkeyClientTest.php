<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('returns a large Valkey bulk reply without hanging', function (): void {
    $script = <<<'PY'
import importlib.util
import socket
import sys
import threading

spec = importlib.util.spec_from_file_location("proxycli_server", sys.argv[1])
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

payload = '{"accounts":[]}' + ("x" * 65536)
reply = f"${len(payload)}\r\n{payload}\r\n".encode()
server = socket.socket()
server.bind(("127.0.0.1", 0))
server.listen(1)
host, port = server.getsockname()

def serve() -> None:
    connection, _ = server.accept()
    connection.recv(4096)
    connection.sendall(b"+OK\r\n")
    connection.recv(4096)
    connection.sendall(reply)
    connection.close()
    server.close()

threading.Thread(target=serve, daemon=True).start()
result = module.Valkey(host, port, "", "secret").get("orbit:proxycli:snapshot")
if result != payload:
    raise SystemExit("unexpected bulk")
print("ok")
PY;

    $process = new Process([
        '/usr/bin/python3',
        '-c',
        $script,
        dirname(__DIR__, 4).'/resources/proxycli/server.py',
    ]);
    $process->setTimeout(5);
    $process->run();

    expect($process->isSuccessful())->toBeTrue()
        ->and(trim($process->getOutput()))->toBe('ok')
        ->and($process->getErrorOutput())->toBe('');
});
