<?php

declare(strict_types=1);

namespace App\Services\Profile;

final class ProfileInputResolver
{
    public function resolve(?string $url, bool $asFirstUser, ?string $user): ProfileInput|ProfileInputFailure
    {
        if ($asFirstUser && $user !== null) {
            return new ProfileInputFailure('profile.validation_failed', 'Use either --as-first-user or --user, not both.', [
                'field' => 'auth',
                'reason' => 'conflicting_auth_modes',
            ]);
        }

        $url ??= $this->appUrlFromNearestEnv();

        if ($url === null) {
            return new ProfileInputFailure('profile.validation_failed', 'URL to profile is required.', [
                'field' => 'url',
                'reason' => 'missing_required_input',
            ]);
        }

        if ($this->urlValidationMessage($url) !== null) {
            return new ProfileInputFailure(
                'profile.validation_failed',
                'URL to profile must be an absolute HTTP or HTTPS URL.',
                [
                    'field' => 'url',
                    'reason' => 'invalid_url',
                ],
            );
        }

        return new ProfileInput(
            url: $url,
            authMode: $this->authMode($asFirstUser, $user),
            user: $user,
        );
    }

    public function urlValidationMessage(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? $parts['scheme'] ?? null : null;
        $host = is_array($parts) ? $parts['host'] ?? null : null;

        if (
            filter_var($url, FILTER_VALIDATE_URL) === false
            || ! is_string($scheme)
            || ! in_array(strtolower($scheme), ['http', 'https'], true)
            || ! is_string($host)
            || $host === ''
        ) {
            return 'Enter an absolute HTTP or HTTPS URL.';
        }

        return null;
    }

    private function appUrlFromNearestEnv(): ?string
    {
        $directory = $this->hostCwd();

        if ($directory === null) {
            return null;
        }

        while (true) {
            $envPath = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.env';

            if (is_file($envPath)) {
                return $this->appUrlFromEnvFile($envPath);
            }

            $parent = dirname($directory);

            if ($parent === $directory) {
                return null;
            }

            $directory = $parent;
        }
    }

    private function hostCwd(): ?string
    {
        $hostCwd = getenv('ORBIT_HOST_CWD');

        if (is_string($hostCwd) && trim($hostCwd) !== '') {
            return trim($hostCwd);
        }

        $cwd = getcwd();

        return is_string($cwd) && trim($cwd) !== '' ? trim($cwd) : null;
    }

    private function appUrlFromEnvFile(string $path): ?string
    {
        $handle = fopen(filename: $path, mode: 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $matches = [];

                if (preg_match('/^\s*(?:export\s+)?APP_URL\s*=\s*(.*)$/', $line, $matches) !== 1) {
                    continue;
                }

                return $this->parseEnvValue($matches[1]);
            }
        } finally {
            fclose($handle);
        }

        return null;
    }

    private function parseEnvValue(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $quote = $value[0];

        if ($quote === "'" || $quote === '"') {
            $closingQuote = strrpos($value, $quote);

            if ($closingQuote === false || $closingQuote === 0) {
                return $value;
            }

            return substr(string: $value, offset: 1, length: $closingQuote - 1);
        }

        $valueWithoutComment = preg_replace(pattern: '/\s+#.*$/', replacement: '', subject: $value);

        return is_string($valueWithoutComment) ? rtrim($valueWithoutComment) : $value;
    }

    private function authMode(bool $asFirstUser, ?string $user): string
    {
        if ($user !== null) {
            return 'user';
        }

        return $asFirstUser ? 'first-user' : 'guest';
    }
}
