# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
- Bumped minimum PHP requirement from `^8.1` to `^8.2` (required by Laravel 13).
- Updated `statamic/cms` constraint to `^6.0` (Statamic 6 only).
- Extended `devskio/laravel-ohdear-health-check` constraint to `^1.0 || ^2.0` to allow a future Laravel 13-compatible release of that package.

## [1.0.0] - 2024-07-10

### Added
- Statamic control-panel configuration screen for all health-check settings.
- **StatamicVersion** check – compares the installed Statamic version against the latest GitHub release (cached for 6 hours).
- **StorageFolderSize** check – reports storage folder size, excluding `framework/` and `debugbar/` directories.
- **ForgottenFiles** check – scans `public/` for files not on the built-in or user-defined allowlist.
- Automatic registration of the Statamic-specific checks as `additional_checks` in the shared Laravel package config.
- Config synchronisation between `statamic-ohdear-health-check` and `ohdear-health-check` on application boot.
- `vendor:publish` support for config (`statamic-ohdear-health-check-config`) and blueprints (`statamic-ohdear-health-check-blueprints`) tags.
- Supports Statamic 6.

