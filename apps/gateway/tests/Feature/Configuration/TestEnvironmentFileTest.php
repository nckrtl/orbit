<?php

declare(strict_types=1);

describe('the test application environment file', function (): void {
    it('is the tracked .env.example, never the untracked .env', function (): void {
        expect(app()->environmentFilePath())->toBe(base_path('.env.example'))
            ->and(base_path('.env.example'))->toBeFile();
    });
});
