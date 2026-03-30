# Docker Setup

This guide covers Docker deployment for ClassEngage. Docker is optional, but it is a convenient way to package the runtime dependencies needed by the plugin.

## What Docker Changes

The Docker files in `bin/` are deployment wiring, not plugin logic.

- `local.yml` keeps the webserver focused on HTTP traffic
- `Dockerfile.worker` packages the fast background worker runtime
- `Dockerfile.cron` packages the cron runtime
- `webserver.custom.yml` and `Dockerfile.custom` are optional webserver overrides for environments that want the same PDF tooling in the web container

## Runtime Requirements

Whichever container executes background tasks must have:

- access to the same Moodle code
- access to the same `config.php`
- access to the same database
- access to the same `moodledata`
- `shell_exec` enabled
- `pdftotext` installed
- `ZipArchive` available

Recommended:

- `pdfinfo`
- PHP `Imagick`
- ImageMagick PDF policy adjusted for reads

## Recommended Container Layout

Use three responsibilities:

1. `webserver`
   - handles HTTP requests
   - may remain on the standard Moodle image
2. `worker`
   - runs `mod/classengage/classes/task/task_worker.php`
   - polls every few seconds for `mod_classengage` adhoc tasks
3. `cron`
   - runs `admin/cli/cron.php`
   - remains required for normal Moodle scheduled tasks and fallback adhoc execution

## Shared Volumes

The worker and cron must share these with the webserver:

- Moodle code at `/var/www/html`
- Moodle data at `/var/www/moodledata`

Without shared `moodledata`, the worker will not see uploaded slides, caches, or generated assets.

## Minimal Compose Pattern

```yaml
services:
  webserver:
    image: moodlehq/moodle-php-apache:${MOODLE_DOCKER_PHP_VERSION}
    volumes:
      - ./moodle:/var/www/html:ro
      - ./moodledata:/var/www/moodledata:rw

  worker:
    build:
      context: ./bin
      dockerfile: Dockerfile.worker
    volumes:
      - ./moodle:/var/www/html:ro
      - ./moodledata:/var/www/moodledata:rw
    environment:
      WORKER_INTERVAL: "2"
      WORKER_COMPONENT: "mod_classengage"

  cron:
    build:
      context: ./bin
      dockerfile: Dockerfile.cron
    volumes:
      - ./moodle:/var/www/html:ro
      - ./moodledata:/var/www/moodledata:rw
```

## Optional Webserver Override

If you want the web container itself to have the PDF tools, use `webserver.custom.yml` and `Dockerfile.custom`.

This is optional. The plugin does not require the webserver image to be custom if the background runner already has the required tools.

## Verification

Run these checks inside the container that executes background tasks:

```bash
php /var/www/html/mod/classengage/cli/runtime_check.php
php /var/www/html/mod/classengage/classes/task/task_worker.php --help
php /var/www/html/admin/cli/cron.php --help
```

## Operational Recommendation

- keep Moodle cron enabled
- add the dedicated worker for fast 2-5 second pickup
- treat the custom webserver image as a convenience, not a plugin requirement
