# ClassEngage Quick Setup

For university IT teams evaluating whether the plugin needs Docker.

## Short Answer

ClassEngage does not require Docker.

It works on:

- a normal Moodle server
- a Dockerized Moodle stack
- a mixed deployment where Moodle web traffic runs in one runtime and background tasks run in another

## Minimum Operational Requirement

The runtime that executes background tasks must provide:

- Moodle cron
- PHP CLI access to the same Moodle site
- `shell_exec`
- `pdftotext`
- `ZipArchive`
- one configured AI provider

Recommended:

- `pdfinfo`
- PHP `Imagick`

## Choose Your Deployment

### Option A: Standard Moodle Server

1. Install the plugin into `mod/classengage`.
2. Install `poppler-utils` and `php-zip` on the server.
3. Configure Moodle cron every minute.
4. Optionally run `mod/classengage/classes/task/task_worker.php` as a long-running service.
5. Configure an AI provider in plugin settings.

### Option B: Docker With Sidecar Worker

1. Keep the main Moodle webserver image unchanged if preferred.
2. Add a worker container and a cron container.
3. Mount the same Moodle code and `moodledata` into webserver, worker, and cron.
4. Ensure all containers point at the same database and `config.php`.
5. Configure an AI provider in plugin settings.

## Verification

Run this in the environment that will execute cron or the worker:

```bash
php /path/to/moodle/mod/classengage/cli/runtime_check.php
```

Then test:

1. Upload a PDF.
2. Start inspection or generation.
3. Confirm cron or the worker picks up the task.
4. Confirm generated questions appear in Moodle.

## Recommendation

For a university deployment:

- keep normal Moodle cron enabled
- add the dedicated worker if faster 2-5 second turnaround matters
- describe Docker as an optional packaging choice, not a plugin requirement
