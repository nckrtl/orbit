<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('refuses unsafe or conflicting database fixtures before mutation and keeps credentials private', function (string $scenario): void {
    $root = temporaryPath('orbit-sample-fixtures-', 6);
    mkdir($root.'/bin', 0700, true);
    mkdir($root.'/.orbit', 0700);
    $secret = str_repeat('a', 48);
    $credentials = array_fill_keys(['mysql_root', 'mysql_app', 'postgres_root', 'postgres_app', 'valkey'], $secret);
    if ($scenario !== 'lost credentials') {
        file_put_contents($root.'/.orbit/e2e-database-secrets.json', json_encode($credentials, JSON_THROW_ON_ERROR));
        chmod($root.'/.orbit/e2e-database-secrets.json', $scenario === 'unsafe permissions' ? 0644 : 0600);
    }
    file_put_contents($root.'/bin/php', <<<'PYTHON'
#!/usr/bin/env python3
import json, os, sys
arguments=json.load(sys.stdin)
with open(os.environ['FIXTURE_COMMANDS'],'a') as log: log.write(arguments[1]+'\n')
if arguments[1]=='node:list':
    print(json.dumps({'nodes':[{'id':2,'name':'app-dev','status':'active','roles':['database'],'wireguard_ip':'10.44.0.2'}]}))
elif arguments[1]=='process:list':
    print(json.dumps({'processes':[] if os.environ['FIXTURE_SCENARIO']=='missing process' else [{'id':1,'name':'e2e-mysql','target_type':'node','target_id':2,'runtime':'docker','runtime_config':{'image':'foreign-image'},'restart_policy':'unless-stopped'}]}))
else:
    sys.exit(90)
PYTHON);
    chmod($root.'/bin/php', 0700);
    $source = file_get_contents(dirname(__DIR__, 2).'/resources/guest/converge-sample-fixtures.sh');
    $source = str_replace('/home/orbit', $root, $source);
    $source = preg_replace('/^\[\[ "\$\(id -u\)" -eq 0 \]\].*$/m', '', $source);
    file_put_contents($root.'/helper.sh', $source);
    $process = new Process(['bash', $root.'/helper.sh', $scenario === 'missing process' ? 'verify' : 'converge'], env: [
        'PATH' => $root.'/bin:'.getenv('PATH'),
        'FIXTURE_COMMANDS' => $root.'/commands',
        'FIXTURE_SCENARIO' => $scenario,
    ]);
    try {
        expect($process->run())->toBe(65)
            ->and($process->getOutput().$process->getErrorOutput())->not->toContain($secret)
            ->and(file($root.'/commands', FILE_IGNORE_NEW_LINES))->each->toBeIn(['node:list', 'process:list']);
        if ($scenario === 'lost credentials') {
            expect(file_exists($root.'/.orbit/e2e-database-secrets.json'))->toBeFalse();
        } else {
            expect(json_decode(file_get_contents($root.'/.orbit/e2e-database-secrets.json'), true, 16, JSON_THROW_ON_ERROR))->toBe($credentials);
        }
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
})->with(['lost credentials', 'unsafe permissions', 'conflicting process', 'missing process']);

it('checks sleeping queue workers through Doctor without changing their desired state', function (string $desiredState, bool $keepAlive, bool $healthy, int $exitCode, bool $checksDoctor): void {
    $root = temporaryPath('orbit-sleeping-fixtures-', 6);
    mkdir($root.'/bin', 0700, true);
    mkdir($root.'/.orbit', 0700);
    file_put_contents($root.'/.orbit/e2e-database-secrets.json', json_encode(array_fill_keys(
        ['mysql_root', 'mysql_app', 'postgres_root', 'postgres_app', 'valkey'], str_repeat('a', 48),
    ), JSON_THROW_ON_ERROR));
    chmod($root.'/.orbit/e2e-database-secrets.json', 0600);
    file_put_contents($root.'/bin/php', <<<'PYTHON'
#!/usr/bin/env python3
import json, os, sys
if len(sys.argv)<3 or '$kernel' not in sys.argv[2]: sys.exit(0)
args=json.load(sys.stdin)[1:]
with open(os.environ['FIXTURE_COMMANDS'],'a') as log: log.write(args[0]+'\n')
names=['e2e-mysql','e2e-postgres','e2e-valkey']
ports=[3306,5432,6379]
commands=[['mysqld','--innodb-buffer-pool-size=67108864','--max-connections=30','--mysqlx=0','--performance-schema=OFF'],['postgres','-c','shared_buffers=32MB','-c','max_connections=30'],['sh','-ec','exec valkey-server --requirepass "$VALKEY_PASSWORD" --appendonly yes --maxmemory 64mb --maxmemory-policy allkeys-lru']]
processes=[dict(id=i+1,name=name,target_type='node',target_id=2,runtime='docker',restart_policy='unless-stopped',status='active',runtime_status='running',runtime_config=dict(image=['mysql:8.4','postgres:18-alpine','valkey/valkey:8-alpine'][i],command=commands[i],ports=[f'10.44.0.2:{ports[i]}:{ports[i]}'],volumes=[dict(source=name+'-data',target=['/var/lib/mysql','/var/lib/postgresql','/data'][i],read_only=False)])) for i,name in enumerate(names)]
connections=[dict(slug=name,driver=['mysql','pgsql','redis'][i],node_id=2,host='10.44.0.2',port=ports[i],database='1' if i==2 else 'orbit_e2e',username='default' if i==2 else 'orbit_e2e') for i,name in enumerate(names)]
instances=[dict(id=i+1,name=name,app_id=1 if i<2 else i,node_id=3 if i==1 else 2,status='active',route=None,checkout_path=os.environ['FIXTURE_ROOT']) for i,name in enumerate(['e2e-dev','e2e-prod','e2e-monorepo','e2e-package'])]
worker=dict(id=4,name='e2e-queue',target_id=1,runtime='systemd',runtime_status='inactive',status='active',desired_state=os.environ['DESIRED_STATE'],keep_alive=os.environ['KEEP_ALIVE']=='1',runtime_config=dict(command=['/usr/bin/php','artisan','queue:work','redis','--sleep=3','--tries=1','--max-time=3600']))
schedule=dict(id=1,name='e2e-scheduler',target_type='instance',target_id=1,status='active',desired_timer_state='enabled',command='/usr/bin/php artisan schedule:run',calendar='*-*-* *:*:00')
responses={
 'node:list':dict(nodes=[dict(id=2,name='app-dev',status='active',roles=['database'],wireguard_ip='10.44.0.2')]),
 'process:list':dict(processes=[worker] if '--instance=1' in args else processes),
 'database:list':dict(connections=connections),'database:query':{},'instance:list':dict(instances=instances),
 'project:list':dict(projects=[dict(id=2,slug='e2e-monorepo',type='monorepo',repository_url='https://github.com/laravel/wayfinder.git'),dict(id=3,slug='e2e-package',type='laravel-package',repository_url='https://github.com/spatie/laravel-package-tools.git')]),
 'schedule:list':dict(schedules=[schedule]),'schedule:show':schedule,
 'doctor':dict(healthy=os.environ['DOCTOR_HEALTHY']=='1',summary=dict(checks=4)),
}
if args[0] not in responses: sys.exit(90)
print(json.dumps(responses[args[0]]))
PYTHON);
    file_put_contents($root.'/bin/sudo', "#!/bin/sh\nprintf 'PONG\\n'\n");
    chmod($root.'/bin/php', 0700);
    chmod($root.'/bin/sudo', 0700);
    $source = str_replace('/home/orbit', $root, file_get_contents(dirname(__DIR__, 2).'/resources/guest/converge-sample-fixtures.sh'));
    $source = preg_replace('/^\[\[ "\$\(id -u\)" -eq 0 \]\].*$/m', '', $source);
    file_put_contents($root.'/helper.sh', $source);
    $process = new Process(['bash', $root.'/helper.sh', 'verify'], env: [
        'PATH' => $root.'/bin:'.getenv('PATH'),
        'FIXTURE_COMMANDS' => $root.'/commands',
        'FIXTURE_ROOT' => $root,
        'DESIRED_STATE' => $desiredState,
        'KEEP_ALIVE' => $keepAlive ? '1' : '0',
        'DOCTOR_HEALTHY' => $healthy ? '1' : '0',
    ]);
    try {
        expect($process->run())->toBe($exitCode, $process->getErrorOutput());
        $commands = file($root.'/commands', FILE_IGNORE_NEW_LINES);
        expect(in_array('doctor', $commands, true))->toBe($checksDoctor)
            ->and($commands)->not->toContain('process:start', 'process:stop', 'process:create');
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
})->with([
    'healthy sleeping worker' => ['running', false, true, 0, true],
    'unhealthy sleeping worker' => ['running', false, false, 65, true],
    'operator stopped worker' => ['stopped', false, true, 65, false],
    'stopped keep-alive worker' => ['running', true, true, 65, false],
]);
