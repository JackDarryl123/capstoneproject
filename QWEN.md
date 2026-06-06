# PEPO - Provincial Equipment Pool Office

**Equipment Pool Operations Management System**

A comprehensive PHP/MySQL web application for managing provincial equipment pool operations with role-based access control, document management, QR code generation, and activity logging.

---

## Project Overview

PEPO is a government equipment management system serving Occidental Mindoro municipalities. The system enables tracking, maintenance scheduling, and document management for provincial equipment across multiple locations (Mamburao, Sablayan, San Jose, Lubang).

### Core Features

- **Multi-role Authentication**: Admin, Staff, Supply, User, PACCO Admin, GSO Admin
- **Equipment Management**: Inventory tracking, checkout/check-in, maintenance scheduling
- **Document Management**: Upload, view, and track official documents
- **QR Code Generation**: Equipment identification and quick scanning
- **Activity Logging**: Comprehensive audit trail for all operations
- **Appointment System**: Schedule equipment maintenance and inspections
- **Supply Request System**: Departmental supply chain management

---

## Technology Stack

| Layer | Technology |
|-------|------------|
| Backend | PHP 8.2+ (mysqli) |
| Database | MySQL 8.0 / MariaDB 10.4 |
| Frontend | HTML5, Tailwind CSS, Bootstrap 5, Vanilla JavaScript |
| Server | XAMPP (Apache + MySQL) |
| Dependencies | PHPMailer 7.0, phpqrcode library |

---

## Project Structure

```
C:\xampp\htdocs\PEPO\
├── api/                    # REST API endpoints (stats, health, notifications)
├── auth/                   # Authentication handlers
├── GSO/                    # General Services Office module
├── includes/               # Core helpers (session, mail)
├── PACCO/                  # Provincial Accounting Office module
├── phpqrcode/              # QR code generation library
├── public/                 # Static assets (CSS, JS)
├── staff/                  # Staff dashboard & operations
├── supply/                 # Supply department module
├── temp_qr/                # Temporary QR code storage
├── uploads/                # User uploaded files
├── users/                  # User dashboard & requests
├── vendor/                 # Composer dependencies
├── css/                    # Stylesheets
│   └── input.css           # Tailwind source
├── index.php               # Main entry point (login)
├── admin_dashboard.php     # Admin dashboard
├── config.php              # Database configuration
├── db_connect.php          # Database connection helper
├── process.php             # Form processing handler
├── user_management.sql     # Database schema & seed data
├── package.json            # NPM scripts (Tailwind, Prettier)
└── tailwind.config.js      # Tailwind configuration
```

---

## Building and Running

### Prerequisites

1. **XAMPP installed** with PHP 8.2+ and MySQL/MariaDB
2. **Node.js** (for Tailwind CSS and Prettier)
3. **Composer** (for PHP dependencies)

### Setup Instructions

1. **Clone/Copy project** to `C:\xampp\htdocs\PEPO`

2. **Install PHP dependencies**:
   ```bash
   composer install
   ```

3. **Install NPM dependencies**:
   ```bash
   npm install
   ```

4. **Import database**:
   ```bash
   "C:/xampp/mysql/bin/mysql.exe" -u root user_management < user_management.sql
   ```

5. **Build CSS**:
   ```bash
   npm run build:css
   ```

6. **Start XAMPP** (Apache + MySQL)

7. **Access application**: `http://localhost/PEPO`

### Development Commands

| Command | Description |
|---------|-------------|
| `npm run build:css` | Build minified Tailwind CSS |
| `npm run watch:css` | Watch mode for CSS development |
| `npm run check-format` | Check PHP formatting with Prettier |
| `npm run fix-format` | Auto-fix PHP formatting |
| `php -l filename.php` | Lint single PHP file |
| `php -l includes/*.php` | Lint multiple files |

### Windows PowerShell - Lint All PHP Files

```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
```

---

## Development Conventions

### Required File Header

**ALL PHP files must start with this exact header:**

