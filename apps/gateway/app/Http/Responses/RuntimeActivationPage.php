<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\Response;

final readonly class RuntimeActivationPage
{
    public function progress(): Response
    {
        return $this->html(
            status: 401,
            title: 'Starting development runtime',
            body: '<p>Orbit is starting this AppInstance\'s development processes.</p><p>This page refreshes until the runtime is ready.</p>',
            refresh: 2,
        );
    }

    public function failed(string $message): Response
    {
        $safe = e($message);

        return $this->html(
            status: 503,
            title: 'Development runtime failed',
            body: '<p>Orbit could not start this AppInstance\'s development processes.</p><p>'.$safe.'</p><p>This page retries until the runtime is ready.</p>',
            refresh: 5,
        );
    }

    public function ready(): Response
    {
        return response('', 200);
    }

    private function html(int $status, string $title, string $body, ?int $refresh = null): Response
    {
        $refreshTag = $refresh === null
            ? ''
            : '<meta http-equiv="refresh" content="'.$refresh.'">';

        return response(
            <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            {$refreshTag}
            <title>{$title}</title>
            </head>
            <body>
            {$body}
            </body>
            </html>
            HTML,
            $status,
            [
                'Content-Type' => 'text/html; charset=utf-8',
                'Cache-Control' => 'no-store',
            ],
        );
    }
}
