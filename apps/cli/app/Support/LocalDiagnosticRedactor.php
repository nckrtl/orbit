<?php

declare(strict_types=1);

namespace App\Support;

final class LocalDiagnosticRedactor
{
    private const int TAIL_BYTES = 32_768;

    private const int OVERLAP_BYTES = 8_192;

    public function redact(string $diagnostics): string
    {
        $sensitiveName = $this->sensitiveNamePattern();
        $redacted = str_ireplace(
            search: '[REDACTED]',
            replace: '[redacted]',
            subject: $diagnostics,
        );
        $patterns = [
            '/-----BEGIN [A-Z0-9 ]+-----[\s\S]*?-----END [A-Z0-9 ]+-----/' => '[redacted]',
            '/((?:^|[,{]\s*)["\']?'
                .$sensitiveName
                .'["\']?\s*(?:=|:)\s*)(?:"[^"\r\n]*"|\'[^\'\r\n]*\'|[^,\s}\r\n]+)/im' => '$1[redacted]',
            '/\b('.$sensitiveName.')\s*=\s*(?:"[^"\r\n]*"|\'[^\'\r\n]*\'|[^\s&,}\r\n]+)/i' => '$1=[redacted]',
            '/\b((?:Proxy-)?Authorization)\s*:\s*[^\r\n]*/i' => '$1: [redacted]',
            '/\b(Bearer)\s+(?:"[^"]*"|\'[^\']*\'|[A-Za-z0-9][A-Za-z0-9._\-+\/=]{7,})/i' => '$1 [redacted]',
            '/(\b[a-z][a-z0-9+.-]*:\/\/)[^@\s\/]+@/i' => '$1[redacted]@',
            '/([?&](?:'.$sensitiveName.'|passwd|credential|cookie)=)[^&\s]+/i' => '$1[redacted]',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $result = preg_replace(pattern: $pattern, replacement: $replacement, subject: $redacted);

            if (is_string($result)) {
                $redacted = $result;
            }
        }

        return $redacted;
    }

    /** @param iterable<string> $chunks */
    public function redactedTail(iterable $chunks): string
    {
        $tail = '';
        $retainedBytes = self::TAIL_BYTES + self::OVERLAP_BYTES;

        foreach ($chunks as $chunk) {
            $tail .= $chunk;

            if (strlen($tail) > $retainedBytes) {
                $tail = substr($tail, -$retainedBytes);
            }
        }

        return substr($this->redact($tail), -self::TAIL_BYTES);
    }

    public function isSensitiveName(string $name): bool
    {
        return preg_match('/\A'.$this->sensitiveNamePattern().'\z/iD', $name) === 1;
    }

    private function sensitiveNamePattern(): string
    {
        return (
            '[A-Z0-9_.-]*(?:APP[_-]?KEY|APPLICATION[_-]?KEY|API[_-]?KEY|ACCESS[_-]?TOKEN|'
            .'REFRESH[_-]?TOKEN|OPERATION[_-]?TOKEN|EXECUTOR[_-]?SECRET|PRIVATE[_-]?KEY|'
            .'PRE[_-]?SHARED[_-]?KEY|PASSWORD[_-]?HASH|PASSWORD|PASSWD|PWD|SECRET|TOKEN|'
            .'BEARER[_-]?TOKEN|CREDENTIAL|COOKIE)[A-Z0-9_.-]*'
        );
    }
}
