# Due Flow

Due Flow is a lightweight, mobile-first customer due manager for ordinary cPanel hosting. It uses PHP 8.2+, PDO, MySQL/MariaDB, vanilla JavaScript and no runtime Node.js process.

## What is implemented

- Private email/password login with password hashing, secure session cookies and CSRF checks.
- Customer creation/editing with name, phone, due and notes.
- Asia/Dhaka timestamps for customer and transaction records.
- `tel:` calling, archived paid customers, and an iPhone-friendly layout.
- Due reductions ask for explicit confirmation before creating a `received` transaction. Increases and corrections do not create payments.
- Monday-Sunday weekly reports based on transaction timestamps.
- Competition ranking with shared ranks. The score is transparent: repayment ratio (60%), recent payment activity in the last 90 days (20%), and at least two recorded payments (20%), with a small outstanding-balance penalty. Customers without two payments are shown as **Not enough history**.
- Durable `sync_queue` records for customers and transactions. `cron.php` retries failed server-side webhook deliveries without exposing Google credentials to the browser.

The phone PLUS button uses the browser Contact Picker API only when `navigator.contacts.select` exists. iPhone Safari does not provide a dependable native web contact picker, so unsupported browsers show a clear manual-entry fallback; the app never pretends to access the address book.

## cPanel installation

1. In cPanel, confirm PHP 8.2+ and enable `PDO`, `pdo_mysql`, `curl`, `openssl`, `json` and `mbstring` in **MultiPHP INI Editor/PHP Selector**. Confirm MySQL/MariaDB, PHP CLI/Terminal and Cron Jobs are available. Confirm the account has HTTPS and outbound HTTPS access.
2. Create a database and database user using **MySQL Database Wizard**. Import `schema.sql` with phpMyAdmin, or let `install.php` create the tables.
3. Copy `config.example.php` to `config.php`, fill in the database credentials, set `secure_cookies` to `true`, and keep `config.php` outside `public_html` where the hosting layout permits. If it must be public, deny direct access with cPanel/Apache rules.
4. Upload the files to the desired HTTPS directory. Open `/install.php`, create an admin account, then delete `install.php`.
5. Add a cPanel cron job (for example every 5 minutes): `php /home/CPANEL_USER/path/to/cron.php`.
6. Test login, customer creation, a due reduction with both confirmation choices, archive, reports, ranking ties, and a failed/retried sync before using real records.

If PHP CLI is unavailable, use the cPanel Terminal to verify `php -v`; the web application itself only needs PHP-FPM/Apache. The application cannot inspect a hosted cPanel account from this repository, so these checks must be performed in the target account.

## Google Sheets sync

Create a Google Apps Script web app with four sheets named `Customers`, `Transactions`, `Weekly Reports`, and `Customer Performance`. Store the generated web-app URL and a long random shared secret in `config.php`. The webhook verifies an HMAC signature over the raw request body, then upserts by stable IDs rather than appending blindly. The queue payloads are intentionally idempotent and MySQL remains authoritative.

An installable handler is included in `google-apps-script/Code.gs`. Copy it into Apps Script, set the `DUE_FLOW_SECRET` script property, and deploy it as a web app. It verifies the HMAC and upserts stable IDs into `Customers` and `Transactions`; create `Weekly Reports` and `Customer Performance` sheets for later report exports.

The handler pattern is:

```js
function doPost(e) { /* see google-apps-script/Code.gs */ }
```

Never put the secret or Google credentials in `assets/app.js`.

## Financial model

`customers.outstanding_due` is the current balance. A new customer creates one immutable `given` transaction. An edit that lowers the balance offers a confirmation; only YES creates one immutable `received` transaction for the exact reduction. The original creation time and all transaction times remain unchanged when customer details are edited.
