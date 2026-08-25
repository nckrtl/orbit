<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Exceptions\GatewayConfigException;
use App\Repositories\GatewayConfigRepository;
use LaravelZero\Framework\Commands\Command;

final class GatewayUseCommand extends Command
{
    protected $signature = 'gateway:use
        {name : Local profile name}
        {--json : Return machine-readable JSON}';

    protected $description = 'Select the active gateway profile.';

    public function handle(GatewayConfigRepository $repository): int
    {
        $name = $this->argument('name');

        if (! is_string($name)) {
            $this->error('Gateway name must be a string.');

            return self::FAILURE;
        }

        try {
            $repository->use($name);
        } catch (GatewayConfigException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->line(json_encode(
                ['active_gateway' => $name],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        }

        $this->info("Gateway [{$name}] is active.");

        return self::SUCCESS;
    }
}
