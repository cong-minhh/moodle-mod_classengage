# Compatibility Matrix

This document describes ClassEngage compatibility in capability terms rather than Docker terms.

## Supported Deployment Types

| Deployment type | Supported | Notes |
|-----------------|-----------|-------|
| Standard Moodle on VM or bare metal | Yes | Install runtime dependencies on the host and run Moodle cron |
| Dockerized Moodle with custom web image | Yes | Optional convenience for bundling dependencies |
| Dockerized Moodle with sidecar worker and cron | Yes | Recommended when the main Moodle image should stay unchanged |
| Mixed web + separate CLI/worker runtime | Yes | The background runner must share code, config, database, and `moodledata` |

## Runtime Capability Matrix

| Capability | Required for PDF generation | Required for PPTX/DOCX | Notes |
|------------|-----------------------------|-------------------------|-------|
| Moodle cron | Yes | Yes | Required for scheduled tasks and fallback adhoc execution |
| PHP CLI bootstrap | Yes | Yes | Needed by cron and the optional worker |
| `shell_exec` | Sometimes | No | Required only when PDF mode is set to `external` |
| `pdftotext` | Sometimes | No | Required only when PDF mode is set to `external` |
| Bundled PDF parser | Sometimes | No | Required only when PDF mode is set to `bundled`; `auto` can use either backend |
| `pdfinfo` | No | No | Recommended for better PDF page counting |
| `ZipArchive` | No | Yes | Required for PPTX and DOCX inspection |
| PHP `Imagick` | No | No | Recommended for PDF previews |
| Built-in generator | Yes | Yes | Included in the plugin for text-based generation |
| External AI provider configuration | No | No | Optional, but recommended for better quality and image-heavy material |

## Execution Models

| Model | Supported | Latency | Notes |
|-------|-----------|---------|-------|
| Cron only | Yes | Usually up to 1 minute | Simplest operational model |
| Cron + `task_worker.php` | Yes | Usually 2-5 seconds | Recommended for production |

## Important Caveat

The diagnostics page at `/mod/classengage/nlp_diagnostics.php` checks the runtime serving that request. In split deployments, the webserver runtime and the background runner may differ.

If cron or the worker runs elsewhere, validate that runtime directly with:

```bash
php /path/to/moodle/mod/classengage/cli/runtime_check.php
```

## Recommendation For University Deployments

Present the plugin requirements like this:

> ClassEngage works on both standard Moodle and Dockerized Moodle. Docker is optional. The key requirement is that the runtime executing background tasks has Moodle cron plus the PDF and AI dependencies needed by the current NLP pipeline.
> 
> For the self-contained path, use the bundled PDF parser and built-in generator. External AI services are optional.
