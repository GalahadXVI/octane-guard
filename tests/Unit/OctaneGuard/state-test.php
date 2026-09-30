<?php

declare(strict_types=1);

use GalahadXVI\OctaneGuard\State;

require_once dirname(__DIR__, 3).'/src/state.php';

/**
 * Remove only this test's temporary fixture, without following symlinks.
 */
function removeOctaneGuardStateFixture(string $directory): void
{
    foreach (new DirectoryIterator($directory) as $entry) {
        if ($entry->isDot())
            continue;

        if ($entry->isDir() && !$entry->isLink())
            removeOctaneGuardStateFixture($entry->getPathname());
        else
            unlink($entry->getPathname());
    }

    rmdir($directory);
}

beforeEach(function (): void {
    $this->fixture_directory = realpath(sys_get_temp_dir()).'/octane-state-'.bin2hex(random_bytes(12));
    $this->state_directory = $this->fixture_directory.'/state';
    $this->application_directory = $this->fixture_directory.'/application';
    mkdir($this->fixture_directory, 0700);
    mkdir($this->state_directory, 0700);
    mkdir($this->application_directory, 0700);
});

afterEach(function (): void {
    removeOctaneGuardStateFixture($this->fixture_directory);
});

it('preserves a launch reservation across guard instances', function (): void {
    $state = new State($this->state_directory, $this->application_directory, '11111111-1111-4111-8111-111111111111');
    $state->acquire();
    $record = $state->read();
    $record['launches']++;
    $record['pgid'] = getmypid();
    $state->save($record);
    $state->closeInChild();

    $replacement = new State($this->state_directory, $this->application_directory, '11111111-1111-4111-8111-111111111111');
    $replacement->acquire();

    expect($replacement->read())->toBe($record)
        ->and(fileowner($this->state_directory.'/state.json'))->toBe(posix_geteuid())
        ->and(fileperms($this->state_directory.'/state.json') & 0077)->toBe(0);

    $replacement->closeInChild();
});

it('does not permit another guard to acquire an occupied lock', function (): void {
    $state = new State($this->state_directory, $this->application_directory, '11111111-1111-4111-8111-111111111111');
    $state->acquire();
    $contender = new State($this->state_directory, $this->application_directory, '11111111-1111-4111-8111-111111111111');

    expect(fn () => $contender->acquire())->toThrow(RuntimeException::class);

    $state->closeInChild();
    $contender->acquire();
    $contender->closeInChild();
});

