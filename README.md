# Statamic Oh Dear Health Check

[![Packagist Version](https://img.shields.io/packagist/v/devskio/statamic-ohdear-health-check)](https://packagist.org/packages/devskio/statamic-ohdear-health-check)
[![Total Downloads](https://img.shields.io/packagist/dt/devskio/statamic-ohdear-health-check)](https://packagist.org/packages/devskio/statamic-ohdear-health-check)
[![License](https://img.shields.io/github/license/devskio/statamic-ohdear-addon)](LICENSE)
[![PHP](https://img.shields.io/packagist/php-v/devskio/statamic-ohdear-health-check)](composer.json)

A Statamic addon that exposes an [Oh Dear](https://ohdear.app)-compatible health-check endpoint and provides a control-panel configuration screen.

It is built as a Statamic-specific layer on top of [`devskio/laravel-ohdear-health-check`](https://github.com/devskio/laravel-ohdear-health-check), which provides the core HTTP endpoint, shared-secret middleware, and base checks (database, disk space, error log).

> **Related project:** [TYPO3 Oh Dear Health Check](https://github.com/devskio/TYPO3-OhDear-Health-Check) — the same concept for TYPO3 CMS.

---

## Features

- **CP configuration screen** – configure all checks and thresholds directly from the Statamic control panel
- **Statamic-specific checks**
  - Statamic version (compares installed vs latest GitHub release)
  - Storage folder size (excludes `framework/` and `debugbar/` caches)
  - Forgotten public files (warns about unexpected files in `public/`)
- **Shared-package checks** (managed by `devskio/laravel-ohdear-health-check`)
  - Database connectivity
  - Disk used space
  - PHP error log size
- Oh Dear-compatible JSON response format
- **Dashboard widget** – a control-panel widget showing the local checks alongside everything Oh Dear itself monitors (uptime, performance, broken links, mixed content, certificate health, DNS, domain expiry, scheduled tasks) when an API token is configured

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | ^8.2 |
| Statamic | ^6.0 |
| Laravel | ^12.0 \|\| ^13.0 |

---

## Installation

```bash
composer require devskio/statamic-ohdear-health-check
```

The `devskio/laravel-ohdear-health-check` package is installed automatically as a dependency.

### Publish config (optional)

```bash
# Publish the Statamic addon config
php artisan vendor:publish --tag="statamic-ohdear-health-check-config"

# Publish the blueprint (if you need to customise the CP form)
php artisan vendor:publish --tag="statamic-ohdear-health-check-blueprints"
```

---

## Configuration

All settings can be managed in **three ways** (listed by priority, highest first):

1. **Statamic Control Panel** – open **Tools → OhDear Health Check**. Changes are written directly to the published config files and take effect immediately — no `.env` editing or deployment required.
2. **Environment variables** – add any of the keys below to your `.env` file.
3. **Config file** – publish `config/statamic-ohdear-health-check.php` and edit it directly (useful for array values like `allowed_files`).

> ⚠️ Values saved through the CP will override both the config file defaults **and** env variables for the settings it manages. If you want env variables to always take precedence, rely solely on `.env` and leave the CP form untouched.

---

### Environment variables

```dotenv
# ---------------------------------------------------------------
# Route & authentication
# ---------------------------------------------------------------

# Shared secret used by Oh Dear to authenticate requests (required)
OHDEAR_HEALTH_CHECK_SECRET=your-secret-here

# Override the health-check endpoint path (optional)
 OHDEAR_HEALTH_CHECK_PATH=/ohdear-health-check
 STATAMIC_OHDEAR_HEALTH_CHECK_PATH=/ohdear-health-check  # takes precedence over OHDEAR_HEALTH_CHECK_PATH

# Response format sent to Oh Dear (default: ohdear)
 OHDEAR_RESPONSE_FORMAT=ohdear

# ---------------------------------------------------------------
# Oh Dear API (optional — powers the dashboard widget's monitor strip)
# ---------------------------------------------------------------

# Token from https://ohdear.app/user/api-tokens
OHDEAR_API_TOKEN=

# The number in your monitor URL, e.g. 12345 in ohdear.app/monitors/12345
OHDEAR_MONITOR_ID=

# Request timeout in seconds (default: 5)
 OHDEAR_API_TIMEOUT=5

# ---------------------------------------------------------------
# Shared checks
# ---------------------------------------------------------------

# Database connectivity check
 OHDEAR_ENABLE_DATABASE_CHECK=false

# Disk used-space check
 OHDEAR_ENABLE_DISK_USED_SPACE_CHECK=true
 OHDEAR_DISK_SPACE_ERROR_THRESHOLD=90    # %
 OHDEAR_DISK_SPACE_WARNING_THRESHOLD=70  # %

# PHP error-log size check
 OHDEAR_ENABLE_ERROR_LOG_SIZE_CHECK=true
 OHDEAR_ERROR_LOG_ERROR_THRESHOLD=500    # MB
 OHDEAR_ERROR_LOG_WARNING_THRESHOLD=50   # MB

# ---------------------------------------------------------------
# Statamic-specific checks
# ---------------------------------------------------------------

# Storage folder size check
 OHDEAR_ENABLE_STORAGE_FOLDER_SIZE_CHECK=true
 OHDEAR_STORAGE_FOLDER_SIZE_ERROR_THRESHOLD=500    # MB
 OHDEAR_STORAGE_FOLDER_SIZE_WARNING_THRESHOLD=50   # MB

# Statamic version check (compares installed vs latest GitHub release)
 OHDEAR_ENABLE_STATAMIC_VERSION_CHECK=true

# Forgotten public files check
 OHDEAR_ENABLE_FORGOTTEN_FILES_CHECK=true
```

### Config file

Publish the config for values that cannot be set via env (e.g. `allowed_files`, which is an array):

```bash
php artisan vendor:publish --tag="statamic-ohdear-health-check-config"
```

Then edit `config/statamic-ohdear-health-check.php`:

```php
// Extra filenames / glob patterns that are allowed in public/
'allowed_files' => [
    'sitemap.xml',
    'my-custom-file.txt',
],
```

### Control panel

Open **Tools → OhDear Health Check** in the Statamic CP to configure all options through a UI. Settings saved here are written to the published config files and take effect immediately.

### Dashboard widget

Add the widget to `config/statamic/cp.php`:

```php
'widgets' => [
    ['type' => 'ohdear_health_check', 'width' => 100],
],
```

Only users with the **View OhDear health check widget** permission see it — for everyone else it
renders nothing and Statamic drops it from the dashboard. It is a permission of its own, so
configuring the addon does not imply seeing the widget (and vice versa). Grant it per role under **Users → Permissions**. To skip running the checks entirely
for users who can't see it, gate the widget in `cp.php` as well:

```php
'widgets' => [
    [
        'type' => 'ohdear_health_check',
        'width' => 100,
        'can' => 'view ohdear health check widget',
    ],
],
```

The widget needs its stylesheet published once:

```bash
php artisan vendor:publish --tag=statamic-ohdear-health-check --force
```

It shows the four application-health cards (disk space, error log, storage size, forgotten
files) from the local checks. Add `OHDEAR_API_TOKEN` and `OHDEAR_MONITOR_ID` — or fill them in
under **Tools → OhDear Health Check** — and the monitor strip above them is populated from the
Oh Dear API instead of the local checks: uptime, performance, broken links, mixed content,
certificate health, DNS, domain expiry, scheduled tasks and anything else enabled on the
monitor. Results are cached for the widget's `cache` seconds (default 60), and the widget falls
back to local checks if the API is unreachable.

### Permissions

| Permission | Grants |
| --- | --- |
| `configure ohdear health check` | Access to **Tools → OhDear Health Check** |
| `view ohdear health check widget` | The dashboard widget |

The two are independent: grant the widget to editors without letting them change thresholds, or
give an admin the settings screen without putting the widget on their dashboard. Super users
have both. Neither is granted by default, so add them to the roles that need them.

### Forgotten Files check

The check scans `public/` and warns about any entry that is not in the built-in allowlist:

```
.htaccess, index.php, robots.txt, assets, build, favicons,
fonts, icons, img, static, vendor, visuals, hot
```

Add project-specific entries via `allowed_files` in the config (supports `fnmatch` glob patterns).

### Statamic Version check

Fetches the latest Statamic release tag from GitHub (`api.github.com`) and compares it to the installed version. The result is cached for **6 hours**.

---


## Authors

- [devskio.io](https://devsk.io)

---

## License

The MIT License. See [LICENSE](LICENSE) for details.
