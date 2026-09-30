# Octane Guard

A small PHP wrapper that cleans up Laravel Octane's worker processes before Supervisor starts it again. It is designed for **Octane with Swoole**, including sites managed by Laravel Forge.

You keep using the existing Forge background process to start, stop, and restart Octane. The guard runs inside that process. It does not change Horizon or the shared Supervisor/systemd service.

> **Alpha software.** Automated process tests pass on Linux, but testing with real Octane/Swoole and whole-service restarts is still required before production use.

## How it works

1. Supervisor starts the guard, which starts one Octane instance.
2. If Octane exits, the guard stops the processes in its own process group. It never searches for processes by name or port.
3. The replacement guard starts Octane only after confirming the previous group is gone.
4. After **three failed runs**, automatic recovery stops until you fix the cause and reset the count.

Those three failures can be months apart. Deployments and reboots do not clear the count. A normal requested stop does not count as a failure.

**Stopping a running Octane instance takes about 10 seconds.** The guard first sends TERM, then KILLs its own group, including itself. Supervisor may therefore log SIGKILL on a normal stop. A deliberate stop in Forge stays stopped.

## Before installing

This version supports:

- Linux with PHP 8.3+, `pcntl`, `posix`, and Swoole.
- Foreground Swoole at **`127.0.0.1:8000`**. The address and port are currently fixed.
- One Supervisor process per site, running as the application's user.
- A persistent state directory outside the application and its release folders.

Do not use daemon mode, `--watch`, a custom Swoole command, or subprocesses that detach from Octane's process group. This is process cleanup, not an HTTP health check; it cannot detect a server that is alive but stuck.

## Install the package

From your application's root directory:

```bash
composer config repositories.octane-guard vcs https://github.com/GalahadXVI/octane-guard.git
composer require galahadxvi/octane-guard:0.1.0-alpha.2
```

The repository is public; no GitHub token is needed for normal downloads. It is not on Packagist, so the repository entry is required. Commit your application's Composer files and deploy normally. Installing the package does not start processes or change server settings.

## Set up the existing Octane process

Plan a brief interruption while switching the process. The examples below use `/home/forge/example.com`; replace it with your stable site path, even when deployments use release symlinks.

### 1. Create the state directory

Run this **as `forge`**, not root:

```bash
install -d -m 0700 /home/forge/.local/state/octane-guard/example
```

Use a different directory for each site. Keep it across deployments and reboots. Let the guard create its files; do not copy another site's state or delete it to bypass a refusal.

### 2. Update only the Octane entry

Stop the existing Octane process in Forge and confirm its old workers are gone. Do not add a second Octane entry alongside it.

Edit that program's file under `/etc/supervisor/conf.d/`. Keep its existing program name, user, process name, working directory, and logging settings. Replace the command and set these values:

```ini
command=/usr/bin/php8.4 /home/forge/example.com/vendor/bin/octane-guard --run --app-dir=/home/forge/example.com --state-dir=/home/forge/.local/state/octane-guard/example
numprocs=1
autostart=true
autorestart=unexpected
exitcodes=0,78,255
startsecs=1
startretries=3
stopwaitsecs=20
stopsignal=TERM
stopasgroup=true
killasgroup=true
```

Check that `/usr/bin/php8.4` is the correct executable on your server. The guard uses that same PHP executable to start Octane.

**These settings are required.** In particular, `autorestart=true` would keep restarting a guard that has refused to run. The 20-second stop allowance gives the guard time to finish its 10-second cleanup. Changing only Forge's command field is not enough.

### 3. Apply that program's configuration

For an entry named `[program:daemon-123456]`, use:

```bash
sudo supervisorctl reread
sudo supervisorctl update daemon-123456
sudo supervisorctl status 'daemon-123456:*'
```

Replace the example ID with your Octane program's name. `update` applies the change and starts the updated program because `autostart=true`; you do not need to restart the shared Supervisor service. [Supervisor command reference](https://supervisord.org/running.html#supervisorctl-actions)

You can then use the existing Forge process controls. Forge may rewrite manually edited settings, so check them after editing the entry in the panel.

## If recovery stops

Read the existing Forge daemon log. Messages starting with `[octane-guard]` explain whether the failure count is exhausted, another guard holds the lock, or the state/setup is invalid.

After fixing the cause, stop the Forge entry and run this as `forge`:

```bash
php8.4 /home/forge/example.com/vendor/bin/octane-guard --reset --app-dir=/home/forge/example.com --state-dir=/home/forge/.local/state/octane-guard/example
```

Then start the same Forge entry. Reset refuses while a guard or its recorded process group is still alive. Missing or corrupt state also causes a refusal; deleting the state is not a recovery step.

Exit `78` means a safety refusal. Supervisor may make a few startup attempts before showing `FATAL`, but the guard will not launch more Octane instances after its limit is reached.

## Updates and limits

- Update to a specific package version, deploy it, then restart the Forge background process. `octane:reload` alone does not replace the running guard.
- The guard cannot adopt orphan processes left before installation. If the guard alone is killed and its workers survive, the replacement refuses to start rather than guessing which old processes to kill.
- Forced termination can interrupt requests; it does not undo completed database writes or external actions.
- To roll back, stop the guarded entry, confirm its group is gone, then restore the original Octane command and Supervisor settings.

## Development

The command does not boot Laravel or load the consuming application's Composer autoloader. Its state files are tied to the site path and Linux boot ID.

Use PHP 8.4 for the development dependency lock:

```bash
composer install
composer validate --strict
composer test
```

Supervisor integration tests need Supervisor 4.2.5 and Python. Set `OCTANE_GUARD_SUPERVISOR_SOURCE` to the directory containing its `supervisor` package and `OCTANE_GUARD_SUPERVISOR_PYTHON` to the interpreter. Without those settings, those tests skip. CI installs both and runs the suite on Ubuntu.

Tests use temporary directories, isolated process groups, and local sockets. They do not boot an application or control the server's existing Supervisor. Before production use, also test your actual PHP/Octane/Swoole versions under requests and repeated whole-Supervisor restarts, checking that old worker groups disappear before replacements start.
