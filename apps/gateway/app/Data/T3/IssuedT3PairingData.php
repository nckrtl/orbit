<?php

declare(strict_types=1);

namespace App\Data\T3;

use App\Models\T3Pairing;
use SensitiveParameter;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** A new pairing and the one-time link the peer opens to pair with the T3 server. */
#[MapOutputName(SnakeCaseMapper::class)]
final class IssuedT3PairingData extends Data
{
    public function __construct(
        public T3PairingData $pairing,
        #[SensitiveParameter] public string $pairingUrl,
    ) {}

    public static function fromModel(T3Pairing $pairing, #[SensitiveParameter] string $pairingUrl): self
    {
        return new self(T3PairingData::fromModel($pairing), $pairingUrl);
    }
}
