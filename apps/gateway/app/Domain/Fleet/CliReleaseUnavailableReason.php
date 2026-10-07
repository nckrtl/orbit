<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

/** Why the Gateway cannot name a published CLI release for its own commit. None of these is an error. */
enum CliReleaseUnavailableReason: string
{
    /** The Gateway version is not a commit its Git repository knows, such as `dev`. */
    case GatewayCommitUnknown = 'gateway_commit_unknown';

    /** The Git repository is shallow or unreadable, so the commit count, and with it the version, is unknown. */
    case HistoryUnavailable = 'history_unavailable';

    /** No release has this tag yet: CI is still running, or the commit has no release. The release is `pending`. */
    case ReleaseMissing = 'release_missing';

    /** The tag points at another commit, so the release does not belong to this one. */
    case ReleaseMismatch = 'release_mismatch';

    /** The release lacks a binary or a valid `SHA256SUMS` line. */
    case ReleaseIncomplete = 'release_incomplete';

    /** GitHub did not answer, or answered with something other than a release. */
    case GitHubUnavailable = 'github_unavailable';
}
