<?php

declare(strict_types=1);

namespace App\Domain\Releases;

use InvalidArgumentException;

/**
 * One release verdict that needs a person: what happened, to which release, and where the evidence is.
 */
final readonly class ReleaseAlert
{
    private const int EvidenceUrlLimit = 2048;

    public function __construct(
        public ReleaseAlertKind $kind,
        public ReleaseAlertSubject $subject,
        public string $summary,
        public ?string $evidenceUrl = null,
    ) {
        if (trim($summary) === '') {
            throw new InvalidArgumentException('A release alert needs a summary.');
        }

        if ($evidenceUrl !== null && ! $this->isHttpUrl($evidenceUrl)) {
            throw new InvalidArgumentException('A release alert evidence link is an http or https URL of at most 2048 characters.');
        }
    }

    private function isHttpUrl(string $url): bool
    {
        if (strlen($url) > self::EvidenceUrlLimit || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true);
    }
}
