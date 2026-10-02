<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\MysqlStatements;
use App\Domain\Shared\ResourceOperationException;

describe('MysqlStatements', function (): void {
    it('quotes identifiers and escapes the password', function (): void {
        $statements = new MysqlStatements;

        expect($statements->ensureUser('app', "it's a \\ secret"))->toBe(implode("\n", [
            "CREATE USER IF NOT EXISTS 'app'@'%' IDENTIFIED BY 'it\\'s a \\\\ secret';",
            "ALTER USER 'app'@'%' IDENTIFIED BY 'it\\'s a \\\\ secret';",
        ])."\n")
            ->and($statements->createDatabase('1app'))->toBe("CREATE DATABASE `1app`;\n")
            ->and($statements->dropDatabase('app_test'))->toBe("DROP DATABASE IF EXISTS `app_test`;\n")
            ->and($statements->dropUser('app'))->toBe("DROP USER IF EXISTS 'app'@'%';\n");
    });

    it('escapes underscores and percent signs in a prefix pattern', function (): void {
        $statements = new MysqlStatements;

        expect($statements->likePrefix('app_test'))->toBe('app\\_test%')
            ->and($statements->grantAllWithPrefix('app_test', 'app'))
            ->toBe("GRANT ALL PRIVILEGES ON `app\\_test%`.* TO 'app'@'%';\n")
            ->and($statements->databasesNamed('app', 'app_test'))
            ->toBe("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'app' OR SCHEMA_NAME LIKE 'app\\\\_test%' ORDER BY SCHEMA_NAME;\n")
            ->and($statements->privilegeText('app', prefix: 'app_test'))
            ->toBe('ALL PRIVILEGES ON `app`.*, `app\\_test%`.*');
    });

    it('replaces a grant so a repeat can make a user read-only', function (): void {
        expect(new MysqlStatements()->replaceGrant('app', 'reporting', readOnly: true))->toBe(implode("\n", [
            "GRANT SELECT ON `app`.* TO 'reporting'@'%';",
            "REVOKE ALL PRIVILEGES ON `app`.* FROM 'reporting'@'%';",
            "GRANT SELECT ON `app`.* TO 'reporting'@'%';",
        ])."\n");
    });

    it('refuses unsafe names and multi-line passwords', function (Closure $render): void {
        expect(fn () => $render(new MysqlStatements))->toThrow(
            fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('validation.failed'),
        );
    })->with([
        'backtick in database' => [fn (MysqlStatements $statements): string => $statements->createDatabase('app`; DROP DATABASE x; --')],
        'database over 64' => [fn (MysqlStatements $statements): string => $statements->createDatabase(str_repeat('a', 65))],
        'quote in user' => [fn (MysqlStatements $statements): string => $statements->dropUser("app'@'%")],
        'user over 32' => [fn (MysqlStatements $statements): string => $statements->dropUser(str_repeat('a', 33))],
        'newline in password' => [fn (MysqlStatements $statements): string => $statements->ensureUser('app', "a\nb")],
        'empty password' => [fn (MysqlStatements $statements): string => $statements->ensureUser('app', '')],
        'percent in prefix' => [fn (MysqlStatements $statements): string => $statements->likePrefix('app%')],
    ]);
});
