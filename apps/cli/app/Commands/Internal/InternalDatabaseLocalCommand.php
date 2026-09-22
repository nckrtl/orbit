<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Database\LocalDatabaseQueryAction;
use App\Services\Database\LocalDatabaseQueryException;
use App\Services\Database\LocalDatabaseQueryRequest;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\StandardInputReader;
use App\Support\GatewayFailureRenderer;
use JsonException;
use LaravelZero\Framework\Commands\Command;

final class InternalDatabaseLocalCommand extends Command
{
    #[\Override]
    protected $signature = 'internal:database-local';

    #[\Override]
    protected $description = 'Run one SQL statement against a local SQLite file through PDO.';

    #[\Override]
    protected $hidden = true;

    public function handle(StandardInputReader $input, LocalDatabaseQueryAction $action): int
    {
        try {
            $request = $this->request($input->read());
            $result = $action->execute($request);
            $json = json_encode($result->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (LocalDatabaseQueryException $exception) {
            return $this->writeFailure($exception->errorCode, $exception->getMessage());
        } catch (JsonException) {
            return $this->writeFailure('database.query_failed', 'Database query failed.');
        }

        ConsoleWriter::write(
            $this->output,
            $json."\n",
        );

        return self::SUCCESS;
    }

    private function request(string $contents): LocalDatabaseQueryRequest
    {
        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new LocalDatabaseQueryException(
                'database.internal_unauthorized',
                'Internal database query is unauthorized.',
            );
        }

        if (! is_array($decoded)) {
            throw new LocalDatabaseQueryException(
                'database.internal_unauthorized',
                'Internal database query is unauthorized.',
            );
        }

        $token = $decoded['token'] ?? null;
        $path = $decoded['path'] ?? null;
        $sql = $decoded['sql'] ?? null;
        $write = $decoded['write'] ?? false;

        if (! is_string($token) || ! is_string($path) || ! is_string($sql) || ! is_bool($write)) {
            throw new LocalDatabaseQueryException(
                'database.internal_unauthorized',
                'Internal database query is unauthorized.',
            );
        }

        return new LocalDatabaseQueryRequest($token, $path, $sql, $write);
    }

    private function writeFailure(string $code, string $message): int
    {
        ConsoleWriter::write($this->output, GatewayFailureRenderer::json($code, $message)."\n");

        return self::FAILURE;
    }
}
