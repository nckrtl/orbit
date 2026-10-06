<?php

declare(strict_types=1);
use App\Domain\Tasks\TaskReviewRequestLogins;
use App\Domain\Tasks\TaskReviewTrust;

$implementerEffort = env('ORBIT_TASKS_IMPLEMENTER_EFFORT');
$reviewerEffort = env('ORBIT_TASKS_REVIEWER_EFFORT');
$configuredHome = env('ORBIT_HOME');
$userHome = getenv('HOME');
$orbitHome = base_path('.orbit');

if (is_string($userHome) && $userHome !== '') {
    $orbitHome = $userHome.'/.orbit';
}

if (is_string($configuredHome) && $configuredHome !== '') {
    $orbitHome = $configuredHome;
}

return [
    'home' => rtrim(string: $orbitHome, characters: '/'),
    // Private ephemeral authorization, never in ORBIT_HOME or database backups.
    'document_cleanup_runtime' => env('APP_ENV') === 'testing'
        ? env('ORBIT_DOCUMENT_CLEANUP_RUNTIME', '/run/orbit/project-documents')
        : '/run/orbit/project-documents',
    'gateway_checkout' => rtrim(
        string: env(key: 'ORBIT_GATEWAY_CHECKOUT', default: '/home/orbit/orbit-gateway'),
        characters: '/',
    ),
    'gateway_web' => rtrim(
        string: env(key: 'ORBIT_GATEWAY_WEB', default: '/home/orbit/web'),
        characters: '/',
    ),
    'app_dev_domain' => trim(
        string: env(key: 'ORBIT_APP_DEV_DOMAIN', default: 'orbit'),
        characters: '.',
    ),
    // PHP-FPM and Caddy end a Gateway request after 600 seconds. The command deadline ends remote
    // work 30 seconds earlier, so a slow command fails with an error and records its Activity.
    'command_timeout' => 570.0,
    // PHP-FPM checks its 600-second request limit on a heartbeat of a third of it, so a Gateway request,
    // streamed deployments included, ends by about 800 seconds. An Activity still `running` at 900
    // seconds belongs to a request that was killed.
    'activity_interrupted_after' => 900,
    'websocket' => [
        'repository' => env(key: 'ORBIT_WEBSOCKET_REPOSITORY', default: 'https://github.com/nckrtl/orbit-reverb.git'),
        'ref' => env(key: 'ORBIT_WEBSOCKET_REF', default: 'main'),
        'install_path' => env(key: 'ORBIT_WEBSOCKET_INSTALL_PATH', default: '/opt/orbit/websocket'),
        'port' => max(1, (int) env('ORBIT_WEBSOCKET_PORT', 8790)),
    ],
    'analytics' => [
        // The Plausible Community Edition release a new analytics role runs; `analytics:update` pins another.
        'plausible_version' => env(key: 'ORBIT_ANALYTICS_PLAUSIBLE_VERSION', default: '3.2.1'),
    ],
    't3' => [
        'port' => max(1, (int) env('ORBIT_T3_PORT', 3773)),
        'token' => env('ORBIT_T3_TOKEN'),
    ],
    'pi' => [
        'port' => max(1, (int) env('ORBIT_PI_PORT', 3774)),
        'token' => env('ORBIT_PI_TOKEN'),
        // A models.json provider such as a CLIProxyAPI endpoint. Plain model names use it when set.
        'provider' => env('ORBIT_PI_PROVIDER'),
    ],
    'tasks' => [
        // Operator-owned repair trust: lower-case github.com owner/repository => list of positive integer account IDs,
        // from `owner/repo:id,id;owner/repo:id`. Empty disables review feedback. No logins, wildcards, roles, or
        // branch-provided settings.
        'github_reviewers' => TaskReviewTrust::parseEnv(env('ORBIT_TASKS_GITHUB_REVIEWERS')),
        // Comma-separated GitHub logins requested as reviewers after a task pull request is opened or reused.
        // Unset or empty requests no one. The pull request author is omitted at request time.
        'review_request_logins' => TaskReviewRequestLogins::parseEnv(env('ORBIT_TASKS_REVIEW_REQUEST_LOGINS')),
        'worker_user' => env('ORBIT_TASKS_WORKER_USER'),
        'implementer_agent_driver' => env('ORBIT_TASKS_IMPLEMENTER_AGENT_DRIVER', 'pi'),
        'reviewer_agent_driver' => env('ORBIT_TASKS_REVIEWER_AGENT_DRIVER', 'pi'),
        // Models for new groups. Unset keeps TaskAgentDefaults.
        'implementer_model' => env('ORBIT_TASKS_IMPLEMENTER_MODEL'),
        'reviewer_model' => env('ORBIT_TASKS_REVIEWER_MODEL'),
        // Effort for new threads. Existing threads keep their stored value.
        'implementer_effort' => $implementerEffort === null || $implementerEffort === '' ? 'high' : $implementerEffort,
        'reviewer_effort' => $reviewerEffort === null || $reviewerEffort === '' ? 'high' : $reviewerEffort,
        'observation_grace_seconds' => (int) env('ORBIT_TASKS_OBSERVATION_GRACE_SECONDS', 120),
        // A group reserved longer than this returns to todo on the next tick. Keep it well above the slowest workspace provision.
        'reserved_timeout_seconds' => max(60, (int) env('ORBIT_TASKS_RESERVED_TIMEOUT_SECONDS', 3600)),
        'coder_webhook_url' => env('ORBIT_CODER_WEBHOOK_URL'),
        'coder_webhook_secret' => env('ORBIT_CODER_WEBHOOK_SECRET'),
        'opsbot_webhook_url' => env('ORBIT_OPSBOT_WEBHOOK_URL'),
        'opsbot_webhook_secret' => env('ORBIT_OPSBOT_WEBHOOK_SECRET'),
    ],
    // The outer loop neither counts nor files a listed fingerprint or a source path under a prefix.
    'problems' => [
        'suppressed_fingerprints' => [],
        'suppressed_path_prefixes' => [
            'app/Infrastructure/Tasks/T3/',
        ],
    ],
    'hibernation' => [
        'idle_seconds' => max(1, (int) env('ORBIT_HIBERNATION_IDLE_SECONDS', 3600)),
        'sweep_seconds' => max(60, (int) env('ORBIT_HIBERNATION_SWEEP_SECONDS', 600)),
        'wake_timeout_seconds' => max(5, (int) env('ORBIT_HIBERNATION_WAKE_TIMEOUT_SECONDS', 60)),
        'dependency_idle_seconds' => max(1, (int) env('ORBIT_HIBERNATION_DEPENDENCY_IDLE_SECONDS', 604800)),
        'cold_wake_timeout_seconds' => max(60, (int) env('ORBIT_HIBERNATION_COLD_WAKE_TIMEOUT_SECONDS', 1800)),
    ],
];
