<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\GitHub;

use Orbit\Sdk\GatewayApiException;
use SensitiveParameter;

final readonly class GitHubAppResponse
{
    /** @param list<array{id:int,account:string,type:string,repositories:string,suspended:bool}> $installations */
    private function __construct(
        public string $name,
        public string $slug,
        public int $appId,
        public string $owner,
        public string $url,
        public string $settingsUrl,
        public array $installations,
        public string $requestId,
    ) {}

    /** @param array<string,mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        $name = $data['name'] ?? null;
        $slug = $data['slug'] ?? null;
        $appId = $data['app_id'] ?? null;
        $owner = $data['owner'] ?? null;
        $url = $data['url'] ?? null;
        $settingsUrl = $data['settings_url'] ?? null;

        if (
            ! self::validText($name)
            || ! self::validText($slug)
            || ! is_int($appId)
            || $appId < 1
            || ! self::validText($owner)
            || ! self::validUrl($url)
            || ! self::validUrl($settingsUrl)
        ) {
            throw new GatewayApiException('Gateway response contains an invalid GitHub App.', requestId: $requestId);
        }

        /** @var string $name */
        /** @var string $slug */
        /** @var string $owner */
        /** @var string $url */
        /** @var string $settingsUrl */
        return new self(
            $name,
            $slug,
            $appId,
            $owner,
            $url,
            $settingsUrl,
            self::installations($data['installations'] ?? null, $requestId),
            $requestId,
        );
    }

    /** @phpstan-assert-if-true string $value */
    public static function validText(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '' && strlen($value) <= 255;
    }

    /** @phpstan-assert-if-true string $value */
    public static function validUrl(mixed $value): bool
    {
        return
            is_string($value)
            && strlen($value) <= 2048
            && filter_var($value, FILTER_VALIDATE_URL) !== false
            && preg_match('/\Ahttps?:\/\//iD', $value) === 1;
    }

    /** @return list<array{id:int,account:string,type:string,repositories:string,suspended:bool}> */
    private static function installations(mixed $value, string $requestId): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 10_000) {
            throw new GatewayApiException('Gateway response contains invalid GitHub App installations.', requestId: $requestId);
        }

        $rows = [];
        foreach ($value as $row) {
            $id = is_array($row) ? $row['id'] ?? null : null;
            $account = is_array($row) ? $row['account'] ?? null : null;
            $type = is_array($row) ? $row['type'] ?? null : null;
            $repositories = is_array($row) ? $row['repositories'] ?? null : null;
            $suspended = is_array($row) ? $row['suspended'] ?? null : null;

            if (
                ! is_int($id)
                || $id < 1
                || ! self::validText($account)
                || ! is_string($type)
                || ! in_array($type, ['user', 'organization'], strict: true)
                || ! is_string($repositories)
                || ! in_array($repositories, ['all', 'selected'], strict: true)
                || ! is_bool($suspended)
            ) {
                throw new GatewayApiException('Gateway response contains an invalid GitHub App installation.', requestId: $requestId);
            }

            $rows[] = [
                'id' => $id,
                'account' => $account,
                'type' => $type,
                'repositories' => $repositories,
                'suspended' => $suspended,
            ];
        }

        return $rows;
    }

    /**
     * @return array{
     *     name: string,
     *     slug: string,
     *     app_id: int,
     *     owner: string,
     *     url: string,
     *     settings_url: string,
     *     installations: list<array{id:int,account:string,type:string,repositories:string,suspended:bool}>,
     *     request_id: string
     * }
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'app_id' => $this->appId,
            'owner' => $this->owner,
            'url' => $this->url,
            'settings_url' => $this->settingsUrl,
            'installations' => $this->installations,
            'request_id' => $this->requestId,
        ];
    }
}
