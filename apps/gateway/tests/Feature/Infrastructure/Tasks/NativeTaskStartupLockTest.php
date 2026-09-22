<?php

declare(strict_types=1);

use App\Infrastructure\Tasks\NativeTaskStartupLock;
use App\Models\TaskGroup;

it('releases startup ownership after an exception so another caller can try', function (): void {
    $directory = sys_get_temp_dir().'/orbit-startup-'.bin2hex(random_bytes(8));
    $lock = new NativeTaskStartupLock($directory);

    try {
        expect(fn () => $lock->run(fn () => throw new RuntimeException('setup failed')))
            ->toThrow(RuntimeException::class, 'setup failed');
        $result = new TaskGroup;
        expect(new NativeTaskStartupLock($directory)->run(fn (): TaskGroup => $result))->toBe($result);
    } finally {
        @unlink($directory.'/startup.lock');
        @rmdir($directory);
    }
});
