<?php

declare(strict_types=1);

namespace App\Console\Commands\Standby;

use App\E2E\StandbyRefresher;
use App\E2E\Value\MigrationPlan;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

final class RefreshCommand extends Command
{
    #[\Override]
    protected $signature = 'standby:refresh {--main-sha=} {--migration-file=} {--allow-cold} {--json}';
    #[\Override]
    protected $description = 'Refresh and promote the standby generation';

    public function handle(StandbyRefresher $refresher): int
    {
        try {
            $sha = $this->option('main-sha');
            if (! is_string($sha) || preg_match('/\A[a-f0-9]{40}\z/D', $sha) !== 1) {
                throw new \InvalidArgumentException('The exact main SHA is required.');
            }
            $result = $refresher->request($sha, $this->migration(), (bool) $this->option('allow-cold'));
            $payload = $result->toArray();
            $this->line($this->option('json') ? json_encode($payload, JSON_THROW_ON_ERROR) : $result->state);

            return $result->state === 'failed' ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function migration(): ?MigrationPlan
    {
        $path = $this->option('migration-file');
        if ($path === null) {
            return null;
        }
        if (! is_string($path) || $path === '' || ! is_file($path) || is_link($path)) {
            throw new \InvalidArgumentException('The migration file is invalid.');
        }
        try {
            $value = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \InvalidArgumentException('The migration file is malformed.', previous: $exception);
        }
        if (! is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException('The migration file schema is invalid.');
        }

        return MigrationPlan::fromArray($value);
    }
}
