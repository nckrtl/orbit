<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\GitHub;

use Orbit\Sdk\GatewayApiException;
use SensitiveParameter;

final readonly class GitHubAppInstallResponse
{
    /** @param list<string> $accounts */
    private function __construct(
        public string $step,
        public string $url,
        public array $accounts,
        public string $requestId,
    ) {}

    /** @param array<string,mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        $step = $data['step'] ?? null;
        $url = $data['url'] ?? null;
        $accounts = $data['accounts'] ?? null;

        if (
            ! is_string($step)
            || ! in_array($step, ['register', 'install'], strict: true)
            || ! GitHubAppResponse::validUrl($url)
            || ! is_array($accounts)
            || ! array_is_list($accounts)
            || count($accounts) > 10_000
        ) {
            throw new GatewayApiException('Gateway response contains an invalid GitHub App install step.', requestId: $requestId);
        }

        $names = [];
        foreach ($accounts as $account) {
            if (! GitHubAppResponse::validText($account)) {
                throw new GatewayApiException('Gateway response contains an invalid GitHub App install step.', requestId: $requestId);
            }

            $names[] = $account;
        }

        /** @var string $url */
        return new self($step, $url, $names, $requestId);
    }

    /** @return array{step: string, url: string, accounts: list<string>, request_id: string} */
    public function toArray(): array
    {
        return [
            'step' => $this->step,
            'url' => $this->url,
            'accounts' => $this->accounts,
            'request_id' => $this->requestId,
        ];
    }
}
