<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Doctor;

use SensitiveParameter;

final readonly class DoctorFamilyResponse
{
    private const array FAMILIES = ['node', 'role', 'app', 'instance', 'workspace', 'tool', 'process', 'firewall'];

    private const array STATUSES = ['healthy', 'drift', 'unverifiable'];

    /** @param list<DoctorIssueResponse> $issues */
    private function __construct(
        public string $family,
        public string $status,
        public int $checked,
        public array $issues,
    ) {}

    /**
     * @mago-expect analysis:mixed-assignment Gateway family values remain mixed until validated.
     * @param array<array-key, mixed> $data
     */
    public static function fromGatewayData(#[SensitiveParameter] array $data): ?self
    {
        $family = $data['family'] ?? null;
        $status = $data['status'] ?? null;
        $checked = $data['checked'] ?? null;
        $issueData = $data['issues'] ?? null;

        if (
            ! is_string($family)
            || ! in_array($family, self::FAMILIES, strict: true)
            || ! is_string($status)
            || ! in_array($status, self::STATUSES, strict: true)
            || ! is_int($checked)
            || $checked < 0
            || ! is_array($issueData)
            || ! array_is_list($issueData)
        ) {
            return null;
        }

        $issues = [];
        foreach ($issueData as $issueDatum) {
            if (! is_array($issueDatum)) {
                continue;
            }

            $issue = DoctorIssueResponse::fromGatewayData($issueDatum);
            if ($issue !== null) {
                $issues[] = $issue;
            }
        }

        return new self(family: $family, status: $status, checked: $checked, issues: $issues);
    }

    /** @return array{family:string,status:string,checked:int,issues:list<array{code:string,kind:string,resource_type:string,resource_id:int|string|null,resource_name:string|null,summary:string,expected:bool|string|null,observed:bool|string|null}>} */
    public function toArray(): array
    {
        return [
            'family' => $this->family,
            'status' => $this->status,
            'checked' => $this->checked,
            'issues' => array_map(static fn (DoctorIssueResponse $issue): array => $issue->toArray(), $this->issues),
        ];
    }
}
