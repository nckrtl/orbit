<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Services\Git\GitRegistrationDiscovery;
use App\Services\Git\GitRegistrationFacts;
use Illuminate\Contracts\Console\Kernel;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$configuration = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
config()->set('orbit.home', $configuration['home']);
app(GatewayConfigRepository::class)->add(new GatewayProfile('fixture', 'https://fixture.invalid', '/fixture/ca.pem'));
if (isset($configuration['registration_facts'])) {
    $facts = new GitRegistrationFacts(...$configuration['registration_facts']);
    app()->instance(GitRegistrationDiscovery::class, new readonly class($facts) implements GitRegistrationDiscovery
    {
        public function __construct(private GitRegistrationFacts $facts) {}

        public function inspect(string $path): ?GitRegistrationFacts
        {
            return $this->facts;
        }
    });
}
$replies = $configuration['replies'];
MockClient::global(['*' => static function (PendingRequest $pending) use (&$replies, $configuration): MockResponse {
    $request = $pending->getRequest();
    file_put_contents($configuration['trace'], json_encode([
        'class' => $request::class,
        'method' => $pending->getMethod()->value,
        'url' => $pending->getUrl(),
        'body' => method_exists($request, 'body') ? $request->body()->all() : [],
    ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
    $reply = array_shift($replies);

    if ($reply === null || $request::class !== $reply['class']) {
        throw new RuntimeException('Unexpected fixture request.');
    }

    usleep($configuration['delay_us'] ?? 0);

    return MockResponse::make($reply['body'], $reply['status'] ?? 200,
        ['X-Orbit-Request-Id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']);
}]);
$input = new ArgvInput(['orbit', ...$configuration['arguments']]);
$status = $kernel->handle($input, new ConsoleOutput);
$kernel->terminate($input, $status);
exit($status);
