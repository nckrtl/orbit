<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Transfer;

enum AppInstanceTransferStep: string
{
    case Reserved = 'reserved';
    case SourceCaptured = 'source-captured';
    case DestinationCheckoutCreated = 'destination-checkout-created';
    case SourcePaused = 'source-paused';
    case SqliteTransferred = 'sqlite-transferred';
    case EnvironmentImported = 'environment-imported';
    case EnvironmentRebuilt = 'environment-rebuilt';
    case RuntimeRelocated = 'runtime-relocated';
    case RoutePrepared = 'route-prepared';
    case Cutover = 'cutover';
    case DestinationActivated = 'destination-activated';
    case SourceCleaned = 'source-cleaned';
    case Completed = 'completed';

    /** @return list<self> */
    public static function ordered(): array
    {
        return [
            self::Reserved,
            self::SourceCaptured,
            self::DestinationCheckoutCreated,
            self::SourcePaused,
            self::SqliteTransferred,
            self::EnvironmentImported,
            self::EnvironmentRebuilt,
            self::RuntimeRelocated,
            self::RoutePrepared,
            self::Cutover,
            self::DestinationActivated,
            self::SourceCleaned,
            self::Completed,
        ];
    }

    public function isAfterCutover(): bool
    {
        return $this->rank() >= self::Cutover->rank();
    }

    public function rank(): int
    {
        return array_search($this, self::ordered(), true);
    }
}
