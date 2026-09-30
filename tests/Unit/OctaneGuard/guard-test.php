<?php

declare(strict_types=1);

use GalahadXVI\OctaneGuard\Guard;
use Tests\Support\OctaneGuard\ProcessHarness;

require_once __DIR__.'/../../../src/state.php';
require_once __DIR__.'/../../../src/guard.php';
require_once __DIR__.'/../../Support/OctaneGuard/process-harness.php';

/**
 * Make a disposable private directory inside this test's isolated tree.
 */
function guardTestDirectory(string $parent, string $name): string
{
    $path = $parent.'/'.$name;
    mkdir($path, 0700);

    return $path;
}

/**
 * Read persisted evidence without acquiring the running guard's exclusive lock.
 *
 * @return array<string, mixed>
 */
function guardTestRecord(string $path): array
{
    return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Remove only the disposable test tree after all its processes are stopped.
 */
function guardTestRemove(string $path): void
{
    foreach (new FilesystemIterator($path) as $entry) {
        if ($entry->isDir() && !$entry->isLink())
            guardTestRemove($entry->getPathname());
        else
            unlink($entry->getPathname());
    }

    rmdir($path);
}

beforeEach(function (): void {
    $this->guard_root = guardTestDirectory(realpath(sys_get_temp_dir()), 'octane-guard-'.bin2hex(random_bytes(8)));
    $this->guard_app = guardTestDirectory($this->guard_root, 'app');
    $this->guard_state = guardTestDirectory($this->guard_root, 'state');
    $this->guard_processes = [];

    /**
     * Start a guarded fixture in a fresh isolated process group.
     *
     * @return array{ProcessHarness, string}
     */
    $this->launch_guard = function (string $mode, ?int $port = null): array {
        $index = count($this->guard_processes);
        $fixture = guardTestDirectory($this->guard_root, 'fixture-'.$index);
        $runtime = guardTestDirectory($this->guard_root, 'run-'.$index);
        $command = [__DIR__.'/../../Support/OctaneGuard/run-guard.php', $this->guard_state, $this->guard_app, $fixture, $mode];

        if ($port !== null)
            $command[] = (string) $port;

        $process = new ProcessHarness($command, $runtime);
        $this->guard_processes[] = $process;
        $process->start();

        return [$process, $fixture];
    };
});

afterEach(function (): void {
    foreach (array_reverse($this->guard_processes) as $process)
        $process->shutdown();

    guardTestRemove($this->guard_root);
});

it('stops only its own generation and refunds a requested stop', function (): void {
    [$guard, $fixture] = ($this->launch_guard)('clean');
    $guard->waitFor(fn (): bool => is_file($fixture.'/started.json'));
    expect(guardTestRecord($fixture.'/started.json')['pgid'])->toBe($guard->pid());

    $guard->signal(SIGTERM);
    expect($guard->waitForExit())->toBe(128 + SIGKILL);
    $guard->waitFor(fn (): bool => Guard::groupIsAbsent($guard->pid()));
    expect(is_file($fixture.'/stopped.json'))->toBeTrue()
        ->and(guardTestRecord($this->guard_state.'/state.json')['launches'])->toBe(0);

    [$replacement, $next_fixture] = ($this->launch_guard)('clean');
    $replacement->waitFor(fn (): bool => is_file($next_fixture.'/started.json'));
    $replacement->signal(SIGTERM);
    expect($replacement->waitForExit())->toBe(128 + SIGKILL);
});

it('kills a stubborn child within the bounded shutdown window', function (): void {
    [$guard, $fixture] = ($this->launch_guard)('stubborn');
    $guard->waitFor(fn (): bool => is_file($fixture.'/started.json'));
    $guard->signal(SIGTERM);

    expect($guard->waitForExit())->toBe(128 + SIGKILL);
    $guard->waitFor(fn (): bool => Guard::groupIsAbsent($guard->pid()));
    expect(guardTestRecord($this->guard_state.'/state.json')['launches'])->toBe(0);
});

it('cleans an orphan left by a failed parent before a replacement can launch', function (): void {
    [$guard, $fixture] = ($this->launch_guard)('orphan');
    $guard->waitFor(fn (): bool => is_file($fixture.'/orphan-child.json'));
    expect(guardTestRecord($fixture.'/orphan-child.json')['pgid'])->toBe($guard->pid());
    expect($guard->waitForExit())->toBe(128 + SIGKILL);
    $guard->waitFor(fn (): bool => Guard::groupIsAbsent($guard->pid()));
    expect(guardTestRecord($this->guard_state.'/state.json')['launches'])->toBe(1);

    [$replacement, $next_fixture] = ($this->launch_guard)('clean');
    $replacement->waitFor(fn (): bool => is_file($next_fixture.'/started.json'));
    expect($replacement->isRunning())->toBeTrue();
});

it('retains the failure budget across completely new guard processes', function (string $mode): void {
    for ($attempt = 0; $attempt < Guard::MAX_LAUNCHES; $attempt++) {
        [$guard, $fixture] = ($this->launch_guard)($mode);
        expect($guard->waitForExit())->toBe(128 + SIGKILL);
        $guard->waitFor(fn (): bool => Guard::groupIsAbsent($guard->pid()));
        expect(is_file($fixture.'/started.json'))->toBeTrue();
    }

    for ($attempt = 0; $attempt < 3; $attempt++) {
        [$guard, $fixture] = ($this->launch_guard)('clean');
        expect($guard->waitForExit())->toBe(Guard::REFUSED)
            ->and(is_file($fixture.'/started.json'))->toBeFalse();
    }

    expect(guardTestRecord($this->guard_state.'/state.json')['launches'])->toBe(Guard::MAX_LAUNCHES);
})->with(['fail', 'exit-zero']);

it('refuses a competing guard without signalling the existing instance', function (): void {
    [$original, $fixture] = ($this->launch_guard)('clean');
    $original->waitFor(fn (): bool => is_file($fixture.'/started.json'));
    [$competitor, $next_fixture] = ($this->launch_guard)('clean');

    expect($competitor->waitForExit())->toBe(Guard::REFUSED)
        ->and($original->isRunning())->toBeTrue()
        ->and(is_file($next_fixture.'/started.json'))->toBeFalse()
        ->and(guardTestRecord($this->guard_state.'/state.json')['launches'])->toBe(1);
});

it('refuses recovery when only the guard was killed and its child still lives', function (): void {
    [$original, $fixture] = ($this->launch_guard)('stubborn');
    $original->waitFor(fn (): bool => is_file($fixture.'/started.json'));
    $original->signal(SIGKILL);
    expect($original->waitForExit())->toBe(128 + SIGKILL);

    [$replacement, $next_fixture] = ($this->launch_guard)('clean');
    expect($replacement->waitForExit())->toBe(Guard::REFUSED)
        ->and(Guard::groupIsAbsent($original->pid()))->toBeFalse()
        ->and(is_file($next_fixture.'/started.json'))->toBeFalse();
});

it('does not refund a failure when a stop arrives during failure cleanup', function (): void {
    [$guard, $fixture] = ($this->launch_guard)('fail');
    $guard->waitFor(fn (): bool => str_contains($guard->output(), 'Octane exited unexpectedly'));
    $guard->signal(SIGTERM);

    expect($guard->waitForExit())->toBe(128 + SIGKILL)
        ->and(guardTestRecord($this->guard_state.'/state.json')['launches'])->toBe(1);
});

it('does not kill an unrelated process when the requested port is occupied', function (): void {
    $runtime = guardTestDirectory($this->guard_root, 'unrelated-runtime');
    $fixture = guardTestDirectory($this->guard_root, 'unrelated-fixture');
    $unrelated = new ProcessHarness([__DIR__.'/../../Support/OctaneGuard/fixture-worker.php', $fixture, 'hold-port', '0'], $runtime);
    $this->guard_processes[] = $unrelated;
    $unrelated->start();
    $unrelated->waitFor(fn (): bool => is_file($fixture.'/listening.json'));
    $address = guardTestRecord($fixture.'/listening.json')['address'];
    $port = (int) substr($address, strrpos($address, ':') + 1);

    [$guard] = ($this->launch_guard)('hold-port', $port);
    expect($guard->waitForExit())->toBe(128 + SIGKILL)
        ->and($unrelated->isRunning())->toBeTrue();

    $connection = stream_socket_client('tcp://'.$address, $error_number, $error_message, 1);
    expect(is_resource($connection))->toBeTrue();
    fclose($connection);
});
