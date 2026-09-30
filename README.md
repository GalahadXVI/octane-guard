# Octane Guard

A small PHP wrapper for Laravel Octane with Swoole. Forge runs the guard; the guard starts Octane and cleans up its worker processes before a replacement can start.

You keep using Forge's background-process controls and logs. Horizon and the shared Supervisor/systemd service are unaffected.

> **Alpha software.** Tests exercise real processes and Supervisor with fixture workers. Real Octane/Swoole under traffic and whole-service restarts still needs qualification before production use.

## Why this exists

This package was built after outages on two separate Forge-managed sites. Logs showed Supervisor restarting around unattended system updates, followed by repeated Octane startup failures and Supervisor eventually entering `FATAL` state.

The suspected failure sequence was:

1. Supervisor restarted and stopped the Octane launcher.
2. Some Swoole processes survived and kept Octane's listening port occupied.
3. Replacement Octane instances could not start, so Supervisor exhausted its startup attempts and gave up.

The restart and failed startup attempts were observed. Surviving Swoole processes explain the symptoms, but the exact cause has not been conclusively reproduced. This is not confirmation of a particular Forge, Supervisor, or Swoole bug.

### How the guard addresses it

Supervisor manages the guard instead of starting Octane directly. When a stop is requested or Octane exits, the guard tells its own process group to shut down, waits ten seconds, then forcibly terminates that group, including itself. The replacement guard checks that the previous group has disappeared before starting Octane.

**That cleanup before replacement is the part intended to prevent the outage.** It depends on Octane and its workers remaining in the guard's process group. If the guard cannot establish that the previous group is gone, it logs the reason and blocks startup instead of guessing which processes to kill.

The three-failure limit prevents repeated recovery attempts from continuing indefinitely; it does not fix the underlying failure. This behaviour has been tested with Supervisor and deliberately stubborn fixture workers. Validation with real Octane/Swoole under traffic is still required.

## Install

From your application's root directory:

```bash
composer config repositories.octane-guard vcs https://github.com/GalahadXVI/octane-guard.git
composer require galahadxvi/octane-guard:0.1.0-alpha.3
```

Deploy the Composer changes normally. Installation puts the executable at `vendor/bin/octane-guard`; it does not start anything or change your server configuration. The repository is public, so normal downloads need no GitHub token.

The server needs Linux, PHP 8.3+ with `pcntl`, `posix`, and Swoole, and a writable home directory for the site's operating-system user. This version starts foreground Swoole at **`127.0.0.1:8000`**.

## Laravel Forge: quick setup

Use the site's existing Octane background process. Replace its command with the guard command, which launches Octane itself.

1. **Install and deploy the package.** Confirm `vendor/bin/octane-guard` exists on the server.
2. **Stop the existing Octane background process in Forge.** Confirm its old workers have exited before switching. Keep the site's Octane/Nginx configuration enabled.
3. **Edit the background process using the fields below.** If creating a replacement entry, remove or disable the old entry's automatic startup so there is only one Octane launcher after reboots.
4. **Start the process and check its log.** Look for `[octane-guard] Started Octane child`, then check that the site responds.

Replace `/home/forge/example.com` with your site's stable path:

| Forge field | Value |
| --- | --- |
| Name | `Octane Guard` |
| Command | `/usr/bin/php8.4 /home/forge/example.com/vendor/bin/octane-guard --run --app-dir=/home/forge/example.com` |
| Working Directory | `/home/forge/example.com` |
| User | `forge`, or the site's existing isolated user |
| Processes | `1` |
| Start Seconds | `1` |
| Stop Seconds | `20` |
| Stop Signal | `SIGTERM` |

Use your actual PHP executable. The guard uses it to start Octane too. Keep Forge's automatic restart enabled. **No manual Supervisor file edits, separate installer command, or shared service changes are needed for this setup.**

The guard creates its private state directory automatically under the operating-system user's home: `~/.octane-guard/<hash-of-app-path>`. It stores the lock and failure count there, outside deployments. Keep the same `--app-dir` path, including when deployments use release symlinks.

