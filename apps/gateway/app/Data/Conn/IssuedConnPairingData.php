<?php

declare(strict_types=1);

namespace App\Data\Conn;

use App\Models\ConnPairing;
use SensitiveParameter;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** A new pairing and the one-time link the Node opens to pair with the T3 server. */
#[MapOutputName(SnakeCaseMapper::class)]
final class IssuedConnPairingData extends Data
{
    public function __construct(
        public ConnPairingData $pairing,
        #[SensitiveParameter] public string $pairingUrl,
    ) {}

    public static function fromModel(ConnPairing $pairing, #[SensitiveParameter] string $pairingUrl): self
    {
        return new self(ConnPairingData::fromModel($pairing), $pairingUrl);
    }
}
