<?php

/**
 * Reads one Prometheus instant query response on stdin and prints `ok` when it
 * carries the expected number of finite samples, or the reason it does not.
 *
 * Usage: series-verdict.php <minimum series> [exact series]
 */

declare(strict_types=1);

$minimum = (int) ($argv[1] ?? 1);
$exact = isset($argv[2]) ? (int) $argv[2] : null;
$response = json_decode((string) stream_get_contents(STDIN), true);

if (! is_array($response) || ($response['status'] ?? '') !== 'success') {
    echo 'query failed: ', substr(json_encode($response), 0, 200);
    exit(0);
}

$result = $response['data']['result'] ?? [];

if (count($result) < $minimum) {
    echo 'returned ', count($result), ' series, expected at least ', $minimum;
    exit(0);
}

if ($exact !== null && count($result) !== $exact) {
    echo 'returned ', count($result), ' series, expected exactly ', $exact;
    exit(0);
}

foreach ($result as $series) {
    $value = $series['value'][1] ?? 'NaN';
    if (! is_numeric($value) || ! is_finite((float) $value)) {
        echo 'returned a non-finite sample: ', (string) $value;
        exit(0);
    }
}

echo 'ok';
