# Standard Moodle Setup

This guide covers running ClassEngage on a normal Moodle installation without Docker.

## Compatibility Summary

ClassEngage is not Docker-only. A standard Moodle deployment works if the runtime that executes background tasks can provide the same capabilities as the Docker worker image.

For the current NLP pipeline, that means:

- Moodle cron is enabled and runs reliably
- the Moodle CLI runtime can bootstrap the same site as the web runtime
- `shell_exec` is enabled for the task runner
- `pdftotext` is installed for PDF text extraction
- `ZipArchive` is available for PPTX and DOCX processing
- at least one AI provider is configured

Recommended for full PDF previews:

- `pdfinfo`
- PHP `Imagick`
- ImageMagick policy that allows PDF reads

## Install The Plugin

1. Copy the plugin into `mod/classengage`.
2. Visit **Site administration > Notifications** to complete installation.
3. Configure an AI provider in **Site administration > Plugins > Activity modules > In-class Learning Engagement**.

## Install Server Dependencies

Install the required tools in the same environment that will run Moodle cron or the optional worker.

Debian or Ubuntu example:

```bash
sudo apt-get update
sudo apt-get install -y poppler-utils php-zip
```

Optional preview support:

```bash
sudo apt-get install -y imagemagick php-imagick
```

If ImageMagick blocks PDF reads by policy, allow PDF decoding in the server policy file used by your distro.

## Configure Background Processing

### Minimum: Moodle Cron

Run Moodle cron every minute:

```bash
* * * * * /usr/bin/php /path/to/moodle/admin/cli/cron.php >/dev/null 2>&1
```

This is required even if you later add a dedicated worker, because ClassEngage still declares standard scheduled tasks in `db/tasks.php`.

### Recommended: Dedicated Worker

For faster task pickup, run the ClassEngage worker alongside normal Moodle cron.

Example `systemd` unit:

```ini
[Unit]
Description=ClassEngage task worker
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/path/to/moodle
ExecStart=/usr/bin/php /path/to/moodle/mod/classengage/classes/task/task_worker.php --interval=2 --component=mod_classengage --verbose
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Enable it:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now classengage-worker
```

## Verify The Runtime

Run the CLI checker in the same environment as cron or the worker:

```bash
php /path/to/moodle/mod/classengage/cli/runtime_check.php
```

This verifies:

- PHP CLI bootstrap
- `shell_exec`
- `pdftotext`
- `pdfinfo`
- `Imagick`
- `ZipArchive`
- configured AI providers

## Operational Notes

- Web requests queue work through `slides_api.php`.
- Document inspection runs in `classes/task/inspect_document_task.php`.
- Question generation runs in `classes/task/generate_nlp_task.php`.
- The optional worker improves latency, but it does not replace Moodle cron.

## Acceptance Test

1. Upload a PDF in ClassEngage.
2. Trigger inspection or question generation.
3. Confirm cron or the worker picks up the task.
4. Confirm questions appear in Moodle.
5. If PDF previews are missing but questions generate, verify `Imagick` and the ImageMagick PDF policy.
