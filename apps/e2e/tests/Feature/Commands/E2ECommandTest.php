<?php

declare(strict_types=1);

it('rejects an issue argument that is not a string', function () {
    $this
        ->artisan('topology:verify', ['issue' => ['AUX-1']])
        ->expectsOutputToContain('The issue argument must be a string.')
        ->assertFailed();
});
