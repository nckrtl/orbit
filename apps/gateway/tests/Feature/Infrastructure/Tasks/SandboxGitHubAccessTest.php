<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubCliToken;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Tasks\TaskPullRequestException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\GitHubTaskBaseBranchFetcher;
use App\Infrastructure\Tasks\GitHubTaskPullRequestPublisher;
use App\Models\Task;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\UpCloudRuntimeWorkspace;

use function Pest\Laravel\mock;

it('fetches and publishes directly inside the owned UpCloud VM with fresh protected access', function (): void {
    $workspace = UpCloudRuntimeWorkspace::create();
    $group = $workspace->taskSandbox->group;
    $group->project->update(['source_access' => 'gh_cli']);
    mock(GitHubCliToken::class)->shouldNotReceive('token');
    GitHubTestSupport::storeApp();
    $github = mock(GitHubApi::class);
    $github->shouldReceive('repositoryInstallation')->twice()->andReturn(9);
    $github->shouldReceive('repositoryReadToken')->once()->andReturn('fresh-read-secret');
    $github->shouldReceive('repositoryPullRequestToken')->once()->andReturn('fresh-write-secret');
    mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/keys/private');
    mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/keys/known_hosts');
    $steps = [];
    mock(SshExecutor::class)->shouldReceive('execute')->twice()->andReturnUsing(function ($connection, RemoteCommand $command) use ($workspace, $group, &$steps): CommandResult {
        expect($connection->host)->toBe($workspace->node->wireguard_ip);
        expect($command->input)->toBeNull();
        expect(implode(' ', $command->arguments))->not->toContain('secret');
        $script = stream_get_contents($command->protectedInput->stream());
        expect($script)->not->toContain('bundle');
        if (count($steps) === 0) {
            expect($script)->toContain('fetch --no-tags', 'ls-remote --exit-code', base64_encode('x-access-token:fresh-read-secret'));
            expect($command->arguments)->toBe(['bash', '-seu', '--', '/home/orbit/orbit', 'main', 'task-'.$group->id, '']);
            $steps[] = 'fetch';
        } else {
            expect($script)->toContain('push --quiet', base64_encode('x-access-token:fresh-write-secret'));
            expect($command->arguments)->toBe(['bash', '-seu', '--', '/home/orbit/orbit', 'task-'.$group->id, str_repeat('c', 40)]);
            $steps[] = 'push';
        }

        return new CommandResult(0, '', '', 1, false);
    });
    app(GitHubTaskBaseBranchFetcher::class)->fetchForTurn($group);
    app(GitHubTaskPullRequestPublisher::class)->push($group, str_repeat('c', 40));
    expect($steps)->toBe(['fetch', 'push']);
});

it('renews only its Project access and does not record token issuance in activity', function (): void {
    $workspace = UpCloudRuntimeWorkspace::create();
    $workspace->taskSandbox->group->update(['status' => 'running']);
    GitHubTestSupport::storeApp();
    $github = mock(GitHubApi::class);
    $github->shouldReceive('repositoryInstallation')->twice()->withArgs(fn ($credentials, $repository) => $repository->owner === 'acme' && $repository->name === 'dlf')->andReturn(9);
    $github->shouldReceive('repositorySandboxToken')->twice()->andReturn('first-token', 'second-token');
    $this->withServerVariables(['REMOTE_ADDR' => $workspace->node->wireguard_ip]);
    $this->withToken(str_repeat('a', 64))->postJson('/api/v1/compute/github-token', ['repository' => 'other/private'])
        ->assertOk()->assertExactJson(['token' => 'first-token'])->assertHeader('Cache-Control', 'no-store, private');
    $this->withToken(str_repeat('a', 64))->postJson('/api/v1/compute/github-token')->assertOk()->assertExactJson(['token' => 'second-token']);
    $this->assertDatabaseCount('activity_log', 0);
});

it('refuses credential renewal from a wrong peer, wrong secret, or unavailable ownership', function (string $fault): void {
    $workspace = UpCloudRuntimeWorkspace::create();
    $sandbox = $workspace->taskSandbox;
    $sandbox->group->update(['status' => 'running']);
    $address = $workspace->node->wireguard_ip;
    $secret = str_repeat('a', 64);
    match ($fault) {
        'access' => $workspace->node->accessibleNodes()->detach(),
        'peer' => $address = '10.44.0.200',
        'secret' => $secret = str_repeat('c', 64),
        'stopped' => $sandbox->update(['state' => 'stopped']),
        'destroying' => $sandbox->update(['desired_power' => 'destroyed']),
        'detached' => $sandbox->group->update(['taskable_id' => null]),
        'completed' => $sandbox->group->update(['status' => 'completed']),
        'backlog' => $sandbox->group->update(['status' => 'backlog']),
        'revoked' => $sandbox->forceFill(['model_key_revoked_at' => now()])->save(),
    };
    mock(GitHubApi::class)->shouldNotReceive('repositoryInstallation');
    $this->withServerVariables(['REMOTE_ADDR' => $address])->withToken($secret)
        ->postJson('/api/v1/compute/github-token')->assertForbidden();
})->with(['access', 'peer', 'secret', 'stopped', 'destroying', 'detached', 'completed', 'backlog', 'revoked']);

it('mints a fresh single-repository installation token including Actions read', function (): void {
    GitHubTestSupport::storeApp();
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/dlf/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::sequence()->push(['token' => 'first'])->push(['token' => 'second']),
    ]);
    $access = app(RepositoryPullRequestAccess::class);
    $repository = GitHubRepository::fromOrigin('https://github.com/acme/dlf.git');
    expect($access->sandboxToken($repository))->toBe('first');
    expect($access->sandboxToken($repository))->toBe('second');
    Http::assertSentCount(4);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/access_tokens') && $request->data() === [
        'repositories' => ['dlf'], 'permissions' => ['contents' => 'write', 'pull_requests' => 'write', 'workflows' => 'write', 'actions' => 'read'],
    ]);
});

