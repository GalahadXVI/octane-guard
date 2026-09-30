<?php

declare(strict_types=1);

$python = $argv[1];
$source = $argv[2];
$configuration = $argv[3];
$launcher = 'import sys; sys.path.insert(0, sys.argv[1]); configuration = sys.argv[2]; '
    .'sys.argv = ["supervisord", "-n", "-c", configuration]; '
    .'from supervisor.supervisord import main; main()';

pcntl_exec($python, ['-c', $launcher, $source, $configuration]);
exit(120);
