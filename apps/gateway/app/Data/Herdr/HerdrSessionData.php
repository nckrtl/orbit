<?php

declare(strict_types=1);

namespace App\Data\Herdr;

use App\Models\HerdrSession;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class HerdrSessionData extends Data
{
    /**
     * @param  array{process: string, listener: string, session: string}  $health
     */
    public function __construct(
        public int $id,
        public string $node,
        public int $nodeId,
        public string $session,
        public string $user,
        public ?int $processId,
        public ?string $observerUrl,
        public string $status,
        public ?string $herdrVersion,
        public ?int $protocol,
        public array $health,
        public ?string $failedStep,
        public ?string $errorCode,
    ) {}

    /**
     * @param  array{process: string, listener: string, session: string}  $health
     */
    public static function fromModel(HerdrSession $session, array $health): self
    {
        return new self(
            id: $session->id,
            node: $session->node->name,
            nodeId: $session->node_id,
            session: $session->session,
            user: $session->user,
            processId: $session->process_id,
            observerUrl: $session->observer_url,
            status: $session->status->value,
            herdrVersion: $session->herdr_version,
            protocol: $session->protocol,
            health: $health,
            failedStep: $session->failed_step,
            errorCode: $session->error_code,
        );
    }
}
