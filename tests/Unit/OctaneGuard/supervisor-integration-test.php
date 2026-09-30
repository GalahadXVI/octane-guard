<?php

declare(strict_types=1);

use Tests\Support\OctaneGuard\SupervisorHarness;

require_once __DIR__.'/../../Support/OctaneGuard/supervisor-harness.php';
require_once __DIR__.'/../../../src/guard.php';

beforeEach(function (): void {
    $source = getenv('OCTANE_GUARD_SUPERVISOR_SOURCE');

    if ($source === false || ! is_file($source.'/supervisor/supervisord.py'))
        $this->markTestSkipped('Set OCTANE_GUARD_SUPERVISOR_SOURCE to an unpacked Supervisor 4.2.5 source directory.');

    $this->supervisor_source = $source;
    $this->supervisor_python = getenv('OCTANE_GUARD_SUPERVISOR_PYTHON') ?: '/usr/bin/python3';
    $this->supervisor_directory = realpath(sys_get_temp_dir()).'/octane-sv-'.bin2hex(random_bytes(6));
    $this->supervisor_shutdown_completed = false;
    mkdir($this->supervisor_directory, 0700);
});

afterEach(function (): void {
    if (! isset($this->supervisor_directory) || ! $this->supervisor_shutdown_completed || $this->status()->isFailure() || $this->status()->isError())
        return;

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->supervisor_directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($files as $file) {
        if ($file->isDir() && ! $file->isLink())
            rmdir($file->getPathname());
        else
            unlink($file->getPathname());
    }

    rmdir($this->supervisor_directory);
});

it('parks without automatic restart loops under Forge Supervisor defaults after failures', function (string $mode): void {
    $supervisor = new SupervisorHarness($this->supervisor_directory, $this->supervisor_python, $this->supervisor_source, $mode);

    try {
        $supervisor->start();
        $supervisor->waitFor(fn (): bool => is_file($this->supervisor_directory.'/guard.log')
            && str_contains(file_get_contents($this->supervisor_directory.'/guard.log'), 'BLOCKED'), 20.0);
        $supervisor->waitFor(fn (): bool => $supervisor->status()['statename'] === 'RUNNING');
        $blocked_pid = $supervisor->status()['pid'];

        expect($supervisor->launches())->toBe(GalahadXVI\OctaneGuard\Guard::MAX_LAUNCHES);

        usleep(2_000_000);

        expect($supervisor->status()['statename'])->toBe('RUNNING')
            ->and($supervisor->status()['pid'])->toBe($blocked_pid)
            ->and($supervisor->launches())->toBe(GalahadXVI\OctaneGuard\Guard::MAX_LAUNCHES);

        $supervisor->rpc('stopProcess', ['guard', true]);
        expect($supervisor->status()['statename'])->toBe('STOPPED');
        $supervisor->rpc('startProcess', ['guard', true]);
        $replacement_pid = $supervisor->status()['pid'];
        expect($replacement_pid)->not->toBe($blocked_pid);
        usleep(500_000);
        expect($supervisor->status()['pid'])->toBe($replacement_pid)
            ->and($supervisor->launches())->toBe(GalahadXVI\OctaneGuard\Guard::MAX_LAUNCHES);

        $supervisor->shutdown();
        $supervisor->start();
        $supervisor->waitFor(fn (): bool => $supervisor->status()['statename'] === 'RUNNING');
        expect($supervisor->launches())->toBe(GalahadXVI\OctaneGuard\Guard::MAX_LAUNCHES);
    } finally {
        $supervisor->shutdown();
        $this->supervisor_shutdown_completed = true;
    }
})->with(['fail', 'orphan', 'delayed-fail']);

it('leaves a deliberately stopped guard stopped without consuming its failure allowance', function (): void {
    $supervisor = new SupervisorHarness($this->supervisor_directory, $this->supervisor_python, $this->supervisor_source, 'clean');

    try {
        $supervisor->start();
        $supervisor->waitFor(fn (): bool => $supervisor->status()['statename'] === 'RUNNING');
        $supervisor->rpc('stopProcess', ['guard', true]);

        $state = json_decode(file_get_contents($this->supervisor_directory.'/state/state.json'), true, 64, JSON_THROW_ON_ERROR);

        expect($supervisor->status()['statename'])->toBe('STOPPED')
            ->and($supervisor->launches())->toBe(1)
            ->and($state['launches'])->toBe(0);

        usleep(2_000_000);

        expect($supervisor->status()['statename'])->toBe('STOPPED')
            ->and($supervisor->launches())->toBe(1);
    } finally {
        $supervisor->shutdown();
        $this->supervisor_shutdown_completed = true;
    }
});

it('cleans stubborn workers across repeated whole Supervisor restarts using the same durable state', function (): void {
    $supervisor = new SupervisorHarness($this->supervisor_directory, $this->supervisor_python, $this->supervisor_source, 'stubborn');

    try {
        for ($generation = 1; $generation <= 3; $generation++) {
            $supervisor->start();
            $supervisor->waitFor(fn (): bool => $supervisor->status()['statename'] === 'RUNNING');
            $running_state = json_decode(file_get_contents($this->supervisor_directory.'/state/state.json'), true, 64, JSON_THROW_ON_ERROR);

            expect($supervisor->launches())->toBe($generation)
                ->and($running_state['launches'])->toBe(1);

            $supervisor->shutdown();
            $supervisor->waitFor(fn (): bool => GalahadXVI\OctaneGuard\Guard::groupIsAbsent($running_state['pgid']));
            $stopped_state = json_decode(file_get_contents($this->supervisor_directory.'/state/state.json'), true, 64, JSON_THROW_ON_ERROR);

            expect($stopped_state['launches'])->toBe(0)
                ->and($stopped_state['pgid'])->toBe($running_state['pgid']);
        }
    } finally {
        $supervisor->shutdown();
        $this->supervisor_shutdown_completed = true;
    }
});