```php
<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/includes/session_helper.php';
start_user_session();
```

### Import Order (REQUIRED)

```
session_helper.php → config.php → Helpers → Classes
```

### PHP Type Hints

All functions must use strict type hints. Use `?Type` for nullable returns:

```php
function get_user_by_id(int $userId): ?array {
    global $mysqli;
    $stmt = $mysqli->prepare("SELECT id, username, email, role FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    return $user ?: null;
}
```

### Naming Conventions

| Type | Convention | Example |
|------|------------|---------|
| Variables | camelCase | `$userId`, `$isActive` |
| Functions | snake_case | `get_user_by_id()` |
| Classes | PascalCase | `Database`, `UserService` |
| Methods | camelCase | `getUserInfo()` |
| Properties | camelCase | `$userName` |
| Tables | snake_case | `users`, `equipment_items` |
| Files | kebab-case | `user-dashboard.php` |
| JavaScript | camelCase | `handleSubmit()` |
| Constants | UPPER_SNAKE | `MAX_LOGIN_ATTEMPTS` |

### Formatting Rules

- **Indentation**: 2 spaces
- **Line length**: Max 100 characters
- **Braces**: Same line for functions/classes, new line for control structures
- **Quotes**: Single quotes (unless interpolating)
- **Arrays**: Trailing commas in multi-line arrays
- **Comparison**: Strict (`===` / `!==`) always
- **Braces**: Always use even for single-line statements

### Prettier Configuration

```json
{
  "tabWidth": 2,
  "singleQuote": true,
  "semi": true,
  "printWidth": 100
}
```

---

## Database Operations

### Prepared Statements (REQUIRED)

**NEVER concatenate strings in SQL queries:**

```php
// ✅ CORRECT
$stmt = $mysqli->prepare("SELECT id, username FROM users WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

// ❌ WRONG - Never do this
$user = $mysqli->query("SELECT * FROM users WHERE id = $id");
```

### Type Codes for `bind_param`

| Code | Type |
|------|------|
| `i` | Integer |
| `s` | String |
| `d` | Double/Float |
| `b` | Blob |

---

## Security Requirements

### Session & Authentication

```php
// Role-based access control
require_role('admin');

// Manual role check
if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: /login.php');
    exit();
}

// CSRF protection
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf'] ?? '')) {
    die('CSRF validation failed');
}

// Regenerate session ID after privilege changes
session_regenerate_id(true);
```

### Input Sanitization

```php
// Output escaping
$username = htmlspecialchars(trim($_POST['username']), ENT_QUOTES, 'UTF-8');

// Never trust user input - always validate and sanitize
```

### Password Handling

```php
// Hashing (registration)
$hashed = password_hash($password, PASSWORD_DEFAULT);

// Verification (login)
if (password_verify($password, $hashed)) {
    // Valid
}
```

---

## Page Template Pattern

```php
<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/includes/session_helper.php';
start_user_session();
require_role('admin');

$pageTitle = 'Page Title';
include __DIR__ . '/includes/header.php';
?>

<!-- Page content -->

<?php include __DIR__ . '/includes/footer.php'; ?>
```

---

## JavaScript Conventions

- Use `const`/`let`, **never `var`**
- Arrow functions preferred
- Template literals for string interpolation
- Strict equality (`===` / `!==`) always
- Event delegation over inline handlers
- Use Fetch API with try-catch
- Store inline scripts in `public/js/` directory
- Use `defer` attribute for `<script>` tags in `<head>`

### Example

```javascript
document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('#myForm');
    
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        
        try {
            const response = await fetch('/api/endpoint.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ key: value })
            });
            
            const data = await response.json();
            // Handle response
        } catch (error) {
            console.error('Error:', error);
        }
    });
});
```

---

## Frontend Assets

