<?php

declare(strict_types=1);

describe('Boost configuration', function (): void {
    it('enables durable scoped guidance', function (): void {
        $configuration = require base_path('config/boost.php');

        expect($configuration['enforce_tests'])
            ->toBeTrue()
            ->and($configuration['rules'])
            ->toBe(['enabled' => true, 'scoped_guidelines' => true]);
    });
});
