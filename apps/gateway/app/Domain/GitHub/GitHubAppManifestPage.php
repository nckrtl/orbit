<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

/**
 * The HTML page that posts the App manifest to GitHub. GitHub accepts a manifest only as a form post
 * from the browser of the person who will own the App. The manifest asks for read access to
 * repository contents and metadata, no webhook, and a public App that other accounts can install
 * ([ADR 0098](/decisions/0098-read-github-repositories-through-a-gateway-owned-github-app)).
 * An empty `default_events` list is omitted: GitHub's convert endpoint rejects `[]`.
 */
final readonly class GitHubAppManifestPage
{
    private const string HOMEPAGE = 'https://orbit.nckrtl.com';

    /** @return array<string, mixed> */
    public static function manifest(GitHubAppRegistration $registration): array
    {
        return [
            'name' => $registration->name,
            'url' => self::HOMEPAGE,
            'description' => 'Lets this Orbit Gateway read repositories for clones and deployments.',
            'redirect_url' => "{$registration->gatewayUrl}/api/v1/github/app/callback",
            'public' => true,
            'default_permissions' => [
                'checks' => 'read',
                'contents' => 'write',
                'metadata' => 'read',
                'pull_requests' => 'write',
            ],
        ];
    }

    public static function action(GitHubAppRegistration $registration): string
    {
        $base = $registration->owner === null
            ? 'https://github.com/settings/apps/new'
            : 'https://github.com/organizations/'.rawurlencode($registration->owner).'/settings/apps/new';

        return $base.'?state='.rawurlencode($registration->state);
    }

    public static function render(GitHubAppRegistration $registration): string
    {
        $action = htmlspecialchars(self::action($registration), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $manifest = htmlspecialchars(
            json_encode(self::manifest($registration), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        );

        return <<<HTML
            <!doctype html>
            <html lang="en">
            <head><meta charset="utf-8"><title>Register the Orbit GitHub App</title></head>
            <body>
            <form id="manifest" action="{$action}" method="post">
            <input type="hidden" name="manifest" value="{$manifest}">
            <p>Orbit sends the App definition to GitHub. Confirm it there.</p>
            <button type="submit">Continue to GitHub</button>
            </form>
            <script>document.getElementById('manifest').submit();</script>
            </body>
            </html>
            HTML;
    }
}
