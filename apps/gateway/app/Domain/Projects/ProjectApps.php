<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\ProjectRoot;

/** Named app configuration, independent of repository or runtime discovery. */
final class ProjectApps
{
    /** @return array{path: string, web_root: ?string} */
    public static function fromRoot(?string $root): array
    {
        if ($root === null || $root === '.') {
            return ['path' => '.', 'web_root' => null];
        }

        if ($root === 'public') {
            return ['path' => '.', 'web_root' => 'public'];
        }
        if (str_ends_with($root, '/public')) {
            return ['path' => substr($root, 0, -strlen('/public')), 'web_root' => 'public'];
        }

        return ['path' => $root, 'web_root' => null];
    }

    /** @return list<array{name: string, path: string, web_root: ?string, type: string}> */
    public static function legacy(?string $root, ProjectType $type): array
    {
        if ($root !== null && ! ProjectRoot::isValid($root, $type)) {
            self::invalid('root', 'The legacy root is invalid for this app type.');
        }
        $paths = self::fromRoot($root ?? (in_array($type, [ProjectType::LaravelApp, ProjectType::Monorepo], true) ? 'public' : '.'));

        return [['name' => 'web', 'path' => $paths['path'], 'web_root' => $paths['web_root'], 'type' => $type->value]];
    }

    /** @return list<array{name: string, path: string, web_root: ?string, type: string}> */
    public static function validate(mixed $apps): array
    {
        if (! is_array($apps) || ! array_is_list($apps) || $apps === []) {
            self::invalid('apps', 'Apps must be a non-empty list.');
        }

        $names = [];
        $paths = [];
        $validated = [];
        foreach ($apps as $index => $app) {
            if (! is_array($app) || count($app) !== 4 || array_diff(['name', 'path', 'web_root', 'type'], array_keys($app)) !== []) {
                self::invalid("apps.{$index}", 'An app must have exactly name, path, web_root and type.');
            }
            $name = $app['name'];
            $path = $app['path'];
            $webRoot = $app['web_root'];
            $type = $app['type'];
            if (! is_string($name) || preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $name) !== 1) {
                self::invalid("apps.{$index}.name", 'The app name must be a lowercase DNS label of 1 through 63 characters.');
            }
            if (in_array($name, $names, true)) {
                self::invalid("apps.{$index}.name", 'App names must be unique within the Project.', 'project.app_name_conflict');
            }
            if (! is_string($path) || ! self::validPath($path, true)) {
                self::invalid("apps.{$index}.path", 'The app path must be a canonical relative directory of 1 through 255 bytes.');
            }
            if (in_array($path, $paths, true)) {
                self::invalid("apps.{$index}.path", 'App paths must be distinct within the Project.', 'project.app_path_conflict');
            }
            if (! is_string($type) || ProjectType::tryFrom($type) === null) {
                self::invalid("apps.{$index}.type", 'The app type is invalid.');
            }
            if ($webRoot !== null && (! is_string($webRoot) || ! self::validPath($webRoot, false))) {
                self::invalid("apps.{$index}.web_root", 'The web root must be a canonical non-dot relative directory or null.');
            }
            if (is_string($webRoot) && strlen($path === '.' ? $webRoot : $path.'/'.$webRoot) > 255) {
                self::invalid("apps.{$index}.web_root", 'The composed serving path must not exceed 255 bytes.');
            }
            $names[] = $name;
            $paths[] = $path;
            $validated[] = ['name' => $name, 'path' => $path, 'web_root' => $webRoot, 'type' => $type];
        }
        usort($validated, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $validated;
    }

    /**
     * @param  list<array{name: string, path: string, web_root: ?string, type: string}>  $apps
     * @return list<array{name: string, path: string, web_root: ?string, type: string}>
     */
    public static function effective(array $apps, mixed $overrides): array
    {
        if (! is_array($overrides)) {
            self::invalid('app_overrides', 'App overrides must be a name-keyed object.');
        }
        $names = array_column($apps, 'name');
        foreach ($overrides as $name => $override) {
            if (! in_array((string) $name, $names, true) || ! is_array($override) || count($override) !== 2 || ! array_key_exists('path', $override) || ! array_key_exists('web_root', $override)) {
                self::invalid('app_overrides', 'An override must name an existing app and have exactly path and web_root.');
            }
        }
        foreach ($apps as &$app) {
            if (array_key_exists($app['name'], $overrides)) {
                $override = $overrides[$app['name']];
                $app = ['name' => $app['name'], 'path' => $override['path'], 'web_root' => $override['web_root'], 'type' => $app['type']];
            }
        }
        unset($app);

        try {
            return self::validate($apps);
        } catch (ResourceOperationException $exception) {
            if ($exception->errorCode === 'project.app_path_conflict') {
                throw $exception;
            }

            throw new ResourceOperationException('instance.app_overrides_invalid', $exception->getMessage(), previous: $exception);
        }
    }

    /** @param array{name: string, path: string, web_root: ?string, type: string} $app */
    public static function isServing(array $app): bool
    {
        return in_array($app['type'], ['laravel-app', 'monorepo'], true) || $app['path'] !== '.' || $app['web_root'] !== null;
    }

    public static function validPath(string $path, bool $allowDot): bool
    {
        if ($path === '.' && $allowDot) {
            return true;
        }
        if (strlen($path) < 1 || strlen($path) > 255 || preg_match('/\A[a-zA-Z0-9._-]+(?:\/[a-zA-Z0-9._-]+)*\z/D', $path) !== 1) {
            return false;
        }

        return ! array_intersect(explode('/', $path), ['.', '..']);
    }

    private static function invalid(string $field, string $message, ?string $code = null): never
    {
        throw new ResourceOperationException(
            errorCode: $code ?? (str_starts_with($field, 'app_overrides') ? 'instance.app_overrides_invalid' : 'project.apps_invalid'),
            message: $message,
            details: ['field' => $field],
        );
    }
}
