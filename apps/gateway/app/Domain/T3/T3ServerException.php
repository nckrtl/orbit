<?php

declare(strict_types=1);

namespace App\Domain\T3;

use App\Domain\Shared\ResourceOperationException;
use Throwable;

final class T3ServerException extends ResourceOperationException
{
    public static function unreachable(string $baseUrl, ?Throwable $previous = null): self
    {
        return new self('t3.server_unreachable', "The T3 server at {$baseUrl} did not answer.", 502, $previous);
    }

    public static function refused(string $baseUrl, string $operation, int $status, string $reason): self
    {
        $code = $status === 401 ? 't3.session_rejected' : 't3.request_refused';

        return new self($code, "The T3 server at {$baseUrl} refused {$operation} ({$status} {$reason}).", 502, details: [
            'operation' => $operation,
            'status' => $status,
            'reason' => $reason,
        ]);
    }

    public static function rejected(string $baseUrl, string $operation): self
    {
        return new self('t3.session_rejected', "The T3 server at {$baseUrl} does not accept the session.", 502, details: [
            'operation' => $operation,
        ]);
    }

    public static function malformed(string $baseUrl, string $operation): self
    {
        return new self('t3.response_invalid', "The T3 server at {$baseUrl} sent an unexpected {$operation} response.", 502, details: [
            'operation' => $operation,
        ]);
    }
}
