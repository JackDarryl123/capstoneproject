# PEPO (Provincial Equipment Pool Office)

## Project Overview
PEPO is a comprehensive management system designed for equipment pool operations. It facilitates user authentication, role-based access control (RBAC), equipment and inventory tracking, maintenance scheduling, document management, and activity logging. The system is designed to streamline administrative tasks for various departments, including Maintenance, Supply, and Property Custodians.

### Core Technologies
- **Backend:** PHP 8.2+ (native `mysqli` extension)
- **Database:** MySQL/MariaDB (default database: `user_management`)
- **Frontend:** HTML5, Tailwind CSS (via CDN and CLI), Vanilla JavaScript, Poppins font
- **Tools:** npm (for Tailwind CSS compilation and Prettier formatting), Composer (for PHP dependencies)
- **Dependencies:** 
  - [PHPMailer](https://github.com/PHPMailer/PHPMailer): For email notifications.
  - [phpqrcode](http://phpqrcode.sourceforge.net/): For QR code generation of equipment and documents.

## Directory Structure
- `/api/`: Backend API endpoints (JSON responses) for asynchronous operations.
- `/includes/`: Core utility files, including session management (`session_helper.php`) and mailing (`mail_helper.php`, `mail_config.php`).
- `/users/`: Dashboards and views for Property Custodian Officers.
- `/staff/`: Dashboards and views for Maintenance Staff.
- `/supply/`: Dashboards and views for Supply Department Admins.
- `/css/`: Tailwind CSS input (`input.css`) and compiled output (`styles.css`).
- `/rs/`: Static resources such as images, logos, and icons.
- `/uploads/`: Storage for uploaded documents and images.
- `/phpqrcode/`: Third-party library for QR code generation.
- `/vendor/`: Composer dependencies.
- `/node_modules/`: npm dependencies.

## Building and Running
### Prerequisites
- **Local Server:** XAMPP, WAMP, or any Apache/PHP/MySQL stack.
- **Node.js:** For Tailwind CSS and Prettier tools.
- **Database:** Import the provided `user_management.sql` (if available) or ensure the `user_management` database is created with appropriate tables.
- **Connection Variables:** Note that `config.php` uses `$conn` whereas `db_connect.php` uses `$mysqli`. Be mindful of which file is included in each script to avoid undefined variable errors.

### Setup Instructions
1.  Clone the repository into your web root (e.g., `C:\xampp\htdocs\PEPO`).
2.  Start Apache and MySQL via the XAMPP Control Panel.
3.  Configure database credentials in `config.php` and `db_connect.php`.
4.  Install dependencies:
    ```bash
    npm install
    composer install
    ```

### Key Commands
- **Build CSS:** `npm run build:css` (compiles Tailwind CSS)
- **Watch CSS:** `npm run watch:css` (watches for changes in Tailwind classes)
- **Format Code:** `npm run fix-format` (runs Prettier on all PHP files)
- **Check Formatting:** `npm run check-format` (verifies code formatting)

## Development Conventions
### File Header Requirements
All core PHP files should include the following at the beginning to ensure consistent session and timezone handling:
```php
<?php
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/includes/session_helper.php';
start_user_session();
```

### Naming Conventions
- **Variables:** camelCase (e.g., `$userId`)
- **Functions:** snake_case (e.g., `get_user_info()`)
- **Classes:** PascalCase (e.g., `DatabaseConnection`)
- **Database Tables:** snake_case (e.g., `activity_logs`)
- **Session Keys:** snake_case (e.g., `$_SESSION['user_id']`)

### Security Standards
- **SQL Injection:** **ALWAYS** use prepared statements with `mysqli`. Never concatenate user input into queries.
- **XSS Protection:** Use `htmlspecialchars()` when outputting user-generated content to HTML.
- **CSRF Protection:** Implement CSRF tokens for sensitive POST requests.
- **Role Validation:** Use `require_role($role)` (from `session_helper.php`) to restrict access to pages based on user roles (`admin`, `staff`, `supply`, `user`).

### Error Handling
- Use `error_log()` to record system errors.
- **Never** display raw database errors (`$mysqli->error`) to the end user.
- Always check if statements are successfully prepared before execution.

### Coding Style
- **Indentation:** 2 spaces (enforced by `.prettierrc`).
- **Braces:** Same line for functions/classes; new line for control structures is optional but consistency is preferred.
- **Line Length:** Maintain a maximum of 100 characters per line where possible.
