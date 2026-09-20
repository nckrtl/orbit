<?php

declare(strict_types=1);

namespace App\Domain\ProxyCli;

interface ProxyCliManagementClient
{
    /**
     * @return list<array<string, mixed>>
     */
    public function authFiles(string $baseUrl, string $managementKey): array;

    /**
     * @param  array<string, string>  $headers
     */
    public function apiCall(
        string $baseUrl,
        string $managementKey,
        string $authIndex,
        string $url,
        array $headers = [],
    ): ProxyCliUsageResponse;

    public function setDisabled(string $baseUrl, string $managementKey, string $account, bool $disabled): void;
}
