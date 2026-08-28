<?php

declare(strict_types=1);

namespace App\E2E;

use Illuminate\Support\Facades\Process;
use RuntimeException;

final readonly class HostRelativeDeleter
{
    public function __construct(
        private string $helper,
    ) {}

    public function delete(string $kind, string $root, string $path): void
    {
        if (! is_file($this->helper) || ! is_executable($this->helper)) {
            throw new RuntimeException('The python3 host deletion helper is unavailable.');
        }
        $prefix = rtrim($root, '/').'/';
        if (! str_starts_with($path, $prefix)) {
            throw new RuntimeException('The host deletion target is outside its safe root.');
        }
        $relative = substr($path, strlen($prefix));
        if ($relative === false || $relative === '') {
            throw new RuntimeException('The host deletion target cannot be the safe root.');
        }
        try {
            $result = Process::timeout(30)->run([
                'python3',
                $this->helper,
                '--kind',
                $kind,
                '--root',
                $root,
                '--path',
                $relative,
            ]);
        } catch (\Throwable $exception) {
            throw new RuntimeException('python3 is required for exact host deletion.', 0, $exception);
        }
        if ($result->failed()) {
            $diagnostic = trim($result->errorOutput());
            throw new RuntimeException(
                $diagnostic === '' ? 'python3 is required for exact host deletion.' : $diagnostic,
            );
        }
    }
}
