<?php

declare(strict_types=1);

use App\Domain\DatabaseConnections\ManagedMysqlUserStatements;
use App\Domain\Shared\ResourceOperationException;

const MANAGED_MYSQL_USER_SECRET = 'user-pass-9f3a';

it('renders idempotent MySQL statements without putting secrets in debug output', function (): void {
    $sql = new ManagedMysqlUserStatements()->render('app_db', 'app_user', MANAGED_MYSQL_USER_SECRET);

    expect($sql)
        ->toContain('CREATE DATABASE IF NOT EXISTS `app_db`;')
        ->toContain("CREATE USER IF NOT EXISTS 'app_user'@'%' IDENTIFIED BY '".MANAGED_MYSQL_USER_SECRET."';")
        ->toContain("ALTER USER 'app_user'@'%' IDENTIFIED BY '".MANAGED_MYSQL_USER_SECRET."';")
        ->toContain("GRANT ALL PRIVILEGES ON `app_db`.* TO 'app_user'@'%';")
        ->toContain('FLUSH PRIVILEGES;')
        ->and(print_r(new ManagedMysqlUserStatements, true))
        ->not->toContain(MANAGED_MYSQL_USER_SECRET);
});

it('escapes quotes and backslashes in the password literal', function (): void {
    $sql = new ManagedMysqlUserStatements()->render('app', 'app', "o'reilly\\x");

    expect($sql)->toContain("IDENTIFIED BY 'o\\'reilly\\\\x';");
});

it('refuses unsafe identifiers and multiline passwords', function (): void {
    $statements = new ManagedMysqlUserStatements;

    expect(fn () => $statements->render('app-db', 'app', 'secret'))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('validation.failed');
        })
        ->and(fn () => $statements->render('app', 'app-user', 'secret'))
        ->toThrow(ResourceOperationException::class)
        ->and(fn () => $statements->render('app', 'app', "one\ntwo"))
        ->toThrow(ResourceOperationException::class);
});
