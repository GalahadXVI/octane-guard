<?php

declare(strict_types=1);

$directory = $argv[1];
$mode = $argv[2];

if (! is_dir($directory))
    throw new RuntimeException('Fixture directory must already exist.');

pcntl_async_signals(true);

/**
 * Record that a disposable worker finished a graceful shutdown.
 */
$terminate = static function () use ($directory): never {
    file_put_contents($directory.'/stopped.json', json_encode(['pid' => getmypid()], JSON_THROW_ON_ERROR));
    exit(0);
};

pcntl_signal(SIGTERM, $mode === 'stubborn' ? SIG_IGN : $terminate);
pcntl_signal(SIGINT, $terminate);

$started = json_encode(['pid' => getmypid(), 'pgid' => posix_getpgrp(), 'mode' => $mode], JSON_THROW_ON_ERROR);
file_put_contents($directory.'/starts.jsonl', $started."\n", FILE_APPEND | LOCK_EX);
file_put_contents($directory.'/started.json', $started);

if ($mode === 'fail')
    exit(1);

if ($mode === 'delayed-fail') {
    usleep(1_500_000);
    exit(1);
}

if ($mode === 'exit-zero')
    exit(0);

if ($mode === 'orphan') {
    $child_pid = pcntl_fork();

    if ($child_pid === -1)
        throw new RuntimeException('Could not fork the disposable orphan fixture.');

    if ($child_pid > 0) {
        $deadline = hrtime(true) + 3_000_000_000;

        while (! is_file($directory.'/orphan-child.json') && hrtime(true) < $deadline) {
            clearstatcache();
            usleep(10_000);
        }

        exit(1);
    }

    pcntl_signal(SIGTERM, SIG_IGN);
    file_put_contents($directory.'/orphan-child.json', json_encode(['pid' => getmypid(), 'pgid' => posix_getpgrp()], JSON_THROW_ON_ERROR));
}

if ($mode === 'hold-port') {
    $socket = stream_socket_server('tcp://127.0.0.1:'.(int) $argv[3], $error_number, $error_message);

    if ($socket === false)
        throw new RuntimeException('Could not bind the disposable test socket.');

    file_put_contents($directory.'/listening.json', json_encode(['address' => stream_socket_get_name($socket, false)], JSON_THROW_ON_ERROR));
}

if (! in_array($mode, ['clean', 'stubborn', 'orphan', 'hold-port'], true))
    throw new RuntimeException('Unknown disposable worker mode.');

while (true)
    usleep(50_000);
