<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/** Execute the real lock/ownership helper against a disposable systemd command boundary. */
function webUnitFixture(): string
{
    $root = temporaryPath('orbit-web-unit-', 6);
    mkdir($root.'/bin', 0700, true);
    $source = file_get_contents(dirname(__DIR__, 3).'/resources/web-unit.sh');
    file_put_contents($root.'/unit.sh', str_replace('/home/orbit', $root.'/home', $source));
    file_put_contents($root.'/bin/systemd-run', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$@" >"$FIXTURE/arguments"
[[ ! -e "$FIXTURE/description" ]] || { echo 'Unit already exists.' >&2; exit 1; }
for argument in "$@"; do
  case "$argument" in --description=*) printf '%s' "${argument#--description=}" >"$FIXTURE/description" ;; esac
done
printf active >"$FIXTURE/active"
echo accepted >>"$FIXTURE/events"
if [[ -e "$FIXTURE/start-delay" ]]; then sleep 0.3; fi
echo acknowledged >>"$FIXTURE/events"
[[ ! -e "$FIXTURE/start-lost" ]] || exit 1
BASH);
    file_put_contents($root.'/bin/systemctl', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
case "$1" in
  show)
    if [[ -e "$FIXTURE/inspect-failed" ]]; then exit 1; fi
    if [[ -e "$FIXTURE/description" ]]; then
      printf 'Description=%s\nLoadState=loaded\nActiveState=%s\n' "$(<"$FIXTURE/description")" "$(<"$FIXTURE/active")"
    else
      printf 'Description=orbit-e2e-web.service\nLoadState=not-found\nActiveState=inactive\n'
    fi
    ;;
  stop)
    echo stop >>"$FIXTURE/stops"
    echo stop >>"$FIXTURE/events"
    [[ ! -e "$FIXTURE/stop-rejected" ]] || exit 1
    printf inactive >"$FIXTURE/active"
    [[ ! -e "$FIXTURE/stop-lost" ]] || exit 1
    ;;
  *) exit 64 ;;
esac
BASH);
    chmod($root.'/bin/systemd-run', 0700);
    chmod($root.'/bin/systemctl', 0700);

    return $root;
}

function webUnitRun(string $root, string $action, string $token): Process
{
    $process = new Process(['bash', $root.'/unit.sh', $action, $token], env: [
        'PATH' => $root.'/bin:/usr/bin:/bin', 'FIXTURE' => $root,
    ]);
    $process->run();

    return $process;
}

it('starts the described unit with the clean pinned launcher and confirms stopped state', function () {
    $root = webUnitFixture();
    $token = str_repeat('a', 32);
    expect(webUnitRun($root, 'start', $token)->getExitCode())->toBe(0);
    expect(file_get_contents($root.'/arguments'))->toContain('--unit=orbit-e2e-web.service', '--description=orbit-e2e-web:'.$token,
        '--uid=orbit', '--gid=orbit', "env\n-i\nHOME=".$root.'/home', 'web-session.sh');
    expect(webUnitRun($root, 'stop', $token)->getExitCode())->toBe(0);
    expect(file_get_contents($root.'/active'))->toBe('inactive');
    expect(is_file($root.'/home/.orbit/e2e-web-sessions/'.$token.'.cancelled'))->toBeTrue();
});

it('recovers an accepted start that returned failure and refuses a delayed start after cancellation', function () {
    $root = webUnitFixture();
    $token = str_repeat('b', 32);
    touch($root.'/start-lost');
    expect(webUnitRun($root, 'start', $token)->getExitCode())->toBe(1);
    expect(file_get_contents($root.'/active'))->toBe('active');
    expect(webUnitRun($root, 'stop', $token)->getExitCode())->toBe(0);
    expect(file_get_contents($root.'/active'))->toBe('inactive');
    unlink($root.'/description');
    unlink($root.'/start-lost');
    expect(webUnitRun($root, 'start', $token)->getExitCode())->toBe(1);
    expect(is_file($root.'/description'))->toBeFalse();
});

it('does not stop a pre-existing unit with a different description', function () {
    $root = webUnitFixture();
    $token = str_repeat('c', 32);
    file_put_contents($root.'/description', 'someone-elses-unit');
    file_put_contents($root.'/active', 'active');
    expect(webUnitRun($root, 'start', $token)->getExitCode())->toBe(1);
    expect(webUnitRun($root, 'stop', $token)->getExitCode())->toBe(0);
    expect(file_get_contents($root.'/active'))->toBe('active');
    expect(is_file($root.'/stops'))->toBeFalse();
});

it('recovers the reservation-before-start crash window and rejects a subsequently arriving start', function () {
    $root = webUnitFixture();
    $token = str_repeat('d', 32);
    expect(webUnitRun($root, 'stop', $token)->getExitCode())->toBe(0);
    expect(webUnitRun($root, 'start', $token)->getExitCode())->toBe(1);
    expect(is_file($root.'/description'))->toBeFalse();
});

it('fails closed on unsuccessful inspection or stopping, and permits cleanup retry', function (string $fault) {
    $root = webUnitFixture();
    $token = str_repeat('e', 32);
    expect(webUnitRun($root, 'start', $token)->getExitCode())->toBe(0);
    touch($root.'/'.$fault);
    expect(webUnitRun($root, 'stop', $token)->getExitCode())->toBe(1);
    expect(file_get_contents($root.'/active'))->toBe('active');
    unlink($root.'/'.$fault);
    expect(webUnitRun($root, 'stop', $token)->getExitCode())->toBe(0);
    expect(file_get_contents($root.'/active'))->toBe('inactive');
})->with(['inspect-failed', 'stop-rejected']);

it('serializes cleanup behind an in-flight accepted startup', function () {
    $root = webUnitFixture();
    $token = str_repeat('1', 32);
    touch($root.'/start-delay');
    $env = ['PATH' => $root.'/bin:/usr/bin:/bin', 'FIXTURE' => $root];
    $start = new Process(['bash', $root.'/unit.sh', 'start', $token], env: $env);
    $start->start();
    $deadline = microtime(true) + 5;
    while (! is_file($root.'/events') && microtime(true) < $deadline) {
        usleep(1000);
    }
    expect(is_file($root.'/events'))->toBeTrue();
    $stop = new Process(['bash', $root.'/unit.sh', 'stop', $token], env: $env);
    $stop->start();
    expect($start->wait())->toBe(0);
    expect($stop->wait())->toBe(0);
    expect(file_get_contents($root.'/events'))->toBe("accepted\nacknowledged\nstop\n");
    expect(file_get_contents($root.'/active'))->toBe('inactive');
});

it('reconciles a stopped unit even when systemctl loses its successful response', function () {
    $root = webUnitFixture();
    $token = str_repeat('f', 32);
    expect(webUnitRun($root, 'start', $token)->getExitCode())->toBe(0);
    touch($root.'/stop-lost');
    expect(webUnitRun($root, 'stop', $token)->getExitCode())->toBe(0);
    expect(file_get_contents($root.'/active'))->toBe('inactive');
});
