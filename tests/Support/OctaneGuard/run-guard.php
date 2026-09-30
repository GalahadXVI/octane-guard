<?php

declare(strict_types=1);

require_once __DIR__.'/../../../src/state.php';
require_once __DIR__.'/../../../src/guard.php';

$guard = new GalahadXVI\OctaneGuard\Guard(
    new GalahadXVI\OctaneGuard\State($argv[1], $argv[2], '11111111-1111-4111-8111-111111111111'),
    [PHP_BINARY, __DIR__.'/fixture-worker.php', $argv[3], $argv[4], ...array_slice($argv, 5)],
    $argv[2],
    0.25,
    0.25,
);

exit($guard->run());
