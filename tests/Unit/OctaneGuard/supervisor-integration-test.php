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

it('bounds automatic child launches under real Supervisor after failures', function (string $mode): void {
    $supervisor = new SupervisorHarness($this->supervisor_directory, $this->supervisor_python, $this->supervisor_source, $mode);

    try {
        $supervisor->start();
        $supervisor->waitFor(fn (): bool => $supervisor->status()['statename'] === 'FATAL', 20.0);

        expect($supervisor->launches())->toBe(GalahadXVI\OctaneGuard\Guard::MAX_LAUNCHES);

        if ($mode === 'delayed-fail')
            expect(substr_count(file_get_contents($this->supervisor_directory.'/supervisord.log'), 'guard entered RUNNING state'))->toBe(GalahadXVI\OctaneGuard\Guard::MAX_LAUNCHES);

        usleep(2_000_000);

        expect($supervisor->status()['statename'])->toBe('FATAL')
            ->and($supervisor->launches())->toBe(GalahadXVI\OctaneGuard\Guard::MAX_LAUNCHES);
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
