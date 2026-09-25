<?php

declare(strict_types=1);

/*
 * Entry point of a private DNS listener release. The Gateway copies this file and the listener classes into
 * /var/lib/orbit/private-dns/releases/<id>/ on the Node that holds `vpn`. It needs no Composer autoloader. ADR 0149.
 *
 * serve.php --listen=ADDRESS --port=53 --catalog=PATH --upstream=HOST:PORT
 * serve.php --self-test   checks the PHP extensions, loads every class in the release, and exits 0
 */

spl_autoload_register(static function (string $class): void {
    if (! str_starts_with($class, 'App\\')) {
        return;
    }

    $path = __DIR__.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($path)) {
        require $path;
    }
});

$arguments = array_slice($argv, 1);

if ($arguments === ['--self-test']) {
    // The listener adopts systemd's sockets with ext-sockets and stops gracefully with ext-pcntl.
    foreach (['sockets', 'pcntl'] as $extension) {
        if (! extension_loaded($extension)) {
            fwrite(STDERR, "Missing PHP extension {$extension}.".PHP_EOL);
            exit(1);
        }
    }

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/app', FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $class = 'App\\'.str_replace('/', '\\', substr($file->getPathname(), strlen(__DIR__.'/app/'), -4));
        if (! class_exists($class) && ! interface_exists($class) && ! enum_exists($class) && ! trait_exists($class)) {
            fwrite(STDERR, "Missing {$class}.".PHP_EOL);
            exit(1);
        }
    }

    exit(0);
}

exit(new App\Infrastructure\AppDev\PrivateDnsListenerProcess()->run(
    App\Infrastructure\AppDev\PrivateDnsListenerProcess::options($arguments),
    STDERR,
));
