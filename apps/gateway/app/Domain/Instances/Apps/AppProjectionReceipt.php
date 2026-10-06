<?php

declare(strict_types=1);

namespace App\Domain\Instances\Apps;

use App\Models\InstanceAppProjectionStep;

/** Verified protected receipt metadata. Snapshot bytes never enter the journal. */
final readonly class AppProjectionReceipt
{
    /**
     * @param  array<string, string>  $snapshots  Protected snapshot references, never file bytes.
     * @param  array<string, string>  $targets  Exact target identities from the committed intent.
     * @param  array<string, array{created: bool, protection_fingerprint: string, result_fingerprint: string}>  $artifacts  Owned artifact and protection evidence.
     */
    public function __construct(
        public string $stepId,
        public string $projectionId,
        public string $receiptId,
        public string $planDigest,
        public string $intentDigest,
        public string $resultFingerprint,
        public array $snapshots,
        public bool $complete,
        public array $targets,
        public array $artifacts,
    ) {}

    public function matches(InstanceAppProjectionStep $step): bool
    {
        return $this->complete && $this->resultFingerprint !== ''
            && $this->stepId === $step->id && $this->projectionId === $step->instance_app_projection_id
            && $this->receiptId === $step->receipt_id && $this->planDigest === $step->plan_digest
            && $this->intentDigest === AppProjectionIdentity::digest($step->intent)
            && is_array($step->intent['targets'] ?? null)
            && AppProjectionIdentity::digest($this->targets) === AppProjectionIdentity::digest($step->intent['targets'])
            && $this->artifacts !== []
            && array_all($this->artifacts, static fn (array $artifact): bool => $artifact['protection_fingerprint'] !== '' && $artifact['result_fingerprint'] !== '');
    }

    /** @return array<string, mixed> */
    public function evidence(): array
    {
        return ['step_id' => $this->stepId, 'projection_id' => $this->projectionId, 'receipt_id' => $this->receiptId,
            'plan_digest' => $this->planDigest, 'intent_digest' => $this->intentDigest,
            'result_fingerprint' => $this->resultFingerprint, 'snapshots' => $this->snapshots, 'complete' => $this->complete,
            'targets' => $this->targets, 'artifacts' => $this->artifacts];
    }
}
