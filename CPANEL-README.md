# Fresh cPanel deployment (no business data)

This package contains the application source and empty database schema. It does not contain `config.php`, database credentials, a database dump, or business/test records. The installer creates the tables and the first administrator only.

## First install

1. In cPanel, create a **new, empty** MySQL database and database user. Grant the user all privileges on that database.
2. Upload this ZIP to the target folder (for example `public_html/depo/`) and extract it there.
3. Copy `config.example.php` to `config.php`. Set the cPanel database host, prefixed database name, prefixed username, and password.
4. Open `/depo/install.php` and create the initial administrator account.
5. Delete or rename `install.php` immediately after installation.
6. Open `/depo/`, sign in, and configure the company and master data from scratch.

Use a new empty database for a fresh company start. The installer is intentionally non-destructive: it does not remove rows already in a database. Do not import the old database dump if you want a clean start.

## Future code updates

Keep the cPanel database and `config.php`. Upload the newer application files and replace the old application code, but do not replace `config.php` or import schema as a data reset. Back up the database and files before each update. Follow any update-specific migration notes included with a future package.

## Requirements

- PHP 8.1+ with `pdo_mysql`
- MySQL 5.7+ or MariaDB 10.4+
- Apache with `.htaccess` support
- HTTPS enabled for normal use
