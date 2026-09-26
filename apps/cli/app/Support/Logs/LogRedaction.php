<?php

declare(strict_types=1);

namespace App\Support\Logs;

/**
 * The CLI's own redaction of log text, applied after the Gateway's. It replaces PEM blocks,
 * secret-named values, authorization headers, bearer tokens, URL credentials, and secret query
 * values with `[redacted]`, and writes the Gateway's `[REDACTED]` marker in the same form.
 */
final class LogRedaction
{
    public static function redact(string $logs): string
    {
        $sensitiveName = self::sensitiveNamePattern();
        $redacted = str_ireplace(
            search: '[REDACTED]',
            replace: '[redacted]',
            subject: $logs,
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

    public static function sensitiveNamePattern(): string
    {
        return
            '[A-Z0-9_.-]*(?:APP[_-]?KEY|APPLICATION[_-]?KEY|API[_-]?KEY|ACCESS[_-]?TOKEN|'
            .'REFRESH[_-]?TOKEN|OPERATION[_-]?TOKEN|EXECUTOR[_-]?SECRET|PRIVATE[_-]?KEY|'
            .'PRE[_-]?SHARED[_-]?KEY|PASSWORD[_-]?HASH|PASSWORD|PASSWD|PWD|SECRET|TOKEN|'
            .'BEARER[_-]?TOKEN|CREDENTIAL|COOKIE)[A-Z0-9_.-]*';
    }
}
