<?php

declare(strict_types=1);

namespace App\Support;

use Orbit\Sdk\Responses\Projects\ProjectAppResponse;

/** Parses the JSON `--apps` and `--app-overrides` options and renders apps for people. */
final class NamedAppOptions
{
    /**
     * A JSON list of apps, each with exactly name, path, web_root and type. Null when invalid.
     *
     * @return list<array{name: string, path: string, web_root: string|null, type: string}>|null
     */
    public static function apps(string $json): ?array
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded) || ! array_is_list($decoded) || $decoded === []) {
            return null;
        }

        $apps = [];

        foreach ($decoded as $app) {
            if (
                ! is_array($app)
                || count($app) !== 4
                || ! is_string($app['name'] ?? null)
                || ! is_string($app['path'] ?? null)
                || ! array_key_exists('web_root', $app)
                || ! (is_string($app['web_root']) || $app['web_root'] === null)
                || ! is_string($app['type'] ?? null)
            ) {
                return null;
            }

            $apps[] = ['name' => $app['name'], 'path' => $app['path'], 'web_root' => $app['web_root'], 'type' => $app['type']];
        }

        return $apps;
    }

    /**
     * A JSON object keyed by app name; each value has exactly path and web_root. Null when invalid.
     *
     * @return array<string, array{path: string, web_root: string|null}>|null
     */
    public static function overrides(string $json): ?array
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return null;
        }

        $overrides = [];

        foreach ($decoded as $name => $override) {
            if (
                ! is_string($name)
                || ! is_array($override)
                || count($override) !== 2
                || ! is_string($override['path'] ?? null)
                || ! array_key_exists('web_root', $override)
                || ! (is_string($override['web_root']) || $override['web_root'] === null)
            ) {
                return null;
            }

            $overrides[$name] = ['path' => $override['path'], 'web_root' => $override['web_root']];
        }

        return $overrides;
    }

    /** `web: apps/site · web root public · laravel-app` */
    public static function describe(ProjectAppResponse $app): string
    {
        $webRoot = $app->webRoot === null ? 'no web root' : "web root {$app->webRoot}";

        return "{$app->name}: {$app->path} · {$webRoot} · {$app->type}";
    }

    /**
     * @param  list<ProjectAppResponse>  $apps
     * @return list<string>|null
     */
    public static function describeAll(array $apps): ?array
    {
        $lines = array_map(self::describe(...), $apps);

        return $lines === [] ? null : $lines;
    }

    /**
     * Human detail fields with one line per app, so app descriptions are never joined with commas.
     *
     * @param  list<ProjectAppResponse>  $apps
     * @return array<string, string|null>
     */
    public static function detailFields(array $apps): array
    {
        if ($apps === []) {
            return ['Apps' => null];
        }

        $fields = [];
        foreach ($apps as $app) {
            $fields["App {$app->name}"] = substr(self::describe($app), strlen($app->name) + 2);
        }

        return $fields;
    }
}
