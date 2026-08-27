<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Firewall;

final readonly class FirewallRulesResponse
{
    /** @param list<FirewallRuleResponse> $rules */
    public function __construct(
        public array $rules,
        public string $requestId,
    ) {}

    /** @return array{rules: list<array<string, mixed>>, request_id: string} */
    public function toArray(): array
    {
        return [
            'rules' => array_map(
                static function (FirewallRuleResponse $rule): array {
                    $data = $rule->toArray();
                    unset($data['request_id']);

                    return $data;
                },
                $this->rules,
            ),
            'request_id' => $this->requestId,
        ];
    }
}
