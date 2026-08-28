<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\AtomicJsonStore;
use InvalidArgumentException;

final readonly class RefreshRequestStore
{
    public function __construct(
        private AtomicJsonStore $store,
    ) {}

    public function request(string $mainSha): void
    {
        $this->validateSha($mainSha);
        $this->store->write('standby/request.json', [
            'schema' => 1,
            'main_sha' => $mainSha,
            'requested_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    public function pending(): ?string
    {
        $request = $this->store->read('standby/request.json');
        if ($request === null) {
            return null;
        }

        $keys = array_keys($request);
        if (
            $keys !== ['schema', 'main_sha', 'requested_at']
            && $keys !== ['schema', 'main_sha', 'requested_at', 'handled']
            || $request['schema'] !== 1
            || ! is_string($request['main_sha'])
            || ! is_string($request['requested_at'])
            || strtotime($request['requested_at']) === false
        ) {
            throw new InvalidArgumentException('The refresh request is invalid.');
        }

        $this->validateSha($request['main_sha']);

        return ($request['handled'] ?? false) === true ? null : $request['main_sha'];
    }

    public function clear(string $mainSha): void
    {
        if ($this->pending() !== $mainSha) {
            return;
        }

        $this->store->write('standby/request.json', [
            'schema' => 1,
            'main_sha' => $mainSha,
            'requested_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'handled' => true,
        ]);
    }

    private function validateSha(string $mainSha): void
    {
        if (preg_match('/\A[a-f0-9]{40}\z/D', $mainSha) !== 1) {
            throw new InvalidArgumentException('The refresh SHA is invalid.');
        }
    }
}
