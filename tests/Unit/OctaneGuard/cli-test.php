<?php

declare(strict_types=1);

use GalahadXVI\OctaneGuard\Guard;
use GalahadXVI\OctaneGuard\State;
use Tests\Support\OctaneGuard\ProcessHarness;

require_once dirname(__DIR__, 3).'/src/state.php';
require_once dirname(__DIR__, 3).'/src/guard.php';
require_once __DIR__.'/../../Support/OctaneGuard/process-harness.php';

/**
 * Execute the shipped command without importing the application or test runtime.
 *
 * @param list<string> $arguments
 * @return array{exit_code: int, output: string}
 */
function runOctaneGuardCommand(array $arguments, string $directory): array
{
    $process = new ProcessHarness([dirname(__DIR__, 3).'/bin/octane-guard', ...$arguments], $directory);

    try {
        $process->start();

        return ['exit_code' => $process->waitForExit(), 'output' => $process->output()];
    } finally {
        $process->shutdown();
    }
}

/**
 * Delete only the private files created for one command test.
 */
function removeOctaneGuardCommandFixture(string $directory): void
{
    foreach (new FilesystemIterator($directory) as $entry) {
        if ($entry->isDir() && !$entry->isLink())
            removeOctaneGuardCommandFixture($entry->getPathname());
        else
            unlink($entry->getPathname());
    }

    rmdir($directory);
}

beforeEach(function (): void {
    $this->cli_directory = realpath(sys_get_temp_dir()).'/octane-cli-'.bin2hex(random_bytes(8));
    $this->cli_app = $this->cli_directory.'/application';
    $this->cli_state = $this->cli_directory.'/state';

    foreach ([$this->cli_directory, $this->cli_app, $this->cli_state, $this->cli_directory.'/command'] as $directory)
        mkdir($directory, 0700);

    $this->cli_arguments = ['--app-dir='.$this->cli_app, '--state-dir='.$this->cli_state];
});

afterEach(function (): void {
    removeOctaneGuardCommandFixture($this->cli_directory);
});

it('prints standalone command help without creating runtime state', function (): void {
    $result = runOctaneGuardCommand(['--help'], $this->cli_directory.'/command');

    expect($result['exit_code'])->toBe(0)
        ->and($result['output'])->toContain('--run', '--reset', '--state-dir')
        ->and(iterator_count(new FilesystemIterator($this->cli_state)))->toBe(0);
});

it('refuses invalid command arguments before creating history', function (string $case): void {
    $arguments = match ($case) {
        'missing-operation' => $this->cli_arguments,
        'missing-path' => ['--reset', $this->cli_arguments[1]],
        'duplicate-path' => ['--reset', ...$this->cli_arguments, $this->cli_arguments[1]],
        'unknown-option' => ['--reset', ...$this->cli_arguments, '--force'],
        'conflicting-operations' => ['--reset', '--run', ...$this->cli_arguments],
    };
    $result = runOctaneGuardCommand($arguments, $this->cli_directory.'/command');

    expect($result['exit_code'])->toBe(Guard::REFUSED)
        ->and($result['output'])->toContain('REFUSED')
        ->and($result['output'])->not->toContain('Linux CLI PHP')
        ->and(iterator_count(new FilesystemIterator($this->cli_state)))->toBe(0);
})->with(['missing-operation', 'missing-path', 'duplicate-path', 'unknown-option', 'conflicting-operations']);

it('resets only the stopped budget without loading the application', function (): void {
    if (PHP_OS_FAMILY !== 'Linux')
        $this->markTestSkipped('The public reset command requires Linux boot identity.');

    mkdir($this->cli_app.'/vendor', 0700);
    $sentinel = '<?php throw new RuntimeException("Application must not be loaded by reset.");';
    file_put_contents($this->cli_app.'/vendor/autoload.php', $sentinel);
    file_put_contents($this->cli_app.'/artisan', $sentinel);
    $state = new State($this->cli_state, $this->cli_app, trim(file_get_contents('/proc/sys/kernel/random/boot_id')));
    $state->acquire();
    $record = $state->read();
    $record['launches'] = Guard::MAX_LAUNCHES;
    $state->save($record);
    $state->closeInChild();
    $result = runOctaneGuardCommand(['--reset', ...$this->cli_arguments], $this->cli_directory.'/command');
    $state->acquire();

    expect($result['exit_code'])->toBe(0)
        ->and($state->read())->toBe(array_replace($record, ['launches' => 0]));

    $state->closeInChild();
});

