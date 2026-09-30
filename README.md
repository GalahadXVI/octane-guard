# galahadxvi/octane-guard

Private PHP command for running Laravel Octane with Swoole under Supervisor.

**Pre-release. Not approved for production installation yet.** Actual Octane/Swoole lifecycle testing on isolated Linux, including whole Supervisor service restarts, remains required. The automated tests use disposable worker processes; a green result does not establish Swoole compatibility.

## Purpose

Supervisor owns automatic restarts. The guard launches one Octane instance and remains alive while its process group is cleaned up. It never calls `supervisorctl`, searches for port owners, or kills processes by name.

On an Octane parent exit or a requested stop, the guard sends TERM to its own group, waits ten seconds, then sends KILL to that group, including itself. The replacement must confirm the previous group has disappeared before starting another generation. An intentional Supervisor stop stays stopped.

Three unexpected terminations exhaust the persistent launch budget, even if months apart. Failed starts and abrupt guard termination count too. Requested stops refund their own reservation. No automatic reset occurs on deploy, reboot, or after an elapsed interval.

The guard handles process exits and shutdown cleanup. It does not detect HTTP hangs, adopt pre-existing orphan processes, or manage detached application subprocesses.

## Requirements

- Linux, PHP 8.3+, `pcntl`, `posix`, and Swoole for the managed application.
- Foreground Swoole on `127.0.0.1:8000`, with no daemonize override, watch mode, or custom Swoole command.
- Supervisor runs exactly one guard as the application user, directly as its process-group leader.
- A private, local state directory outside deployed releases, owned by that user with mode `0700`.
- The exact Supervisor restart policy below. Forge's default `autorestart=true` defeats a stopped circuit breaker.

There is no Laravel runtime dependency and no application or Composer autoloader is loaded by the command. State files are bound to the stable application path and Linux boot identifier. A reboot invalidates the old process group without restoring the failure budget.

## Private Composer installation

This repository is private and is not published to Packagist. Give the deployment user read access to this repository through an SSH key or appropriately scoped GitHub credential. Do not commit credentials.

Add the VCS repository to the consuming application's existing Composer repositories:

```json
{
    "type": "vcs",
    "url": "git@github.com:GalahadXVI/octane-guard.git"
}
```

After an approved release is tagged, require its exact version and deploy the resulting application lock file. Development evaluation can use `dev-main`; do not automatically update the production recovery code from a moving branch.

```bash
composer require galahadxvi/octane-guard:dev-main
php8.4 vendor/bin/octane-guard --help
```

Installing the package does not alter Supervisor or start any processes.

## Supervisor setup after qualification

Create the state directory as the same user that will run Octane:

```bash
install -d -m 0700 /home/forge/.local/state/octane-guard/example
```

Use one state directory consistently for this site. Do not put it in a release directory, delete it during deployment, pre-create `guard.lock`, or copy another site's state.

Update the existing Octane program. The following uses example paths and a placeholder program name; preserve the site's actual Forge program identity and log settings:

```ini
[program:existing-octane-program]
directory=/home/forge/example.com
command=/usr/bin/php8.4 /home/forge/example.com/vendor/bin/octane-guard --run --app-dir=/home/forge/example.com --state-dir=/home/forge/.local/state/octane-guard/example
user=forge
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
redirect_stderr=true
stdout_logfile=/home/forge/.forge/existing-octane-program.log
stdout_logfile_maxbytes=5MB
stdout_logfile_backups=3
```

Confirm the PHP executable path on the server. The guard launches Octane using the same PHP executable. Keep `--app-dir` a stable site path when using symlinked releases.

This is more than a command-field edit in Forge: the per-program restart settings are mandatory. Forge may overwrite manual settings after panel edits, so recheck them before subsequent starts. No shared Supervisor/systemd or Horizon settings are changed by this package.

Normal stops take approximately ten seconds and can appear as SIGKILL in Supervisor logs, even when Octane finished earlier. The twenty-second allowance gives the guard time to complete its cleanup. Forced termination does not undo external effects from interrupted requests.

During migration, stop the old direct Octane entry and verify its processes are gone before activating the guard. Never run both entries together. Rollback likewise requires stopping the guarded entry and confirming its group is gone before restoring the original command and settings.

## When recovery stops

A circuit-open or safety refusal exits `78`. PHP startup/fatal exit `255` is also terminal under the required restart policy. An exit before `startsecs` may still cause bounded Supervisor startup retries before FATAL.

After investigating and fixing the cause, stop the Forge entry and reset as the application user:

```bash
php8.4 /home/forge/example.com/vendor/bin/octane-guard --reset --app-dir=/home/forge/example.com --state-dir=/home/forge/.local/state/octane-guard/example
```

Then start the existing Forge entry. Reset refuses while the guard lock or previous process group is active. If only the guard was killed and its children remain, the replacement deliberately refuses to launch or kill those old numeric IDs. Missing/corrupt state also refuses startup; deleting history is not an automatic repair procedure.

## Development and verification

Use PHP 8.4 for the committed development dependency lock:

```bash
composer install
composer validate --strict
./vendor/bin/pest
```

The Supervisor integration tests require an unpacked Supervisor 4.2.5 source directory and a Python interpreter with its dependencies. Without the source setting, those tests skip explicitly:

```bash
OCTANE_GUARD_SUPERVISOR_SOURCE=/absolute/path/to/supervisor-4.2.5 \
OCTANE_GUARD_SUPERVISOR_PYTHON=/absolute/path/to/python \
./vendor/bin/pest
```

The suite creates isolated process groups and temporary loopback/Unix sockets. It does not boot Laravel, touch a database, or connect to a server's existing Supervisor. Supervisor test failures retain temporary logs for diagnosis.

Before production approval, exercise the real PHP/Octane/Swoole versions on disposable Linux: verify the complete foreground process tree retains the guard's group, repeatedly restart the whole Supervisor service with `KillMode=process`, test resistant workers and active requests, confirm old groups disappear before replacements, and verify deliberate stops and the persistent failure limit.

## Ownership

Private proprietary package under `galahadxvi`. No public Packagist publication or open-source license is granted.