it('does not release the parent lock when the forked child closes its descriptor', function (): void {
    if (!function_exists('pcntl_fork'))
        $this->markTestSkipped('This check requires pcntl.');

    $code = <<<'PHP'
require $argv[1];
$state = new GalahadXVI\OctaneGuard\State($argv[2], $argv[3], '11111111-1111-4111-8111-111111111111');
$state->acquire();
$child_pid = pcntl_fork();
if ($child_pid === -1) exit(2);
if ($child_pid === 0) {
    $state->closeInChild();
    $other = new GalahadXVI\OctaneGuard\State($argv[2], $argv[3], '11111111-1111-4111-8111-111111111111');
    try { $other->acquire(); exit(3); }
    catch (RuntimeException) { exit(0); }
}
pcntl_waitpid($child_pid, $status);
exit(pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 4);
PHP;
    $process = proc_open([PHP_BINARY, '-r', $code, dirname(__DIR__, 3).'/src/state.php', $this->state_directory, $this->application_directory], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect(is_resource($process))->toBeTrue();

    foreach ($pipes as $pipe)
        fclose($pipe);

    expect(proc_close($process))->toBe(0);
});

it('refuses to rebuild missing history from an existing lock', function (): void {
    $state = new State($this->state_directory, $this->application_directory, '11111111-1111-4111-8111-111111111111');
    $state->acquire();
    $state->closeInChild();
    unlink($this->state_directory.'/state.json');

    expect(fn () => $state->acquire())->toThrow(RuntimeException::class)
        ->and(file_exists($this->state_directory.'/state.json'))->toBeFalse();
});

it('continues refusing a replacement lock when old state already exists', function (): void {
    $state = new State($this->state_directory, $this->application_directory, '11111111-1111-4111-8111-111111111111');
    $state->acquire();
    $original_record = $state->read();
    $state->closeInChild();
    unlink($this->state_directory.'/guard.lock');

    foreach (range(1, 3) as $attempt)
        expect(fn () => $state->acquire())->toThrow(RuntimeException::class);

    expect(json_decode(file_get_contents($this->state_directory.'/state.json'), true))->toBe($original_record);
});

it('refuses corrupted or mismatched durable state', function (string $corruption): void {
    $state = new State($this->state_directory, $this->application_directory, '11111111-1111-4111-8111-111111111111');
    $state->acquire();
    $record = $state->read();
    $state->closeInChild();

    $contents = match ($corruption) {
        'invalid-json' => '{invalid',
        'missing-field' => json_encode(array_diff_key($record, ['launches' => true])),
        'other-application' => json_encode(array_replace($record, ['application_path' => $this->fixture_directory])),
        'negative-count' => json_encode(array_replace($record, ['launches' => -1])),
        'string-count' => json_encode(array_replace($record, ['launches' => '0'])),
        'invalid-boot' => json_encode(array_replace($record, ['boot_id' => 'unknown'])),
        'oversized' => str_repeat(' ', 4097),
    };
    file_put_contents($this->state_directory.'/state.json', $contents);

    expect(fn () => $state->acquire())->toThrow(RuntimeException::class)
        ->and(file_get_contents($this->state_directory.'/state.json'))->toBe($contents);
})->with(['invalid-json', 'missing-field', 'other-application', 'negative-count', 'string-count', 'invalid-boot', 'oversized']);

it('refuses shared storage permissions', function (): void {
    chmod($this->state_directory, 0755);

    expect(fn () => new State($this->state_directory, $this->application_directory, '11111111-1111-4111-8111-111111111111'))->toThrow(RuntimeException::class);
});

it('refuses state stored inside the application directory', function (): void {
    $directory = $this->application_directory.'/guard-state';
    mkdir($directory, 0700);

    expect(fn () => new State($directory, $this->application_directory, '11111111-1111-4111-8111-111111111111'))->toThrow(RuntimeException::class);
});

it('refuses a symbolic lock or state file without modifying its target', function (string $filename): void {
    $state = new State($this->state_directory, $this->application_directory, '11111111-1111-4111-8111-111111111111');
    $state->acquire();
    $state->closeInChild();
    $target = $this->fixture_directory.'/unrelated';
    file_put_contents($target, 'must remain untouched');
    chmod($target, 0600);
    unlink($this->state_directory.'/'.$filename);
    symlink($target, $this->state_directory.'/'.$filename);

    expect(fn () => $state->acquire())->toThrow(RuntimeException::class)
        ->and(file_get_contents($target))->toBe('must remain untouched');
})->with(['guard.lock', 'state.json']);

it('keeps application identity stable across release symlink changes', function (): void {
    $first_release = $this->fixture_directory.'/release-one';
    $second_release = $this->fixture_directory.'/release-two';
    $current_release = $this->fixture_directory.'/current';
    mkdir($first_release, 0700);
    mkdir($second_release, 0700);
    symlink($first_release, $current_release);
    $state = new State($this->state_directory, $current_release, '11111111-1111-4111-8111-111111111111');
    $state->acquire();
    $record = $state->read();
    $record['launches']++;
    $state->save($record);
    $state->closeInChild();
    unlink($current_release);
    symlink($second_release, $current_release);
    clearstatcache();
    $replacement = new State($this->state_directory, $current_release, '11111111-1111-4111-8111-111111111111');
    $replacement->acquire();

    expect($replacement->read())->toBe($record);

    $replacement->closeInChild();
});

it('clears only the previous process group after a different boot', function (): void {
    $old_boot = '11111111-1111-4111-8111-111111111111';
    $new_boot = '22222222-2222-4222-8222-222222222222';
    $state = new State($this->state_directory, $this->application_directory, $old_boot);
    $state->acquire();
    $record = $state->read();
    $record['launches']++;
    $record['pgid'] = getmypid();
    $state->save($record);
    $state->closeInChild();
    $replacement = new State($this->state_directory, $this->application_directory, $new_boot);
    $replacement->acquire();

    expect($replacement->read())->toBe(array_replace($record, ['boot_id' => $new_boot, 'pgid' => null]));

    $replacement->closeInChild();
});

it('refuses to save a reservation with an obsolete boot identity', function (): void {
    $state = new State($this->state_directory, $this->application_directory, '11111111-1111-4111-8111-111111111111');
    $state->acquire();
    $record = $state->read();
    $obsolete = array_replace($record, ['boot_id' => '22222222-2222-4222-8222-222222222222']);

    expect(fn () => $state->save($obsolete))->toThrow(RuntimeException::class)
        ->and($state->read())->toBe($record);

    $state->closeInChild();
});

it('refuses a storage file with shared permissions', function (string $filename): void {
    $state = new State($this->state_directory, $this->application_directory, '11111111-1111-4111-8111-111111111111');
    $state->acquire();
    $state->closeInChild();
    chmod($this->state_directory.'/'.$filename, 0644);

    expect(fn () => $state->acquire())->toThrow(RuntimeException::class);
})->with(['guard.lock', 'state.json']);

it('does not replace committed history with an interrupted temporary write', function (): void {
    $state = new State($this->state_directory, $this->application_directory, '11111111-1111-4111-8111-111111111111');
    $state->acquire();
    $record = $state->read();
    $record['launches']++;
    $state->save($record);
    $state->closeInChild();
    file_put_contents($this->state_directory.'/state-interrupted.tmp', '{partial');
    $replacement = new State($this->state_directory, $this->application_directory, '11111111-1111-4111-8111-111111111111');
    $replacement->acquire();

    expect($replacement->read())->toBe($record);

    $replacement->closeInChild();
});


it('creates private storage components without initializing state', function (): void {
    $directory = $this->fixture_directory.'/new-parent/site';

    State::provisionDirectory($directory, $this->application_directory);

    expect(is_dir($directory))->toBeTrue()
        ->and(fileperms(dirname($directory)) & 0777)->toBe(0700)
        ->and(fileperms($directory) & 0777)->toBe(0700)
        ->and(scandir($directory))->toBe(['.', '..']);
});

it('preserves existing reservations while provisioning storage repeatedly', function (): void {
    $state = new State($this->state_directory, $this->application_directory, '11111111-1111-4111-8111-111111111111');
    $state->acquire();
    $record = $state->read();
    $record['launches'] = 3;
    $state->save($record);
    $lock_inode = fileinode($this->state_directory.'/guard.lock');

    State::provisionDirectory($this->state_directory, $this->application_directory);
    State::provisionDirectory($this->state_directory, $this->application_directory);

    expect($state->read())->toBe($record)
        ->and(fileinode($this->state_directory.'/guard.lock'))->toBe($lock_inode);

    $state->closeInChild();
});

it('does not repair unsafe storage permissions during provisioning', function (): void {
    chmod($this->state_directory, 0755);

    expect(fn () => State::provisionDirectory($this->state_directory, $this->application_directory))->toThrow(RuntimeException::class)
        ->and(fileperms($this->state_directory) & 0777)->toBe(0755);
});

it('refuses unsafe ancestor permissions before creating storage', function (): void {
    $parent = $this->fixture_directory.'/shared';
    mkdir($parent, 0700);
    chmod($parent, 0777);

    expect(fn () => State::provisionDirectory($parent.'/state', $this->application_directory))->toThrow(RuntimeException::class)
        ->and(file_exists($parent.'/state'))->toBeFalse();
});

it('refuses symlinks and files while provisioning storage', function (string $kind): void {
    $path = $this->fixture_directory.'/obstacle';

    if ($kind === 'file')
        file_put_contents($path, 'untouched');
    else
        symlink($this->state_directory, $path);

    expect(fn () => State::provisionDirectory($path.'/site', $this->application_directory))->toThrow(RuntimeException::class)
        ->and(file_exists($this->state_directory.'/site'))->toBeFalse();

    if ($kind === 'file')
        expect(file_get_contents($path))->toBe('untouched');
})->with(['file', 'symlink']);

it('rejects non-normalized storage paths before creating directories', function (): void {
    expect(fn () => State::provisionDirectory($this->fixture_directory.'/new/../state', $this->application_directory))->toThrow(RuntimeException::class)
        ->and(file_exists($this->fixture_directory.'/new'))->toBeFalse();
});

it('refuses to provision storage inside the deployed application', function (): void {
    $directory = $this->application_directory.'/guard-state';

    expect(fn () => State::provisionDirectory($directory, $this->application_directory))->toThrow(RuntimeException::class)
        ->and(file_exists($directory))->toBeFalse();
});

it('does not reconstruct missing state when provisioning an existing directory', function (): void {
    $state = new State($this->state_directory, $this->application_directory, '11111111-1111-4111-8111-111111111111');
    $state->acquire();
    $state->closeInChild();
    unlink($this->state_directory.'/state.json');

    State::provisionDirectory($this->state_directory, $this->application_directory);

    expect(fn () => $state->acquire())->toThrow(RuntimeException::class)
        ->and(file_exists($this->state_directory.'/state.json'))->toBeFalse();
});


it('allows concurrent provisioning to converge on one private directory', function (): void {
    $directory = $this->fixture_directory.'/concurrent/site';
    $code = <<<'PHP'
require $argv[1];
GalahadXVI\OctaneGuard\State::provisionDirectory($argv[2], $argv[3]);
PHP;
    $processes = [];

    foreach (range(1, 4) as $attempt) {
        $process = proc_open([PHP_BINARY, '-r', $code, dirname(__DIR__, 3).'/src/state.php', $directory, $this->application_directory], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        expect(is_resource($process))->toBeTrue();

        foreach ($pipes as $pipe)
            fclose($pipe);

        $processes[] = $process;
    }

    foreach ($processes as $process)
        expect(proc_close($process))->toBe(0);

    expect(fileperms($directory) & 0777)->toBe(0700)
        ->and(scandir($directory))->toBe(['.', '..']);
});


it('derives stable separate default paths from the real user account without writing files', function (): void {
    $account = posix_getpwuid(posix_geteuid());
    $root = realpath($account['dir']).'/.octane-guard/';
    $previous_environment = getenv('HOME');
    putenv('HOME=/not-the-service-users-home');

    try {
        $path = State::defaultDirectory('/home/example/current');
        expect($path)->toBe($root.hash('sha256', '/home/example/current'))
            ->and(State::defaultDirectory('/home/example/current/'))->toBe($path)
            ->and(State::defaultDirectory('/home/example/another'))->not->toBe($path);
    } finally {
        putenv($previous_environment === false ? 'HOME' : 'HOME='.$previous_environment);
    }
});

it('rejects ambiguous application paths before deriving default storage', function (string $path): void {
    expect(fn (): string => State::defaultDirectory($path))->toThrow(\GalahadXVI\OctaneGuard\GuardException::class);
})->with(['relative/site', '/home/site/../other', '/home//site', '/']);
