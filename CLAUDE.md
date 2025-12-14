# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

! Important: This Addon is currently unreleased, so never worry about breaking changes and backwards compatibility.

## Project Overview

**Statamic Magic Actions** is a Statamic CMS addon that integrates AI-powered actions into the Statamic control panel. It allows content editors to use magic actions (powered by OpenAI or Anthropic via Prism PHP) directly on various field types to automatically generate or transform content.

The addon supports three types of AI operations:

- **Completion**: Text-to-text processing (e.g., generate titles, extract meta descriptions)
- **Vision**: Image analysis (e.g., generate alt text, extract asset tags)
- **Transcription**: Audio-to-text conversion (e.g., transcribe audio files)

## Architecture

### Core Components

**Frontend (Vue/TypeScript)**

- `resources/js/addon.ts`: Main addon entry point, registers field actions
- Vue components are mounted in Statamic's control panel UI

**Backend (PHP/Laravel)**

- `src/ServiceProvider.php`: Addon bootstrap, registers services and configuration
- `src/Http/Controllers/ActionsController.php`: Handles API endpoints for starting and monitoring jobs
- **Configuration**

- `config/statamic/magic-actions.php`: Provider credentials, action definitions, fieldtype mappings
- `resources/actions/{action-name}/`: All required files for an action

### Data Flow

1. **Frontend Request**: Editor clicks a magic action button in the CP
2. **API Call**: `ActionsController` validates request, checks prompt exists
3. **Job Queuing**: Job is dispatched asynchronously, UUID stored in cache
4. **Job Processing**: Prism PHP provider (OpenAI/Anthropic) executes the request using the action configuration
5. **Status Polling**: Frontend polls `/actions/status/{jobId}` to check completion
6. **Result Return**: When done, result is cached and returned to frontend

## Development Commands

### Code Quality

Use prettier and pint to check and fix code quality.

```bash
prettier --check .
prettier --write .
pint check
pint fix
```

### Testing

#### Unit & Feature Tests

Use pest to run automatic tests. If unsure always use context7 or web search to find the latest docs. Pest 4 is quite new.

```bash
./vendor/bin/pest       # Run all tests
./vendor/bin/pest --filter=SomeTest  # Run specific test
```

#### Integration Testing with Live App

A full Laravel test app is available at `../statamic-magic-actions-test` and can be accessed at `http://statamic-magic-actions-test.test`.

**Credentials:**

- Email: `claude@claude.ai`
- Password: `claude`
- Login URL: `http://statamic-magic-actions-test.test/cp`

You can test the addon in the Statamic control panel using these credentials. For programmatic testing:

**Playwright approach** (recommended for complex interactions):

- Use Playwright to obtain a session and test UI workflows through the control panel

**curl approach** (faster for API-only testing):

- Obtain a session cookie via login, then use curl to test API endpoints
- Example: `curl -b "cookies.txt" http://statamic-magic-actions-test.test/api/magic-actions/...`

See the logs at `../statamic-magic-actions-test/storage/logs/laravel.log` when debugging errors.
