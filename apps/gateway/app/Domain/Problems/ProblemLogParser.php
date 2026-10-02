<?php

declare(strict_types=1);

namespace App\Domain\Problems;

use App\Domain\Logs\LogRedactor;

/**
 * Reads one Laravel log record into a fingerprint signal, or rejects noise.
 */
final readonly class ProblemLogParser
{
    private const string Header = '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] [^.\[\]\s]+\.(DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY): /m';

    /** @var list<string> */
    private const array CountedLevels = ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    /** @var array<string, int> */
    private const array HttpStatuses = [
        'BadRequestHttpException' => 400,
        'UnauthorizedHttpException' => 401,
        'AccessDeniedHttpException' => 403,
        'NotFoundHttpException' => 404,
        'MethodNotAllowedHttpException' => 405,
        'NotAcceptableHttpException' => 406,
        'ConflictHttpException' => 409,
        'GoneHttpException' => 410,
        'LengthRequiredHttpException' => 411,
        'PreconditionFailedHttpException' => 412,
        'PreconditionRequiredHttpException' => 428,
        'UnsupportedMediaTypeHttpException' => 415,
        'UnprocessableEntityHttpException' => 422,
        'LockedHttpException' => 423,
        'TooManyRequestsHttpException' => 429,
        'ServiceUnavailableHttpException' => 503,
    ];

    public function __construct(private LogRedactor $redactor) {}

    /**
     * @return array{records: list<string>, consumed: int}
     */
    public function slice(string $chunk, bool $endOfFile): array
    {
        preg_match_all(self::Header, $chunk, $matches, PREG_OFFSET_CAPTURE);
        $starts = [];

        foreach ($matches[0] as $match) {
            $starts[] = $match[1];
        }

        if ($starts === []) {
            return ['records' => [], 'consumed' => $endOfFile ? strlen($chunk) : 0];
        }

        $records = [];
        $consumed = 0;
        $last = count($starts) - 1;

        foreach ($starts as $index => $start) {
            $next = $starts[$index + 1] ?? null;

            if (is_int($next)) {
                $records[] = substr($chunk, $start, $next - $start);
                $consumed = $next;

                continue;
            }

            if ($endOfFile || $index !== $last) {
                $records[] = substr($chunk, $start);
                $consumed = strlen($chunk);
            }
        }

        return ['records' => $records, 'consumed' => $consumed];
    }

    public function signal(string $record): ?ProblemLogSignal
    {
        if (preg_match(self::Header, $record, $header) !== 1) {
            return null;
        }

        if (! in_array($header[2], self::CountedLevels, true)) {
            return null;
        }

        if (preg_match('/\[object\] \((.+?)\(code:\s*(-?\d+)\)/s', $record, $exception) !== 1) {
            return null;
        }

        $class = str_replace('\\\\', '\\', $exception[1]);

        if ($this->excluded($class, (int) $exception[2])) {
            return null;
        }

        $frame = $this->firstAppFrame($record);

        if ($frame === null) {
            return null;
        }

        $requestId = null;

        if (preg_match('/"request_id"\s*:\s*"([^"]+)"/', $record, $context) === 1) {
            $requestId = $context[1];
        }

        return new ProblemLogSignal(
            $class,
            $frame,
            $requestId,
            $this->excerpt($this->message($record), $requestId),
            $header[1],
        );
    }

    private function excluded(string $class, int $code): bool
    {
        $base = str_contains($class, '\\') ? substr($class, (int) strrpos($class, '\\') + 1) : $class;

        if ($base === 'ValidationException') {
            return true;
        }

        $status = self::HttpStatuses[$base] ?? null;

        if ($base === 'HttpException' && $code >= 100 && $code <= 599) {
            $status = $code;
        }

        return $status !== null && $status < 500;
    }

    private function firstAppFrame(string $record): ?string
    {
        $matched = preg_match_all('/^#\d+ (.+?)\(\d+\): (.+)$/m', $record, $frames, PREG_SET_ORDER);

        if ($matched === false || $frames === []) {
            return null;
        }

        foreach ($frames as $frame) {
            $relative = $this->relativeAppPath($frame[1]);

            if ($relative === null) {
                continue;
            }

            return $relative.':'.$this->functionName($frame[2]);
        }

        return null;
    }

    /**
     * PHP 8.5 names a closure with the line where it was defined, as in
     * `{closure:{closure:Pipeline::carry():194}:195}`. The fingerprint keeps the function and drops those lines.
     */
    private function functionName(string $call): string
    {
        $normalized = str_replace('\\\\', '\\', rtrim($call));
        $withoutArguments = preg_replace('/\([^)]*\)$/', '', $normalized) ?? $normalized;
        $withoutLines = preg_replace('/:\d+(?=})/', '', $withoutArguments);

        return $withoutLines ?? $withoutArguments;
    }

    private function relativeAppPath(string $path): ?string
    {
        $normalized = str_replace('\\', '/', $path);

        foreach ($this->roots() as $root) {
            if (str_starts_with($normalized, $root.'app/')) {
                return substr($normalized, strlen($root));
            }
        }

        return null;
    }

    /** @return list<string> */
    private function roots(): array
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/');
        $real = realpath(base_path());
        $roots = [$base.'/'];

        if (is_string($real)) {
            $canonical = rtrim(str_replace('\\', '/', $real), '/').'/';

            if ($canonical !== $roots[0]) {
                $roots[] = $canonical;
            }
        }

        return $roots;
    }

    private function message(string $record): string
    {
        $line = strtok($record, "\n");

        if (! is_string($line)) {
            return '';
        }

        if (preg_match('/\.(?:ERROR|CRITICAL|ALERT|EMERGENCY): (.*)$/', $line, $match) !== 1) {
            return '';
        }

        $contextAt = strpos($match[1], ' {"');

        if ($contextAt === false) {
            return rtrim($match[1]);
        }

        return rtrim(substr($match[1], 0, $contextAt));
    }

    private function excerpt(string $message, ?string $requestId): string
    {
        $redacted = $this->redactor->redact($message, []);

        if ($requestId === null || $requestId === '') {
            return mb_substr($redacted, 0, 500);
        }

        $suffix = ' request_id='.$requestId;
        $room = 500 - mb_strlen($suffix);

        if ($room <= 0) {
            return mb_substr($suffix, 0, 500);
        }

        return mb_substr($redacted, 0, $room).$suffix;
    }
}
