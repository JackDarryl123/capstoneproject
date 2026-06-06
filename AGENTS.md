# PEPO Development Guide

PHP/MySQL web app for equipment pool operations. XAMMP + PHP 8.2+ + MariaDB.

## Quick Commands

```bash
npm run build:css                 # Build Tailwind (css/input.css -> css/styles.css)
npm run watch:css                 # Watch mode for Tailwind
npm run check-format              # Prettier check on all PHP files
npm run fix-format                # Auto-fix PHP formatting
vendor/bin/phpunit                # PHPUnit (Unit + Integration suites)
npx jest                          # JS tests in tests/*.test.js
php -l path/to/file.php           # PHP lint single file
```

## Required File Header

```php
<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/includes/session_helper.php';
start_user_session();
```

> Note: `declare(strict_types=1)` is aspirational — many existing files omit it. Add it to new files.

## Roles & Routing

- **Roles**: `admin`, `staff`, `supply`, `user`, `pgdh_pacco`, `pgdh_gso`
- `index.php` — entry point, redirects by role via switch on `$_SESSION['role']`
- `require_role('admin')` — guards pages, accepts string or array of roles
- Role → dashboard mapping:
  - `pgdh_pacco` → `PACCO/admin_dashboard.php`
  - `pgdh_gso` → `GSO/admin_dashboard.php`
  - `admin` → `admin_dashboard.php`
  - `staff` → `staff/staff_dashboard.php`
  - `supply` → `supply/supply_dashboard.php`
  - `user` → `users/user_dashboard.php`

## Database

- **DB**: `user_management` | **User**: root | **Pass**: (empty)
- Connection variable is **`$mysqli`** (from `config.php`). Some older files use `$conn` — beware of mismatch.
- `db_connect.php` is a thin wrapper: just `require_once __DIR__ . '/config.php';`
- Use prepared statements always, close `$stmt`:
```php
$stmt = $mysqli->prepare("SELECT id FROM users WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();
```

## Security

```php
require_role('admin');
$input = htmlspecialchars(trim($_POST['input']), ENT_QUOTES, 'UTF-8');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf'] ?? '')) { die('CSRF failed'); }
```

## Formatting

- 2 spaces indent, 100 max chars/line
- `.prettierrc`: single quotes, semicolons
- Tailwind: input `css/input.css` → output `css/styles.css` (minified)

## Key Files

- `includes/session_helper.php` — `start_user_session()`, `require_role()`, `check_login()`
- `config.php` — `$mysqli` DB connection (hardcoded root/empty pass)
- `process.php` — login, registration, and form action handler (452 lines)
- `admin_dashboard.php` — monolithic 2359-line admin panel with inline AJAX handlers (AJAX short-circuit at top checks `$_POST['ajax']`, `X-Requested-With`, or `$_GET['fetch_item_logs']`)

## Testing

- **PHPUnit** (`phpunit ^11`): suites `Unit` (`tests/Unit/`) and `Integration` (`tests/Integration/`), bootstrap at `tests/bootstrap.php`
- **Jest** (`^30.3.0`): JS tests in `tests/*.test.js` — run via `npx jest`
- `npm test` is a stub — use either `vendor/bin/phpunit` or `npx jest` explicitly

## Known Quirks

- `admin_dashboard.php:7-9` uses `ob_start("ob_gzhandler")` — compression buffering
- Duplicate/backup files (`* copy.php`, `*(1).php`) exist in source — ignore or clean up
- Mail config (`includes/mail_config.php`) contains hardcoded SMTP credentials — do not commit changes to it
- CSRF token generation is not centralized in `session_helper.php` — verify per-form implementation
- `GEMINI.md` and `QWEN.md` are verbose AI-generated instruction files — this `AGENTS.md` is the canonical source