**Forge showing “running” means the guard is alive, not that Octane is healthy.** If the guard blocks recovery, the explanation appears in that process's log. [Forge background processes and logs](https://laravel.com/forge/docs/resources/background-processes)

### Upgrading an older guard installation

Keep your existing `--state-dir=/absolute/state` argument on both run and reset commands. Omitting it would select a different directory and lose continuity with the existing failure history. Existing directories and files are validated, never silently repaired or reset.

The previous `autorestart=unexpected` configuration still works; new installations can use Forge's `autorestart=true` configuration. Restart the background process after deploying a guard update: `octane:reload` alone does not replace the running guard.

## What happens when Octane fails?

1. The guard signals only its own process group to stop. It never searches for processes by name or kills whatever holds port 8000.
2. After a 10-second grace period, it kills its group, including itself. Supervisor starts a replacement guard.
3. The replacement checks that the previous group has gone before launching Octane.
4. After **three failed runs**, the guard stays alive but idle. It logs `BLOCKED` and makes no more launch attempts until an operator fixes the cause and resets the count.

The failure count survives process restarts, deployments, and reboots. Failures can be months apart. A normal requested stop does not count as a failure.

**Normal stops also take about 10 seconds** and may show SIGKILL in Supervisor's log. A blocked guard has no new workers to clean up and stops promptly. A deliberate Stop in Forge stays stopped.

## If the log says BLOCKED

The guard logs the reason immediately and repeats it every five minutes. While blocked, it sleeps; it does not keep attempting recovery, launch workers, or kill processes. Missing prerequisites, invalid state, a competing guard, or a surviving previous process group also block startup.

Read the reason in the Forge background-process log, then:

1. Stop that background process in Forge.
2. Fix the reported cause. Do not delete the state directory or change its path to bypass a refusal.
3. If the failure count is exhausted, run the following as the same operating-system user that runs the guard:

```bash
php8.4 /home/forge/example.com/vendor/bin/octane-guard --reset --app-dir=/home/forge/example.com
```

4. Start the background process again and check its log and the site.

If you supplied `--state-dir` in Forge, include the same argument when resetting. Reset refuses while the guard holds the lock or its recorded process group still exists. Simply restarting a blocked process does not clear its failure count.

## Limits

- This is process cleanup, not an HTTP health check. It cannot detect a server that is alive but stuck.
- It supports one foreground Octane/Swoole instance per site, without daemon mode, `--watch`, custom Swoole commands, or children that detach from its process group.
- It cannot adopt workers left behind before installation. If only the guard dies and its workers survive, the replacement blocks instead of guessing which processes to kill.
- Forced termination can interrupt requests. It does not undo completed database writes or external actions.
- The blocked state handles failures the guard can detect after PHP starts. It cannot handle a missing PHP executable or an unreadable/broken executable that prevents the guard from running at all.
- To roll back, stop the guarded entry, confirm its workers have exited, and restore the original Octane command.

## Development

The executable does not boot Laravel or load the consuming application's Composer autoloader. State is tied to the stable application path and Linux boot ID.

Use PHP 8.4 for the development dependency lock:

```bash
composer install
composer validate --strict
composer test
```

Supervisor integration tests need Supervisor 4.2.5 and Python. Set `OCTANE_GUARD_SUPERVISOR_SOURCE` to the directory containing its `supervisor` package and `OCTANE_GUARD_SUPERVISOR_PYTHON` to the interpreter. Without those settings, these tests skip. CI installs both and runs the suite on Ubuntu.

Tests use temporary directories, isolated process groups, and local sockets. They do not boot an application or control the server's existing Supervisor. Before production use, also test your actual PHP/Octane/Swoole versions under requests and repeated whole-Supervisor restarts, checking that old worker groups disappear before replacements start.

## License

All rights reserved. See [LICENSE](LICENSE) for permitted use.
