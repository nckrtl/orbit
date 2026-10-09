<?php

declare(strict_types=1);

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** An in-memory UpCloud API: servers, disks, templates, and firewall rules, behind Http::fake. */
final class FakeUpCloud
{
    /** @var array<string, array<string, mixed>> */
    public array $servers = [];

    /** @var array<string, array<string, mixed>> */
    public array $storages = [];

    /** @var array<string, list<array<string, string>>> */
    public array $firewalls = [];

    /** @var list<string> */
    public array $requests = [];

    /** @var array<string, array<string, mixed>> */
    public array $bodies = [];

    /** Requests that reach the provider but lose their response, as "METHOD path". @var list<string> */
    public array $lose = [];

    /** Requests the provider refuses with HTTP 500, as "METHOD path". @var list<string> */
    public array $refuse = [];

    /** POST server requests that are accepted but never create a VM. */
    public bool $dropCreates = false;

    private int $addresses = 10;

    public function install(): self
    {
        Http::fake(['https://api.upcloud.com/*' => fn (Request $request): PromiseInterface => $this->handle($request)]);

        return $this;
    }

    /** @param array<string, mixed> $attributes */
    public function template(string $title, array $attributes = []): string
    {
        $id = (string) Str::uuid();
        $this->storages[$id] = ['uuid' => $id, 'title' => $title, 'type' => 'template', 'state' => 'online', 'access' => 'private', ...$attributes];

        return $id;
    }

    /** How often exactly this request was sent, as "METHOD path". */
    public function count(string $line): int
    {
        return count(array_keys($this->requests, $line, true));
    }

    /** @return list<string> */
    public function sent(string $method, string $prefix = ''): array
    {
        return array_values(array_filter($this->requests, fn (string $line): bool => str_starts_with($line, $method.' '.$prefix)));
    }

    private function handle(Request $request): PromiseInterface
    {
        $path = trim(substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/1.3/')), '/');
        $line = $request->method().' '.$path;
        $this->requests[] = $line;
        $this->bodies[$line] = $request->data();
        if (in_array($line, $this->refuse, true)) {
            return Http::response(['error' => ['error_message' => 'refused']], 500);
        }
        $response = $this->route($request->method(), explode('/', $path), $request->data());
        if (in_array($line, $this->lose, true)) {
            $this->lose = array_values(array_diff($this->lose, [$line]));

            return Http::failedConnection()($request);
        }

        return $response;
    }

    /**
     * @param  list<string>  $segments
     * @param  array<string, mixed>  $data
     */
    private function route(string $method, array $segments, array $data): PromiseInterface
    {
        [$kind, $id, $action] = array_pad($segments, 3, null);
        if ($kind === 'server' && $id === null) {
            return $method === 'POST' ? $this->create($data) : Http::response(['servers' => ['server' => array_values(array_map(
                fn (array $server): array => ['uuid' => $server['uuid'], 'hostname' => $server['hostname'], 'title' => $server['title'], 'state' => $server['state']], $this->servers))]]);
        }
        if ($kind === 'server') {
            if (! isset($this->servers[$id])) {
                return Http::response(['error' => ['error_code' => 'SERVER_NOT_FOUND']], 404);
            }
            if ($action === 'firewall_rule') {
                if ($method === 'PUT') {
                    $this->firewalls[$id] = $data['firewall_rules']['firewall_rule'];
                }

                return Http::response(['firewall_rules' => ['firewall_rule' => $this->firewalls[$id] ?? []]]);
            }
            if ($action === 'stop') {
                $this->servers[$id]['state'] = 'stopped';

                return Http::response(['server' => $this->servers[$id]]);
            }
            if ($method === 'DELETE') {
                if ($this->servers[$id]['state'] !== 'stopped') {
                    return Http::response(['error' => ['error_code' => 'SERVER_STATE_ILLEGAL']], 409);
                }
                foreach ($this->servers[$id]['storage_devices']['storage_device'] as $device) {
                    unset($this->storages[$device['storage']]);
                }
                unset($this->servers[$id], $this->firewalls[$id]);

                return Http::response('', 204);
            }

            return Http::response(['server' => $this->servers[$id]]);
        }
        if ($kind === 'storage' && $id === 'template') {
            return Http::response(['storages' => ['storage' => array_values(array_filter($this->storages, fn (array $storage): bool => $storage['type'] === 'template'))]]);
        }
        if ($kind === 'storage') {
            if (! isset($this->storages[$id])) {
                return Http::response(['error' => ['error_code' => 'STORAGE_NOT_FOUND']], 404);
            }
            if ($action === 'templatize') {
                $template = (string) Str::uuid();
                $this->storages[$template] = ['uuid' => $template, 'title' => $data['storage']['title'], 'type' => 'template', 'state' => 'maintenance', 'access' => 'private'];

                return Http::response(['storage' => $this->storages[$template]], 201);
            }
            if ($method === 'DELETE') {
                unset($this->storages[$id]);

                return Http::response('', 204);
            }
            $storage = $this->storages[$id];
            if ($storage['type'] === 'template' && $storage['state'] === 'maintenance') {
                // Copying finishes after one observation.
                $this->storages[$id]['state'] = 'online';
            }

            return Http::response(['storage' => $storage]);
        }

        return Http::response(['error' => ['error_code' => 'NOT_FOUND']], 404);
    }

    /** @param array<string, mixed> $data */
    private function create(array $data): PromiseInterface
    {
        $id = (string) Str::uuid();
        if ($this->dropCreates) {
            return Http::response(['server' => ['uuid' => $id]], 202);
        }
        $disk = (string) Str::uuid();
        $request = $data['server'];
        $device = $request['storage_devices']['storage_device'][0];
        $this->storages[$disk] = ['uuid' => $disk, 'title' => $device['title'], 'type' => 'normal', 'state' => 'online', 'size' => $device['size'],
            'servers' => ['server' => [$id]]];
        $this->servers[$id] = [
            'uuid' => $id, 'hostname' => $request['hostname'], 'title' => $request['title'], 'state' => 'started', 'firewall' => $request['firewall'],
            'plan' => $request['plan'], 'zone' => $request['zone'], 'labels' => $request['labels'],
            'storage_devices' => ['storage_device' => [['storage' => $disk, 'storage_title' => $device['title'], 'type' => 'disk', 'storage_size' => $device['size']]]],
            'ip_addresses' => ['ip_address' => [['access' => 'public', 'family' => 'IPv4', 'address' => '203.0.113.'.$this->addresses++]]],
        ];

        return Http::response(['server' => $this->servers[$id]], 202);
    }
}
