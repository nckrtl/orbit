<?php

declare(strict_types=1);

describe('Boost guidance', function (): void {
    it('has a committed guidance index', function (): void {
        expect(base_path('.ai/rules/index.md'))->toBeFile();
    });
});
