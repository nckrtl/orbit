<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Config;
use Laravel\Boost\Mcp\ToolExecutor;
use Laravel\Boost\Support\CommandNormalizer;

final class LaravelZeroToolExecutor extends ToolExecutor
{
    protected function buildCommand(string $toolClass, array $arguments): array
    {
        $phpBinary = is_string(Config::get('boost.executable_paths.php'))
            ? Config::string('boost.executable_paths.php')
            : PHP_BINARY;

        $normalized = CommandNormalizer::normalize($phpBinary);

        return [
            $normalized['command'],
            ...$normalized['args'],
            base_path('orbit'),
            'boost:execute-tool',
            $toolClass,
            base64_encode(json_encode($arguments, flags: JSON_THROW_ON_ERROR)),
        ];
    }
}