it('refuses to reset an occupied guard lock without changing its budget', function (): void {
    if (PHP_OS_FAMILY !== 'Linux')
        $this->markTestSkipped('The public reset command requires Linux boot identity.');

    $state = new State($this->cli_state, $this->cli_app, trim(file_get_contents('/proc/sys/kernel/random/boot_id')));
    $state->acquire();
    $record = $state->read();
    $record['launches'] = Guard::MAX_LAUNCHES;
    $state->save($record);
    $result = runOctaneGuardCommand(['--reset', ...$this->cli_arguments], $this->cli_directory.'/command');

    expect($result['exit_code'])->toBe(Guard::REFUSED)
        ->and($state->read())->toBe($record);

    $state->closeInChild();
});

it('refuses to reset while a recorded process group exists without signalling it', function (): void {
    if (PHP_OS_FAMILY !== 'Linux')
        $this->markTestSkipped('The public reset command requires Linux boot identity.');

    mkdir($this->cli_directory.'/worker', 0700);
    $worker = new ProcessHarness([__DIR__.'/../../Support/OctaneGuard/fixture-worker.php', $this->cli_directory.'/worker', 'clean'], $this->cli_directory.'/worker');

    try {
        $worker->start();
        $worker->waitFor(fn (): bool => is_file($this->cli_directory.'/worker/started.json'));
        $state = new State($this->cli_state, $this->cli_app, trim(file_get_contents('/proc/sys/kernel/random/boot_id')));
        $state->acquire();
        $record = $state->read();
        $record['launches'] = Guard::MAX_LAUNCHES;
        $record['pgid'] = $worker->pid();
        $state->save($record);
        $state->closeInChild();
        $result = runOctaneGuardCommand(['--reset', ...$this->cli_arguments], $this->cli_directory.'/command');
        $state->acquire();

        expect($result['exit_code'])->toBe(Guard::REFUSED)
            ->and($state->read())->toBe($record)
            ->and($worker->isRunning())->toBeTrue()
            ->and(is_file($this->cli_directory.'/worker/stopped.json'))->toBeFalse();

        $state->closeInChild();
    } finally {
        $worker->shutdown();
    }
});


it('parks supervised setup failures without creating history or repeatedly exiting', function (): void {
    $previous_environment = getenv('SUPERVISOR_ENABLED');
    putenv('SUPERVISOR_ENABLED=1');
    $process = new ProcessHarness([dirname(__DIR__, 3).'/bin/octane-guard', '--run', ...$this->cli_arguments, '--invalid'], $this->cli_directory.'/command');

    try {
        $process->start();
        $process->waitFor(fn (): bool => str_contains($process->output(), 'BLOCKED'));
        $output = $process->output();
        usleep(1_100_000);

        expect($process->isRunning())->toBeTrue()
            ->and($process->output())->toBe($output)
            ->and(iterator_count(new FilesystemIterator($this->cli_state)))->toBe(0);

        $process->signal(SIGTERM);
        expect($process->waitForExit(2.0))->toBe(0);
    } finally {
        $process->shutdown();
        putenv($previous_environment === false ? 'SUPERVISOR_ENABLED' : 'SUPERVISOR_ENABLED='.$previous_environment);
    }
});

it('does not park manual reset failures even when the supervisor environment is inherited', function (): void {
    $previous_environment = getenv('SUPERVISOR_ENABLED');
    putenv('SUPERVISOR_ENABLED=1');

    try {
        $result = runOctaneGuardCommand(['--reset', '--invalid'], $this->cli_directory.'/command');
        expect($result['exit_code'])->toBe(Guard::REFUSED)
            ->and($result['output'])->toContain('REFUSED')
            ->and($result['output'])->not->toContain('BLOCKED');
    } finally {
        putenv($previous_environment === false ? 'SUPERVISOR_ENABLED' : 'SUPERVISOR_ENABLED='.$previous_environment);
    }
});