| Asset | Location | Purpose |
|-------|----------|---------|
| Tailwind Source | `css/input.css` | Raw Tailwind directives |
| Tailwind Output | `css/styles.css` | Compiled, minified CSS |
| JavaScript | `public/js/` | Inline scripts |
| QR Codes | `temp_qr/` | Generated QR images |
| Uploads | `uploads/` | User files |

**Always rebuild CSS after changes:**
```bash
npm run build:css
```

---

## Error Handling

### Best Practices

1. **Never expose errors to users in production**
2. **Log errors with full details** (timestamp, user, action, file, line)
3. **Redirect with friendly message** on error
4. **Use try-catch** for operations that may fail
5. **Display user-friendly messages** while logging detailed errors server-side

### Example

```php
try {
    $stmt = $mysqli->prepare("INSERT INTO users (...) VALUES (...)");
    $stmt->execute();
} catch (Exception $e) {
    error_log(sprintf(
        "[%s] %s in %s:%d (User: %s)",
        date('Y-m-d H:i:s'),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $_SESSION['user_id'] ?? 'anonymous'
    ));
    
    $_SESSION['message'] = "An error occurred. Please try again.";
    $_SESSION['msg_type'] = 'danger';
    header('Location: /previous-page.php');
    exit();
}
```

---

## Role-Based Access

### Available Roles

| Role | Dashboard Path | Permissions |
|------|---------------|-------------|
| `admin` | `/admin_dashboard.php` | Full system access |
| `staff` | `/staff/staff_dashboard.php` | Maintenance, activities, scanning |
| `supply` | `/supply/supply_dashboard.php` | Equipment, documents, inventory |
| `user` | `/users/user_dashboard.php` | Requests, appointments, documents |
| `pgdh_pacco` | `/PACCO/admin_dashboard.php` | PACCO-specific admin |
| `pgdh_gso` | `/GSO/admin_dashboard.php` | GSO-specific admin |

---

## Common Pitfalls to Avoid

| Issue | Solution |
|-------|----------|
| Exposing errors to users | Log errors, show friendly messages |
| SQL string concatenation | Always use prepared statements |
| Forgetting `$stmt->close()` | Close statements after use |
| Using short tags `<?` | Always use `<?php` |
| Forgetting `declare(strict_types=1)` | Include in all files |
| Forgetting `exit()` after `header()` | Always call `exit()` after redirects |
| Skipping `htmlspecialchars()` | Escape all output |
| Circular require dependencies | Plan include order carefully |
| Not rebuilding CSS | Run `npm run build:css` after CSS changes |

---

## Testing

### Manual Testing Checklist

Since there's no automated test framework, perform manual testing:

1. **User Flows**
   - Login/logout
   - Equipment checkout/check-in
   - Document upload and viewing
   - Report generation

2. **RBAC Verification**
   - Each role has appropriate permissions
   - Unauthorized access is blocked

3. **Form Validation**
   - All inputs validated and sanitized
   - Error messages are user-friendly

4. **Responsive Design**
   - Test on mobile, tablet, desktop views

5. **Error Handling**
   - Verify errors don't expose sensitive information

---

## Debugging

### Development Mode

Enable error logging in `php.ini` or `.htaccess`:

```ini
display_errors = Off
log_errors = On
error_log = C:/xampp/apache/logs/error.log
```

### Debugging Tips

1. Use `var_dump()` / `print_r()` only in development
2. Remove debugging statements before committing
3. Use browser DevTools for frontend debugging
4. Check Network tab for API request/response issues
5. Review server error logs for PHP errors

---

## Database Schema

Main tables include:

- `users` - User accounts with roles
- `activities` - Maintenance/inspection records
- `activity_log` - Audit trail
- `inventory` - Equipment inventory
- `documents` - Document management
- `equipment_items` - Equipment details
- `notifications` - User notifications

See `user_management.sql` for complete schema.

---

## Additional Resources

- **AGENTS.md**: Detailed development guide with code examples
- **user_management.sql**: Complete database schema
- **includes/session_helper.php**: Session management utilities
- **config.php**: Database connection settings