it('limits the Git credential helper to its exact repository and renews each authentication', function (): void {
    $script = <<<'PY'
import contextlib,io,runpy,sys
m=runpy.run_path(sys.argv[1],run_name='test')
n=m['git'].__globals__
calls=[]
def fresh(request):
    calls.append(request['repository'])
    return 'temporary_'+str(len(calls))
n['token']=fresh
request={'repository':'acme/dlf'}
for fields in ['protocol=https\nhost=github.com\npath=acme/other.git\n\n', 'protocol=https\nhost=evil.test\npath=acme/dlf.git\n\n']:
    output=io.StringIO()
    with contextlib.redirect_stdout(output): m['git'](request,'get',io.StringIO(fields))
    assert not output.getvalue()
assert not calls
for i in [1,2]:
    output=io.StringIO()
    with contextlib.redirect_stdout(output): m['git'](request,'get',io.StringIO('protocol=https\nhost=github.com\npath=acme/dlf.git\n\n'))
    assert output.getvalue()=='username=x-access-token\npassword=temporary_'+str(i)+'\n'
m['git'](request,'store',io.StringIO('password=never-store\n\n'))
assert calls==['acme/dlf','acme/dlf']
print('ok')
PY;
    $result = new Process(['python3', '-I', '-c', $script, resource_path('compute/guest-github-access.py')]);
    $result->mustRun();
    expect(trim($result->getOutput()))->toBe('ok');
});

it('renews over verified TLS without following redirects or sending credentials to a wrong TLS name', function (): void {
    $script = <<<'PY'
import http.server,json,pathlib,runpy,socket,ssl,subprocess,sys,tempfile,threading
m=runpy.run_path(sys.argv[1],run_name='test')
g=m['token'].__globals__
with tempfile.TemporaryDirectory() as directory:
    root=pathlib.Path(directory)
    cert,key=root/'ca.pem',root/'key.pem'
    subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-days','1','-subj','/CN=gateway.orbit','-addext','subjectAltName=DNS:gateway.orbit'],check=True,capture_output=True)
    secret='a'*64
    (root/'token').write_text(secret)
    (root/'token').chmod(0o600)
    g['PI']=root
    requests=[]
    class Handler(http.server.BaseHTTPRequestHandler):
        def do_POST(self):
            assert self.path=='/api/v1/compute/github-token'
            assert self.headers['Authorization']=='Bearer '+secret
            requests.append(self.path)
            self.send_response(302 if len(requests)==3 else 200)
            self.send_header('Location','https://untrusted.test/token')
            self.end_headers()
            self.wfile.write(json.dumps({'token':'fresh_'+str(len(requests))}).encode())
        def log_message(self,*args): pass
    server=http.server.HTTPServer(('127.0.0.1',0),Handler)
    context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(cert,key)
    server.socket=context.wrap_socket(server.socket,server_side=True)
    thread=threading.Thread(target=server.serve_forever,daemon=True)
    thread.start()
    connect=socket.create_connection
    def pinned(address,timeout):
        assert address==('10.44.0.2',443)
        return connect(('127.0.0.1',server.server_port),timeout=timeout)
    socket.create_connection=pinned
    request={'url':'https://gateway.orbit/api/v1/compute/github-token','gateway_address':'10.44.0.2','ca':cert.read_text()}
    try:
        assert m['token'](request)=='fresh_1'
        assert m['token'](request)=='fresh_2'
        try:
            m['token'](request)
            raise AssertionError('Redirect was accepted')
        except ValueError: pass
        try:
            m['token'](dict(request,url='https://wrong.orbit/api/v1/compute/github-token'))
            raise AssertionError('Wrong TLS name was accepted')
        except ssl.SSLCertVerificationError: pass
        assert len(requests)==3
    finally:
        socket.create_connection=connect
        server.shutdown()
        server.server_close()
print('ok')
PY;
    $result = new Process(['python3', '-I', '-c', $script, resource_path('compute/guest-github-access.py')]);
    $result->mustRun();
    expect(trim($result->getOutput()))->toBe('ok');
});

it('refuses direct Git operations for a group borrowing another sandbox workspace', function (): void {
    $workspace = UpCloudRuntimeWorkspace::create();
    $foreign = Task::topLevel()->create(['project_id' => $workspace->project_id, 'title' => 'Foreign', 'brief' => 'Work', 'status' => 'running', 'task_compute' => 'vm']);
    $foreign->taskable()->associate($workspace);
    $foreign->save();
    mock(GitHubApi::class)->shouldNotReceive('repositoryInstallation');
    mock(SshExecutor::class)->shouldNotReceive('execute');
    expect(fn () => app(GitHubTaskBaseBranchFetcher::class)->fetchForTurn($foreign))->toThrow(TaskPullRequestException::class);
    expect(fn () => app(GitHubTaskBaseBranchFetcher::class)->fetch($foreign, 'main'))->toThrow(TaskPullRequestException::class);
    expect(fn () => app(GitHubTaskPullRequestPublisher::class)->push($foreign, str_repeat('c', 40)))->toThrow(TaskPullRequestException::class);
});

it('refuses VM fetch without an installed App instead of borrowing a shared login', function (): void {
    $workspace = UpCloudRuntimeWorkspace::create();
    mock(SshExecutor::class)->shouldNotReceive('execute');
    mock(GitHubCliToken::class)->shouldNotReceive('token');
    expect(fn () => app(GitHubTaskBaseBranchFetcher::class)->fetchForTurn($workspace->taskSandbox->group))->toThrow(TaskPullRequestException::class);
});
