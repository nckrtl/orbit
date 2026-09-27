<?php

declare(strict_types=1);

namespace App\Actions\Gateway;

use App\Data\Gateway\GatewayStatusData;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Config;

final readonly class ShowGatewayStatusAction
{
    public function handle(): GatewayStatusData
    {
        return new GatewayStatusData(
            name: 'orbit-gateway',
            status: 'ok',
            version: Config::string('app.version'),
            phpVersion: PHP_VERSION,
            laravelVersion: Application::VERSION,
        );
    }
}
