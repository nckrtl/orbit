<?php

declare(strict_types=1);

use App\Domain\DatabaseConnections\DatabaseSqlClassifier;
use App\Domain\Shared\ResourceOperationException;

describe(DatabaseSqlClassifier::class, function (): void {
    it('treats select explain show and read pragmas as reads', function (string $sql): void {
        expect(new DatabaseSqlClassifier()->isWrite($sql))->toBeFalse();
    })->with([
        'select' => ['SELECT id FROM users'],
        'with select' => ['WITH active AS (SELECT 1) SELECT * FROM active'],
        'explain' => ['EXPLAIN QUERY PLAN SELECT 1'],
        'show' => ['SHOW TABLES'],
        'describe' => ['DESCRIBE users'],
        'pragma table_info' => ['PRAGMA table_info(users)'],
        'commented select' => ["-- note\nSELECT 1"],
    ]);

    it('treats mutating statements as writes', function (string $sql): void {
        expect(new DatabaseSqlClassifier()->isWrite($sql))->toBeTrue();
    })->with([
        'insert' => ['INSERT INTO users (email) VALUES (\'a@example.test\')'],
        'update' => ['UPDATE users SET email = \'b@example.test\''],
        'delete' => ['DELETE FROM users'],
        'drop' => ['DROP TABLE users'],
        'create' => ['CREATE TABLE users (id INTEGER)'],
        'with insert' => ['WITH next AS (SELECT 1) INSERT INTO users (id) SELECT * FROM next'],
        'write pragma' => ['PRAGMA journal_mode = WAL'],
    ]);

    it('refuses write SQL without the write flag and stacked statements', function (): void {
        $classifier = new DatabaseSqlClassifier;

        expect(fn () => $classifier->assertWriteAllowed('DELETE FROM users', false))
            ->toThrow(ResourceOperationException::class)
            ->and(fn () => $classifier->assertWriteAllowed('SELECT 1; DELETE FROM users', false))
            ->toThrow(ResourceOperationException::class);

        $classifier->assertWriteAllowed('DELETE FROM users', true);
        $classifier->assertWriteAllowed('SELECT 1', false);
    });
});
