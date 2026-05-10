# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Running the Application

Serve with PHP's built-in server (no framework or build step required):

```bash
php -S localhost:8000
```

There are no dependencies to install — flatpickr is loaded from CDN. There is no test suite and no linter configured.

## Architecture

This is a single-file PHP 8.1+ application (`index.php`) that functions as both the controller and the view. It has no framework, no database, and no router.

**Data layer:** Tasks are persisted to `tasks.json` in the same directory via `loadTasks()` / `saveTasks()`. The file is a flat JSON array of task objects. Each task has `id`, `title`, `due_date`, `follow_up_date`, `notes`, `priority`, `status`, `created_at`, and optionally `completed_at`.

**Request handling:** All mutations are POST-only and handled in a single `if ($_SERVER['REQUEST_METHOD'] === 'POST')` block near the top of the file before any HTML is emitted. Supported actions (`$_POST['action']`): `add`, `update_status`, `delete`.

**Enums:** `Priority` (high/medium/low) and `Status` (pending/in_progress/completed/cancelled) are PHP 8.1 backed string enums. They carry display logic (`color()`, `label()`) and `Status` has an `isTerminal()` helper used to conditionally render action buttons.

**View layer:** The bottom half of `index.php` is inline HTML/PHP. Filtering is driven by `?filter=` query param; valid values are `all`, `pending`, `in_progress`, `overdue`, `follow_up`, `completed`. Sorting puts overdue tasks first, then by `due_date` ascending.

## Key Conventions

**XSS prevention:** All user-supplied data is stored raw and escaped on output using `e()` (wraps `htmlspecialchars`). Never echo user data without `e()`.

**CSRF:** Every POST form must include `<input type="hidden" name="csrf" value="<?= e($csrf) ?>">`. The token is validated with `hash_equals` before any action is processed.

**Date format:** Dates are stored as `Y-m-d` strings and datetimes as `Y-m-d H:i:s`. Use `fmtDate()` / `fmtDateTime()` for display.

**IDs:** Task IDs are generated with `bin2hex(random_bytes(8))` (16-char hex strings).
