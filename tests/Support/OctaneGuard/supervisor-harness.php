<?php

declare(strict_types=1);

namespace Tests\Support\OctaneGuard;

use RuntimeException;

require_once __DIR__.'/process-harness.php';

/**
 * Own a disposable Supervisor with a private socket and one fixture program.
 */
final class SupervisorHarness
{
    private ProcessHarness $process;

    /**
     * Build a configuration using exactly the production restart semantics.
     */
    public function __construct(private string $directory, private string $python, private string $source, string $mode)
    {
        foreach (['application', 'state', 'worker', 'supervisor'] as $name)
            mkdir($directory.'/'.$name, 0700);

        $command = implode(' ', array_map(
            static fn (string $argument): string => '"'.str_replace(['\\', '"', '%'], ['\\\\', '\\"', '%%'], $argument).'"',
            [PHP_BINARY, __DIR__.'/run-guard.php', $directory.'/state', $directory.'/application', $directory.'/worker', $mode],
        ));

        $configuration = "[unix_http_server]\nfile={$directory}/rpc.sock\nchmod=0600\n"
            ."[supervisord]\nnodaemon=true\nlogfile={$directory}/supervisord.log\npidfile={$directory}/supervisord.pid\nchildlogdir={$directory}\n"
            ."[rpcinterface:supervisor]\nsupervisor.rpcinterface_factory=supervisor.rpcinterface:make_main_rpcinterface\n"
            ."[program:guard]\ncommand={$command}\ndirectory={$directory}/application\n"
            ."autostart=true\nautorestart=unexpected\nexitcodes=0,78,255\nstartsecs=1\nstartretries=3\n"
            ."stopwaitsecs=2\nstopsignal=TERM\nstopasgroup=true\nkillasgroup=true\n"
            ."stdout_logfile={$directory}/guard.log\nredirect_stderr=true\n";

        file_put_contents($directory.'/supervisord.conf', $configuration);
        $this->process = new ProcessHarness(
            [__DIR__.'/run-supervisor.php', $python, $source, $directory.'/supervisord.conf'],
            $directory.'/supervisor',
        );
    }

    /**
     * Start only this fixture's Supervisor, then wait for its private socket.
     */
    public function start(): void
    {
        $this->process->start();
        $this->process->waitFor(fn (): bool => file_exists($this->directory.'/rpc.sock'));
    }

    /**
     * Wait for a condition while retaining diagnostic output on timeout.
     *
     * @param callable(): bool $condition
     */
    public function waitFor(callable $condition, float $timeout = 15.0): void
    {
        $this->process->waitFor($condition, $timeout);
    }

    /**
     * Send an XML-RPC request exclusively to this fixture's private socket.
     *
     * @param list<mixed> $arguments
     */
    public function rpc(string $method, array $arguments = []): mixed
    {
        $code = 'import sys, socket, json; socket.setdefaulttimeout(5); sys.path.insert(0, sys.argv[1]); '
            .'from supervisor.childutils import getRPCInterface; '
            .'rpc = getRPCInterface({"SUPERVISOR_SERVER_URL": "unix://" + sys.argv[2]}); '
            .'print(json.dumps(getattr(rpc.supervisor, sys.argv[3])(*json.loads(sys.argv[4]))))';

        $process = proc_open(
            [$this->python, '-c', $code, $this->source, $this->directory.'/rpc.sock', $method, json_encode($arguments, JSON_THROW_ON_ERROR)],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process))
            throw new RuntimeException('Could not start the isolated Supervisor RPC client.');

        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit_code = proc_close($process);

        if ($exit_code !== 0)
            throw new RuntimeException('Isolated Supervisor RPC failed: '.$errors);

        return json_decode($output, true, 64, JSON_THROW_ON_ERROR);
    }

    /**
     * Read one named fixture program's status, never the server's Supervisor.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return $this->rpc('getProcessInfo', ['guard']);
    }

    /**
     * Count actual child executions, independent of Supervisor's restart count.
     */
    public function launches(): int
    {
        $path = $this->directory.'/worker/starts.jsonl';

        return is_file($path) ? count(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;
    }

    /**
     * Shut down the disposable Supervisor and wait for its managed group cleanup.
     */
    public function shutdown(): void
    {
        try {
            if ($this->process->isRunning()) {
                $this->rpc('shutdown');
                $this->process->waitForExit(8.0);
            }
        } finally {
            $this->process->shutdown();
        }
    }
}
