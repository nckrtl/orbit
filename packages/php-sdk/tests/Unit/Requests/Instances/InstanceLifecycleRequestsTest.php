<?php

declare(strict_types=1);

describe('Instance lifecycle requests', function (): void {

    it('does not keep the replaced destroy class name', function (): void {
        expect(class_exists('Orbit\\Sdk\\Requests\\Instances\\RemoveInstanceRequest'))->toBeFalse();
    });
});
