<?php

declare(strict_types=1);

namespace App\Data\Doctor;

use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorIssueCode;
use App\Domain\Doctor\DoctorIssueCodeCatalog;
use App\Domain\Doctor\DoctorIssueKind;
use InvalidArgumentException;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class DoctorIssueData extends Data
{
    public string $code;

    /** @mago-expect lint:excessive-parameter-list */
    public function __construct(
        DoctorIssueCode $code,
        public DoctorIssueKind $kind,
        public string $resourceType,
        public int|string|null $resourceId,
        public ?string $resourceName,
        public string $summary,
        public bool|string|null $expected,
        public bool|string|null $observed,
    ) {
        $family = DoctorFamily::tryFrom($resourceType) ?? throw new InvalidArgumentException(
            'A Doctor issue needs a known resource family.',
        );
        if ($code->family() !== $family) {
            throw new InvalidArgumentException('A Doctor issue code must match its resource family.');
        }
        $this->code = $code->code();
    }

    /** @mago-expect lint:excessive-parameter-list */
    public static function fromInternal(
        DoctorFamily $family,
        string $code,
        DoctorIssueKind $kind,
        int|string|null $resourceId,
        ?string $resourceName,
        string $summary,
        bool|string|null $expected,
        bool|string|null $observed,
    ): self {
        $resolved = DoctorIssueCodeCatalog::fromInternal($family, $code);
        if ($resolved->code() !== $code) {
            return new self(
                $resolved,
                DoctorIssueKind::Unverifiable,
                $family->value,
                $resourceId,
                $resourceName,
                ucfirst($family->value).' inspection could not be verified.',
                'verifiable',
                'unverifiable',
            );
        }

        return new self(
            $resolved,
            $kind,
            $family->value,
            $resourceId,
            $resourceName,
            $summary,
            $expected,
            $observed,
        );
    }
}
