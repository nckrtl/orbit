<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Herdr\ObservationGrantSigner;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class JwksController extends Controller
{
    public function show(ObservationGrantSigner $signer): JsonResponse
    {
        return response()->json($signer->jwks());
    }
}
