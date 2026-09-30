<?php

declare(strict_types=1);

use GalahadXVI\OctaneGuard\ApplicationPath;
use GalahadXVI\OctaneGuard\Guard;
use GalahadXVI\OctaneGuard\GuardException;
use GalahadXVI\OctaneGuard\State;

require_once dirname(__DIR__, 3).'/src/application-path.php';
require_once dirname(__DIR__, 3).'/src/state.php';
require_once dirname(__DIR__, 3).'/src/guard.php';

beforeEach(function (): void {
    $this->original_directory = getcwd();
    $this->original_pwd = getenv('PWD');
    $this->path_root = realpath(sys_get_temp_dir()).'/octane-path-'.bin2hex(random_bytes(8));
    mkdir($this->path_root, 0700);
    foreach (['release-a', 'release-b'] as $release) {
        mkdir($this->path_root.'/'.$release, 0700);
        file_put_contents($this->path_root.'/'.$release.'/artisan', '<?php');
    }
    symlink($this->path_root.'/release-a', $this->path_root.'/current');
    chdir($this->path_root.'/current');
    putenv('PWD=/unrelated/inherited/directory');
});

afterEach(function (): void {
    chdir($this->original_directory);
    putenv($this->original_pwd === false ? 'PWD' : 'PWD='.$this->original_pwd);
    unlink($this->path_root.'/current');
    foreach (['release-a', 'release-b'] as $release) {
        unlink($this->path_root.'/'.$release.'/artisan');
        rmdir($this->path_root.'/'.$release);
    }
    rmdir($this->path_root);
});

it('defaults to the current application directory and ignores an unrelated PWD', function (): void {
    expect(ApplicationPath::resolve(null, 'vendor/bin/octane-guard'))->toBe($this->path_root.'/release-a');
});

it('honours an explicit path independently of the working directory', function (): void {
    expect(ApplicationPath::resolve($this->path_root.'/current/', '/another/bin/octane-guard'))->toBe($this->path_root.'/current');
});

it('retains the stable launch path and failure history across release switches', function (): void {
    $command = $this->path_root.'/current/vendor/bin/octane-guard';
    $path = ApplicationPath::resolve(null, $command);
    $storage = State::defaultDirectory($path);
    mkdir($this->path_root.'/state', 0700);

    try {
        $state = new State($this->path_root.'/state', $path, '11111111-1111-4111-8111-111111111111');
        $state->acquire();
        $record = $state->read();
        $record['launches'] = Guard::MAX_LAUNCHES;
        $state->save($record);
        $state->closeInChild();
        chdir($this->path_root);
        unlink($this->path_root.'/current');
        symlink($this->path_root.'/release-b', $this->path_root.'/current');
        clearstatcache(true);
        chdir($this->path_root.'/current');

        $next_path = ApplicationPath::resolve(null, $command);
        $next_state = new State($this->path_root.'/state', $next_path, '11111111-1111-4111-8111-111111111111');
        $next_state->acquire();
        expect($next_path)->toBe($path)
            ->and($next_path)->toBe($this->path_root.'/current')
            ->and(State::defaultDirectory($next_path))->toBe($storage)
            ->and($next_state->read()['launches'])->toBe(Guard::MAX_LAUNCHES);
        $next_state->closeInChild();
    } finally {
        foreach (new FilesystemIterator($this->path_root.'/state') as $entry)
            unlink($entry->getPathname());
        rmdir($this->path_root.'/state');
    }
});

it('uses a logical shell directory only if it matches the current directory', function (): void {
    putenv('PWD='.$this->path_root.'/current');
    expect(ApplicationPath::resolve(null, 'vendor/bin/octane-guard'))->toBe($this->path_root.'/current');
});

it('refuses implicit startup from a directory without artisan', function (): void {
    chdir($this->path_root);
    expect(fn (): string => ApplicationPath::resolve(null, $this->path_root.'/current/vendor/bin/octane-guard'))
        ->toThrow(GuardException::class, 'Working Directory');
});
