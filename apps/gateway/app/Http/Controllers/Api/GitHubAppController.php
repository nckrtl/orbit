<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\GitHub\CompleteGitHubAppRegistrationAction;
use App\Actions\GitHub\DestroyGitHubAppAction;
use App\Actions\GitHub\InstallGitHubAppAction;
use App\Actions\GitHub\ShowGitHubAppAction;
use App\Domain\GitHub\GitHubAppManifestPage;
use App\Domain\GitHub\GitHubAppRegistration;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\GitHub\InstallGitHubAppRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class GitHubAppController extends Controller
{
    public function install(InstallGitHubAppRequest $request, InstallGitHubAppAction $action): JsonResponse
    {
        $result = $action->handle($request->getSchemeAndHttpHost(), $request->name(), $request->owner());

        return $this->response($request, $result->toArray());
    }

    public function show(Request $request, ShowGitHubAppAction $action): JsonResponse
    {
        return $this->response($request, $action->handle()->toArray());
    }

    public function destroy(Request $request, DestroyGitHubAppAction $action): JsonResponse
    {
        return $this->response($request, $action->handle()->toArray());
    }

    /** The page that sends the App manifest from the operator's browser to GitHub. */
    public function register(Request $request, GitHubAppStore $store): Response
    {
        $registration = $store->registration();
        $state = $request->query('state');

        if (
            ! $registration instanceof GitHubAppRegistration
            || ! is_string($state)
            || ! $registration->matches($state, now()->getTimestamp())
        ) {
            throw $this->registrationInvalid();
        }

        return response(GitHubAppManifestPage::render($registration))
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    /** GitHub redirects the operator's browser here after it registers the App. */
    public function callback(Request $request, CompleteGitHubAppRegistrationAction $action): RedirectResponse
    {
        $state = $request->query('state');
        $code = $request->query('code');

        if (! is_string($state) || ! is_string($code) || $code === '') {
            throw $this->registrationInvalid();
        }

        return redirect()->away($action->handle($state, $code)->installUrl());
    }

    private function registrationInvalid(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'github.registration_invalid',
            message: 'The request does not match a pending GitHub App registration. Run github:app:install again.',
        );
    }

    /** @param array<string, mixed> $data */
    private function response(Request $request, array $data): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
