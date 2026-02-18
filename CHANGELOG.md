# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## v0.2.0 - 2026-02-18

### Added
- Multi-provider support — configure default AI provider and model in settings (#11)
- Helpful hint when provider API keys are missing in settings
- Pest browser tests for field actions and settings page (#8)
- MIT license

### Fixed
- Taxonomy terms and relationship fields now display titles instead of raw slugs after magic action updates (#18)
- CP route resolution via `Statamic::cpRoute()` instead of hardcoded paths
- Auth middleware on action routes (security)

### Changed
- AI endpoints migrated to Statamic CP routes with proper authentication
- README updated for multi-provider setup

## v0.1.0 - 2026-02-16

First release 🎉

### What's included

- **Magic Actions** — AI-powered bulk actions for Statamic CP entries
- **Magic Fields** — configurable field-level AI generation
- **CLI support** — `please magic:run` for running actions from the command line
- **Job tracking** — async action execution with progress tracking
- **Global action catalog** — manage and reuse action definitions across collections

### Requirements

- Statamic 5+
- PHP 8.2+
- An OpenAI API key
