<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Config;
use Laravel\Boost\Mcp\ToolExecutor;
use Laravel\Boost\Mcp\Tools\ApplicationInfo;
use Laravel\Boost\Support\CommandNormalizer;
use Laravel\Mcp\Response;

final class LaravelZeroToolExecutor extends ToolExecutor
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(string $toolClass, array $arguments = []): Response
    {
        if ($toolClass === LaravelZeroApplicationInfo::class) {
            $toolClass = ApplicationInfo::class;
        }

        return parent::execute($toolClass, $arguments);
    }

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
