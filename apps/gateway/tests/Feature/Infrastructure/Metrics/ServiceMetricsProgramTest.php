<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('recognizes canonical UFW rules and places isolation before broad member access', function (): void {
    $result = service_metrics_python(<<<'PY'
        import shlex, types
        lines = [
            "ufw allow in on orbit from 10.44.0.2 to 10.44.0.3 port 9114 proto tcp comment 'orbit:metrics-service-fpm-allow'",
            "ufw deny in on orbit to 10.44.0.3 port 9114 proto tcp comment 'orbit:metrics-service-fpm-deny'",
        ]
        commands = []
        def run(args, check=True):
            if args == ['ufw', 'status']:
                return types.SimpleNamespace(stdout='Status: active\n')
            if args == ['ufw', 'show', 'added']:
                return types.SimpleNamespace(stdout='\n'.join(lines))
            commands.append(args)
            if args[1:3] == ['--force', 'delete']:
                comment = args[-1]
                lines[:] = [line for line in lines if comment not in line]
            else:
                lines.insert(0, shlex.join(['ufw'] + args[3:]))
            return types.SimpleNamespace(stdout='')
        module.run = run
        desired = module.rules()
        desired[0][7] = '10.44.0.9'
        module.set_rules(desired)
        assert module.rules() == desired
        assert commands[-2][-1] == 'orbit:metrics-service-fpm-deny'
        assert commands[-1][-1] == 'orbit:metrics-service-fpm-allow'
        assert commands[-1][:3] == ['ufw', 'insert', '1']
        commands.clear()
        module.set_rules(desired)
        assert commands == []
        lines.insert(0, 'ufw allow in on orbit from 10.44.0.0/24')
        state = {'config': None, 'unit': None, 'rules': desired, 'active': False, 'enabled': False, 'binary': False}
        module.inspect = lambda: state
        module.binary_present = lambda: False
        module.apply(state)
        assert module.rules() == desired
        assert shlex.split(lines[0])[-1] == 'orbit:metrics-service-fpm-allow'
        assert shlex.split(lines[1])[-1] == 'orbit:metrics-service-fpm-deny'
        commands.clear()
        module.apply(state)
        assert commands == []
        PY);
    expect($result->getExitCode())->toBe(0, $result->getErrorOutput());
});

it('rejects misleading firewall ownership markers before mutation', function (string $line): void {
    $result = service_metrics_python(<<<'PY'
        import types
        line = sys.argv[2]
        module.run = lambda args, check=True: types.SimpleNamespace(stdout='Status: active' if args == ['ufw', 'status'] else line)
        try:
            module.rules()
        except ValueError:
            sys.exit(0)
        sys.exit(1)
        PY, [$line]);
    expect($result->getExitCode())->toBe(0, $result->getErrorOutput());
})->with([
    "ufw allow in on eth0 proto tcp from 10.44.0.2 to 10.44.0.3 port 9114 comment 'orbit:metrics-service-fpm-allow'",
    "ufw allow in on orbit proto tcp from any to 10.44.0.3 port 9114 comment 'orbit:metrics-service-fpm-allow'",
    "ufw allow in on orbit proto tcp from 10.44.0.2 to 10.44.0.3 port 22 comment 'orbit:metrics-service-fpm-allow'",
    "ufw deny in on orbit proto tcp from 10.44.0.2 to 10.44.0.3 port 9114 comment 'orbit:metrics-service-fpm-deny'",
]);

/** @param list<string> $arguments */
function service_metrics_python(string $body, array $arguments = []): Process
{
    $script = <<<'PY'
        import importlib.util, sys
        spec = importlib.util.spec_from_file_location('metrics', sys.argv[1])
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        PY;
    $process = new Process(['python3', '-c', $script."\n".$body, resource_path('scripts/service-metrics.py'), ...$arguments]);
    $process->run();

    return $process;
}

it('restores only the changed pool after a reload failure and leaves stopped masters stopped', function (): void {
    $script = <<<'PY'
        import importlib.util, pathlib, tempfile, sys, json, base64, types
        source = pathlib.Path(sys.argv[1]).read_text()
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            user = 'orbit-test'
            runtime = root / user
            (runtime / 'generated').mkdir(parents=True)
            (root / 'locks').mkdir()
            source = source.replace("Path('/etc/orbit/php-fpm')", "Path(" + repr(str(root)) + ")")
            source = source.replace("Path('/run/lock/orbit')", "Path(" + repr(str(root / 'locks')) + ")")
            module = types.ModuleType('fpm')
            exec(compile(source, 'service-metrics-fpm.py', 'exec'), module.__dict__)
            module.read = lambda path: path.read_text()
            pool = runtime / 'generated/pool.conf'
            original = '[orbit-test]\nuser = orbit-test\n'
            pool.write_text(original)
            (runtime / 'orbit.identity').write_text('owned')
            (runtime / 'local.conf').write_text('[orbit-test]\npm = ondemand\npm.max_children = 3\n')
            (runtime / 'generated/php-fpm.conf').write_text('include = ' + str(pool) + '\ninclude = ' + str(runtime / 'local.conf') + '\n')
            calls = []
            def run(args, check=True):
                calls.append(args)
                if args[:2] == ['systemctl', 'reload'] and len([c for c in calls if c[:2] == ['systemctl', 'reload']]) == 1:
                    raise RuntimeError('reload failed')
                return types.SimpleNamespace(returncode=0)
            module.run = run
            request = {'operation': 'apply', 'enabled': True, 'user': user, 'version': '8.5', 'marker': 'owned'}
            sys.argv = ['fpm', base64.b64encode(json.dumps(request).encode()).decode()]
            try:
                module.main()
                raise AssertionError('must fail')
            except RuntimeError:
                pass
            assert pool.read_text() == original
            assert len([c for c in calls if c[:2] == ['systemctl', 'reload']]) == 2
            calls.clear()
            def stopped(args, check=True):
                calls.append(args)
                return types.SimpleNamespace(returncode=1 if args[:2] == ['systemctl', 'is-active'] else 0)
            module.run = stopped
            module.main()
            assert 'pm.status_listen' in pool.read_text()
            assert not any(c[:2] in [['systemctl', 'reload'], ['systemctl', 'start'], ['systemctl', 'restart']] for c in calls)
            calls.clear()
            module.main()
            assert calls == []
            journal = runtime / '.metrics-pool-recovery.json'
            journal.write_text(json.dumps({'before': original, 'candidate': pool.read_text()}))
            request['operation'] = 'snapshot'
            sys.argv = ['fpm', base64.b64encode(json.dumps(request).encode()).decode()]
            module.main()
            assert pool.read_text() == original
            assert not journal.exists()
        PY;
    $process = new Process(['python3', '-c', $script, resource_path('scripts/service-metrics-fpm.py')]);
    $process->run();
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
});
