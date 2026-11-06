# Due Flow

Due Flow is a mobile-friendly PHP/MySQL customer due manager for basic cPanel hosting. It needs PHP 8.2+, MySQL/MariaDB, PDO MySQL, and HTTPS. It has no Node.js or build step.

## What the app includes

- Customer cards with due balances, phone calling, editing, and notes.
- A **Paid** button that asks for confirmation, records the payment, and moves the customer to **Paid**.
- Dhaka date/time display using 12-hour AM/PM format.
- Weekly reports for money given, paid money, payment count, and outstanding due.
- Performance ranking and optional Google Sheets synchronization.
- CSRF protection, password hashing, secure sessions, and persistent device login.

## Beginner cPanel installation

### 1. Check hosting requirements

In cPanel, confirm that your hosting provides:

- PHP 8.2 or newer.
- MySQL or MariaDB.
- PHP extensions: `PDO`, `pdo_mysql`, `openssl`, `curl`, `json`, and `mbstring`.
- **Git Version Control**, **MySQL Databases**, **phpMyAdmin**, **SSL/TLS Status**, and **Cron Jobs**.
- HTTPS for your domain.

If PHP version or extensions are unavailable, ask your hosting provider to enable them.

### 2. Create the database

1. Open **MySQL Databases** in cPanel.
2. Under **Create New Database**, create a database, for example `dueflow`.
3. Create a database user, for example `dueflow_user`, with a strong password.
4. Under **Add User To Database**, select the database and user.
5. Grant **ALL PRIVILEGES**, then save.

Write down the exact names shown by cPanel. cPanel commonly adds your account prefix, so the real values may look like:

```text
cpuser_dueflow
cpuser_dueflow_user
```

### 3. Add the repository to cPanel Git Version Control

1. Open **Git Version Control**.
2. Click **Create**.
3. For **Clone URL**, use:

```text
https://github.com/HamzaCanDo/due-flow.git
```

4. Choose a repository path outside `public_html`, for example:

```text
/home/CPANEL_USERNAME/repos/due-flow
```

5. Choose the `main` branch and create the repository.
6. Open **Manage**, then click **Deploy HEAD Commit** after editing `.cpanel.yml`.

### 4. Set the deployment path

Before deploying, edit `.cpanel.yml` in the repository and replace:

```text
/home/CPANEL_USERNAME/public_html/
```

with your real cPanel home path.

For a separate subfolder, use a path such as:

```text
/home/CPANEL_USERNAME/public_html/due-flow/
```

Then also set this in `config.php`:

```php
'base_path' => '/due-flow',
```

Deploy again from **Git Version Control**. The manifest copies only the application files and intentionally does not copy `config.php`.

### 5. Create `config.php`

In cPanel **File Manager**, open the deployed application directory. Copy `config.example.php` to `config.php`, then edit `config.php`:

```php
<?php
declare(strict_types=1);

return [
    'db' => [
        'dsn' => 'mysql:host=localhost;dbname=cpuser_dueflow;charset=utf8mb4',
        'user' => 'cpuser_dueflow_user',
        'password' => 'YOUR_DATABASE_PASSWORD',
    ],
    'app' => [
        'name' => 'Due Flow',
        'timezone' => 'Asia/Dhaka',
        'base_path' => '',
        'secure_cookies' => true,
    ],
    'sheets' => [
        'webhook_url' => '',
        'webhook_secret' => '',
    ],
];
```

Replace the database name, username, and password with the values from **MySQL Databases**. Keep `secure_cookies` set to `true` when the site uses HTTPS.

`config.php` is ignored by Git and is not included in deployments, so future Git deployments will not overwrite your database credentials.

### 6. Create the tables and first admin account

1. Visit your site at `https://your-domain.example/install.php`.
2. Enter the admin email and a password of at least 12 characters.
3. Submit the form.
4. Confirm that setup completes.
5. Delete `install.php` in cPanel **File Manager** immediately.
6. Open the main site and sign in.

The installer is safe to rerun if the tables already exist, but deleting it after setup is still required.

### 7. Configure PHP and HTTPS

In **MultiPHP Manager**, select PHP 8.2 or newer for the domain. In **MultiPHP INI Editor**, ensure the required extensions are enabled.

In **SSL/TLS Status**, ensure the domain has a valid SSL certificate. Use the HTTPS URL, not HTTP. Persistent login and secure cookies depend on HTTPS.

### 8. Add the retry cron job

The Google Sheets sync is optional. If you configure it, add a cPanel **Cron Job**:

```text
*/5 * * * *
```

Use the command below, replacing the username and application path:

```bash
/usr/local/bin/php /home/CPANEL_USERNAME/public_html/cron.php
```

If your host uses a different PHP CLI path, check with cPanel Terminal:

```bash
which php
php -v
```

### 9. Test the installation

Test these flows before entering real data:

1. Sign in and refresh the page.
2. Close and reopen the browser to verify persistent device login.
3. Add a customer with a due amount.
4. Press **Paid**, cancel the confirmation, and verify nothing changes.
5. Press **Paid** again and confirm; verify the customer appears under **Paid**.
6. Verify the paid amount and payment count in **Reports**.
7. Verify the paid date uses Dhaka time and 12-hour AM/PM format.
8. Test the weekly navigation and ranking screens.

## Optional Google Sheets synchronization

Create a Google Apps Script web app with sheets named `Customers`, `Transactions`, `Weekly Reports`, and `Customer Performance`. Copy `google-apps-script/Code.gs` into Apps Script, set the `DUE_FLOW_SECRET` script property, deploy it as a web app, and place the URL and the same secret in `config.php`.

Never put the Google webhook secret in `assets/app.js`.

## Security notes

- Do not commit or upload database passwords to GitHub.
- Keep `config.php` private and do not paste it into public issue reports.
- Delete `install.php` after creating the first admin account.
- Use HTTPS at all times.
- To sign out a device, use **Sign out** and clear that browser's cookies if necessary.
