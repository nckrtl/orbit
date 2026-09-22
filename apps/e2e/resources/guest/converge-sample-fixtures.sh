#!/usr/bin/env bash
set -euo pipefail
umask 077
cd /
[[ $# -eq 1 && ( "$1" == converge || "$1" == verify ) ]] || exit 64
[[ "$(id -u)" -eq 0 ]] && exec sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit bash "$0" "$@"
python3 - "$1" <<'PYTHON'
import sys
try:
    import json, os, secrets, subprocess, time, re, sys
    from pathlib import Path
    os.umask(0o077)
    mode = sys.argv[1] if len(sys.argv) == 2 else ''
    if mode not in ['converge', 'verify']: raise RuntimeError('Expected converge or verify')
    verify = mode == 'verify'
    os.chdir('/home/orbit')
    os.environ.update(HOME='/home/orbit', ORBIT_HOME='/home/orbit/.orbit')

    def run(args, data=None, check=True, env=None):
        r = subprocess.run(args, input=data, capture_output=True, text=True, timeout=900, env=env)
        if check and r.returncode:
            code = 'command-failed'
            try:
                code = json.loads(r.stdout).get('error',{}).get('code',code)
            except Exception:
                pass
            if not isinstance(code,str) or not re.fullmatch(r'[a-z0-9._-]+',code): code='command-failed'
            raise RuntimeError(args[0]+' '+(args[1] if len(args)>1 else '')+': '+code)
        return r

    def orbit(*args):
        # Feed arguments through stdin so passwords never enter process argv.
        program = r"""define('LARAVEL_START', microtime(true));
require '/home/orbit/orbit/apps/cli/vendor/autoload.php';
$app = require '/home/orbit/orbit/apps/cli/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$arguments = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$input = new Symfony\Component\Console\Input\ArgvInput($arguments);
$status = $kernel->handle($input, new Symfony\Component\Console\Output\ConsoleOutput);
$kernel->terminate($input, $status);
exit($status);"""
        result = run(['php','-r',program], json.dumps(['orbit',*args,'--json']), check=False)
        if result.returncode:
            try: code=json.loads(result.stdout)['error']['code']
            except Exception: code='command-failed'
            if not isinstance(code,str) or not re.fullmatch('[a-z0-9._-]+',code): code='command-failed'
            raise RuntimeError('Orbit '+args[0]+': '+code)
        require(result.stdout.strip() != '', 'Orbit '+args[0]+' returned no evidence')
        if args[0] == 'instance:deploy':
            events=[json.loads(line) for line in result.stdout.splitlines()]
            require(events and events[-1].get('status') == 'succeeded', 'Production deployment did not succeed')
            return events[-1]
        try: return json.loads(result.stdout)
        except json.JSONDecodeError: raise RuntimeError('Invalid JSON from Orbit '+args[0])

    def docker(*args, data=None, check=True):
        return run(['sudo','docker',*args],data,check)

    def unique(rows, key, value):
        matches = [row for row in rows if row.get(key) == value]
        if len(matches) > 1: raise RuntimeError('Ambiguous fixture identity')
        return matches[0] if matches else None

    def require(condition, message):
        if not condition: raise RuntimeError(message)

    nodes=orbit('node:list')['nodes']
    node=unique(nodes, 'name', 'app-dev')
    require(node is not None and node['status']=='active' and 'database' in node['roles'] and node['wireguard_ip']=='10.44.0.2', 'Invalid database Node')
    secret_path=Path('/home/orbit/.orbit/e2e-database-secrets.json')
    if not secret_path.exists():
        require(not verify, 'Missing database credentials')
        existing=orbit('process:list','--node=app-dev')['processes']
        if any(p['name'] in ['e2e-mysql','e2e-postgres','e2e-valkey'] for p in existing):
            raise RuntimeError('Sample database Processes exist without their credential file; refusing to replace credentials.')
        values={k:secrets.token_hex(24) for k in ['mysql_root','mysql_app','postgres_root','postgres_app','valkey']}
        with secret_path.open('x') as f: json.dump(values,f)
    require(not secret_path.is_symlink() and secret_path.stat().st_uid == os.getuid() and secret_path.stat().st_mode & 0o077 == 0, 'Unsafe credential file')
    creds=json.loads(secret_path.read_text())
    require(set(creds) == {'mysql_root','mysql_app','postgres_root','postgres_app','valkey'} and all(isinstance(v,str) and re.fullmatch('[a-f0-9]{48}',v) for v in creds.values()), 'Invalid credential file')

    specs=[
     ('e2e-mysql','mysql:8.4',3306,'e2e-mysql-data:/var/lib/mysql',{'MYSQL_ROOT_PASSWORD':creds['mysql_root']},['mysqld','--innodb-buffer-pool-size=67108864','--max-connections=30','--mysqlx=0','--performance-schema=OFF']),
     ('e2e-postgres','postgres:18-alpine',5432,'e2e-postgres-data:/var/lib/postgresql',{'POSTGRES_PASSWORD':creds['postgres_root']},['postgres','-c','shared_buffers=32MB','-c','max_connections=30']),
     ('e2e-valkey','valkey/valkey:8-alpine',6379,'e2e-valkey-data:/data',{'VALKEY_PASSWORD':creds['valkey']},['sh','-ec','exec valkey-server --requirepass "$VALKEY_PASSWORD" --appendonly yes --maxmemory 64mb --maxmemory-policy allkeys-lru']),
    ]
    processes={}
    existing_processes=orbit('process:list','--node=app-dev')['processes']
    for name,image,port,volume,environment,command in specs:
        args=['process:create',name,'--node=app-dev','--runtime=docker','--image='+image,'--working-directory=/','--restart=unless-stopped','--start','--port=10.44.0.2:'+str(port)+':'+str(port),'--volume='+volume]
        args += ['--environment='+k+'='+v for k,v in environment.items()]
        args += ['--command='+x for x in command]
        process=unique(existing_processes, 'name', name)
        expected={'image':image,'command':command,'ports':['10.44.0.2:'+str(port)+':'+str(port)],'volumes':[{'source':volume.split(':')[0],'target':volume.split(':')[1],'read_only':False}]}
        if process:
            require(process['target_type']=='node' and process['target_id']==node['id'] and process['runtime']=='docker' and process['runtime_config']==expected and process['restart_policy']=='unless-stopped', 'Conflicting database Process '+name)
        else:
            require(not verify, 'Missing database Process '+name)
            result=orbit(*args)
            process=result.get('process',result)
        require(isinstance(process.get('id'),int) and process['id'] > 0, 'Missing Process identity')
        pid=process['id']
        processes[name]=pid
        if not verify and process.get('runtime_status') != 'running': orbit('process:start',str(pid))
        if verify: require(process.get('status')=='active' and process.get('runtime_status')=='running', 'Database Process is not running')
        print(json.dumps({'process':name,'id':pid,'image':image,'state':'started'}),flush=True)

    containers={name:'orbit-process-'+str(pid)+'-'+name for name,pid in processes.items()}
    checks={
     'e2e-mysql':['sh','-ec','MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=socket --user=root --batch --skip-column-names -e "SELECT 1"'],
     'e2e-postgres':['pg_isready','--username=postgres','--dbname=postgres'],
     'e2e-valkey':['sh','-ec','REDISCLI_AUTH="$VALKEY_PASSWORD" valkey-cli PING'],
    }
    for name,cmd in checks.items():
        for attempt in range(1 if verify else 90):
            result=docker('exec',containers[name],*cmd,check=False)
            if result.returncode==0: break
            time.sleep(2)
        else: raise RuntimeError(name+' did not become ready')
        print(name+': ready',flush=True)

    connections={x['slug']:x for x in orbit('database:list')['connections']}
    if 'e2e-mysql' not in connections:
        require(not verify, 'Missing MySQL connection')
        mysql=orbit('database:user:create','e2e-mysql','--process='+str(processes['e2e-mysql']),'--database=orbit_e2e','--username=orbit_e2e','--password='+creds['mysql_app'])
    print('e2e-mysql: database and user registered',flush=True)

    pg=containers['e2e-postgres']
    password=creds['postgres_app']
    require(re.fullmatch('[a-f0-9]{48}',password), 'Invalid PostgreSQL credential')
    sql="DO $$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname='orbit_e2e') THEN CREATE ROLE orbit_e2e LOGIN; END IF; END $$;\nALTER ROLE orbit_e2e WITH LOGIN PASSWORD '"+password+"';\nSELECT 'CREATE DATABASE orbit_e2e OWNER orbit_e2e' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname='orbit_e2e')\\gexec\n"
    if not verify and 'e2e-postgres' not in connections: docker('exec','-i',pg,'psql','--username=postgres','--dbname=postgres','--set=ON_ERROR_STOP=1',data=sql)

    connections={x['slug']:x for x in orbit('database:list')['connections']}
    for slug,driver,port,database,username,password in [('e2e-mysql','mysql',3306,'orbit_e2e','orbit_e2e',creds['mysql_app']),('e2e-postgres','pgsql',5432,'orbit_e2e','orbit_e2e',creds['postgres_app']),('e2e-valkey','redis',6379,'1','default',creds['valkey'])]:
        existing=connections.get(slug)
        if existing and any(existing.get(k)!=v for k,v in {'driver':driver,'node_id':node['id'],'host':'10.44.0.2','port':port,'database':database,'username':username}.items()):
            raise RuntimeError('Conflicting connection '+slug)
        if existing: continue
        require(not verify, 'Missing connection '+slug)
        verb='database:create'
        args=[verb,slug,'--node=app-dev','--host=10.44.0.2','--port='+str(port),'--database='+database,'--username='+username,'--password='+password]
        if not existing: args += ['--driver='+driver]
        orbit(*args)
        print(slug+': connection registered',flush=True)

    for slug,sql in [('e2e-mysql','SELECT DATABASE() AS database_name, VERSION() AS version'),('e2e-postgres','SELECT current_database() AS database_name, version() AS version')]:
        response=orbit('database:query',slug,sql)
        print(slug+': authenticated query passed',flush=True)
    valkey=docker('exec',containers['e2e-valkey'],'sh','-ec','REDISCLI_AUTH="$VALKEY_PASSWORD" valkey-cli -n 1 PING')
    require(valkey.stdout.strip() == 'PONG', 'Valkey authentication failed')
    print('e2e-valkey: authenticated logical database 1 passed',flush=True)
    instances=orbit('instance:list')['instances']
    dev=unique(instances,'name','e2e-dev')
    prod=unique(instances,'name','e2e-prod')
    require(dev is not None and prod is not None and dev['app_id']==prod['app_id'] and dev['node_id']==node['id'], 'Missing or conflicting Laravel Instances')
    for instance, sql_slug in [(dev,'e2e-mysql'),(prod,'e2e-postgres')]:
        selector='--instance='+str(instance['id'])
        if not verify:
            # Recover only the known, existing identity through the product.
            args=['instance:create',str(instance['app_id']),str(instance['node_id']),instance['name'],'--domain='+instance['domain'],'--recover-source-profile']
            if instance.get('branch_override'): args += ['--branch='+instance['branch_override']]
            try:
                orbit('env:import',selector)
            except RuntimeError as error:
                if str(error).endswith(': instance.source_profile_missing'):
                    orbit(*args)
                    orbit('env:import',selector)
                elif not str(error).endswith(': env.import_conflict'): raise
                # Preserve stored intent when a previous run already imported it.
            orbit('instance:database:add',sql_slug,selector)
            orbit('instance:database:add','e2e-valkey',selector,'--prefix=REDIS')
            for key,value in {'APP_URL':'https://{{app_instance.domain}}','QUEUE_CONNECTION':'redis','REDIS_DB':'1','REDIS_CACHE_DB':'1','CACHE_STORE':'redis','SESSION_DRIVER':'database'}.items():
                orbit('env:update',selector,'--key='+key,'--value='+value)
            orbit('env:sync',selector)
            if instance['environment']=='development':
                run(['php',instance['checkout_path']+'/artisan','migrate','--force','--no-interaction'])
            else:
                # The existing deploy steps include migrations before activation.
                orbit('instance:deploy',str(instance['id']))
        print(instance['name']+': database environment configured',flush=True)

    projects=orbit('project:list')['projects']
    for slug,kind,repository in [
        ('e2e-monorepo','monorepo','https://github.com/laravel/wayfinder.git'),
        ('e2e-package','laravel-package','https://github.com/spatie/laravel-package-tools.git'),
    ]:
        project=unique(projects,'slug',slug)
        if project:
            require(project['type']==kind and project['repository_url']==repository, 'Conflicting typed Project '+slug)
        else:
            require(not verify,'Missing typed Project '+slug)
            project=orbit('project:create',slug,kind,repository)
        instance=unique(instances,'name',slug)
        if instance:
            require(instance['app_id']==project['id'] and instance['node_id']==node['id'] and instance['status']=='active' and instance.get('route') is None, 'Conflicting non-web Instance '+slug)
        else:
            require(not verify,'Missing typed Instance '+slug)
            orbit('instance:create',str(project['id']),str(node['id']),slug)
        print(slug+': non-web Project and Instance ready',flush=True)

    workers=orbit('process:list','--instance='+str(dev['id']))['processes']
    worker=unique(workers,'name','e2e-queue')
    worker_command=['/usr/bin/php','artisan','queue:work','redis','--sleep=3','--tries=1','--max-time=3600']
    if worker:
        require(worker['runtime']=='systemd' and worker['runtime_config']['command']==worker_command and worker['target_id']==dev['id'], 'Conflicting queue worker')
        if not verify and worker['runtime_status']!='active': orbit('process:start',str(worker['id']))
        if verify:
            require(worker['status']=='active' and worker.get('desired_state')=='running', 'Queue worker is not desired running')
            if worker['runtime_status'] != 'active':
                require(worker['runtime_status']=='inactive' and worker.get('keep_alive') is False, 'Queue worker is not healthy')
                report=orbit('doctor','--node='+str(node['id']),'--family=process')
                require(report.get('healthy') is True and report.get('summary',{}).get('checks',0) >= 4, 'Sleeping queue worker did not pass Doctor')
    else:
        require(not verify,'Missing queue worker')
        orbit('process:create','e2e-queue','--instance='+str(dev['id']),'--restart=always','--start',*['--command='+v for v in worker_command])
    schedules=orbit('schedule:list')['schedules']
    schedule=unique(schedules,'name','e2e-scheduler')
    if schedule:
        schedule=orbit('schedule:show',str(schedule['id']))
        require(schedule['target_type']=='instance' and schedule['status']=='active' and schedule['desired_timer_state']=='enabled', 'Schedule is not enabled')
        require(schedule['target_id']==dev['id'] and schedule['command']=='/usr/bin/php artisan schedule:run' and schedule['calendar']=='*-*-* *:*:00', 'Conflicting Schedule')
    else:
        require(not verify,'Missing Schedule')
        orbit('schedule:create','e2e-scheduler','--instance='+str(dev['id']),'--calendar=*-*-* *:*:00','--command=/usr/bin/php artisan schedule:run','--timeout=120')
    if verify:
        run(['php',dev['checkout_path']+'/artisan','migrate:status','--no-interaction'])
        program = r'''require $argv[1].'/vendor/autoload.php';
$app=require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::select('SELECT 1');
if (!Illuminate\Support\Facades\Redis::connection()->ping()) exit(1);'''
        run(['php','-r',program,dev['checkout_path']])
    print('sample-fixtures: ready',flush=True)

except Exception as error:
    # Never include argv, captured output, or credentials in failure evidence.
    message = str(error) if isinstance(error, RuntimeError) else type(error).__name__
    print('sample-fixtures: '+message, file=sys.stderr)
    sys.exit(65)
PYTHON
