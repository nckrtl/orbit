<?php

declare(strict_types=1);

use App\Domain\Nodes\OsReleaseVersion;

it('prefers a quoted pretty name', function (string $contents): void {
    expect(OsReleaseVersion::fromContents($contents))->toBe('Ubuntu 26.04.1 LTS');
})->with([
    'double quoted' => "ID=ubuntu\nPRETTY_NAME=\"Ubuntu 26.04.1 LTS\"\nVERSION_ID=\"26.04\"\n",
    'single quoted' => "ID=ubuntu\nPRETTY_NAME='Ubuntu 26.04.1 LTS'\nVERSION_ID='26.04'\n",
    'pretty name after comments' => "# comment\n\nPRETTY_NAME=\"Ubuntu 26.04.1 LTS\"\n",
    'final line without newline' => 'PRETTY_NAME="Ubuntu 26.04.1 LTS"',
]);

it('falls back to name and version when pretty name is absent', function (string $contents, string $expected): void {
    expect(OsReleaseVersion::fromContents($contents))->toBe($expected);
})->with([
    'name and version' => ["NAME=\"Ubuntu\"\nVERSION=\"26.04.1 LTS (Resolute Raccoon)\"\n", 'Ubuntu 26.04.1 LTS (Resolute Raccoon)'],
    'name and version id' => ["NAME=Ubuntu\nVERSION_ID=26.04\n", 'Ubuntu 26.04'],
]);

it('returns null when the release file is empty, malformed, or untrusted', function (string $contents): void {
    expect(OsReleaseVersion::fromContents($contents))->toBeNull();
})->with([
    'empty' => '',
    'comments only' => "# NAME=Ubuntu\n",
    'missing values' => "ID=ubuntu\nVERSION_CODENAME=resolute\n",
    'mismatched quotes' => "PRETTY_NAME=\"Ubuntu 26.04.1 LTS'\n",
    'command substitution' => "PRETTY_NAME=\"Ubuntu \$(uname -r)\"\n",
    'backticks' => "PRETTY_NAME=`uname -a`\n",
    'control characters' => "PRETTY_NAME=\"Ubuntu\n26.04\"\n",
]);
