# Merry Begg Clinic SmartQueue

SmartQueue is a Laravel queue-management prototype by Nexware Systems. Queue records, visits, ticket identities, staff permissions, and audit events are stored by Laravel and Eloquent. Public screens expose ticket and service-point information only.

## Current state

- Laravel 13.38, PHP 8.3+, Blade, Vite, and MySQL/MariaDB configuration.
- SQLite is supported for local demonstration and automated tests.
- Five initial departments are database-seeded: Reception, Triage, Doctor, Laboratory, and Pharmacy.
- Staff registration, ticket generation, department queues, priority ordering, calls, recalls, service start/completion, hold/resume, absence/return, cancellation, transfer, counter changes, and visit closure are implemented.
- Staff and administration operations require authentication and server-side role/department/service-point authorization.
- Public displays poll the server and do not include private patient identity.
- Analytics are calculated from stored visits and timestamps; targets are configurable and blank until set.

The clinic must approve mandatory registration fields, clinical priority policy, operational targets, announcements, and any production deployment controls. This prototype does not claim legal or regulatory compliance. The SQLite test suite does not prove parallel MySQL race behavior; MySQL row locks and unique active-stage/service-point keys provide the concurrency controls to verify in the target deployment.

## Requirements

- PHP 8.3 or newer with `ctype`, `curl`, `fileinfo`, `mbstring`, `openssl`, `pdo`, `pdo_mysql` for MySQL/MariaDB (or `pdo_sqlite` for SQLite), `tokenizer`, and `xml`.
- Composer 2.
- MySQL 8+ or MariaDB 10.6+ for clinic deployments, or SQLite for local demonstration.
- Node.js 20+ and npm for compiling assets.

For XAMPP or Laragon on Windows, use a PHP version compatible with Laravel 13 and enable `pdo_mysql` in the PHP configuration used by both the web server and CLI. Restart the relevant PHP/Apache service after changing extensions.

## Local setup

Run these commands from the project directory. The environment-file step intentionally creates `.env` only when it does not already exist.

```powershell
composer install
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
php artisan key:generate
npm install
npm run build
```

For SQLite, leave `DB_CONNECTION=sqlite` and `DB_DATABASE` blank in `.env`; Laravel uses `database/database.sqlite`. Then run:

```powershell
php artisan migrate --seed
php artisan serve
```

For MySQL/MariaDB, first create an empty database and a database user with access limited to that database. Configure `.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=merry_begg_smartqueue
DB_USERNAME=smartqueue_user
DB_PASSWORD=replace-with-a-local-secret
```

Then run `php artisan migrate --seed`. Migrations are incremental; do not run `migrate:fresh` against a database containing clinic records. Never replace an existing `.env` or copy real credentials into `.env.example`.

Open [http://127.0.0.1:8000](http://127.0.0.1:8000). Start the Vite development server with `npm run dev` only when actively changing frontend assets; the compiled production bundle is used otherwise.

## Demonstration accounts

The seed creates fictional staff accounts with the shared default password `SmartQueueDemo!2026`:

| Role | Email |
| --- | --- |
| System Administrator | `admin@smartqueue.test` |
| Clinic Manager | `manager@smartqueue.test` |
| Receptionist | `reception@smartqueue.test` |
| Triage Staff | `triage@smartqueue.test` |
| Doctor | `doctor@smartqueue.test` |
| Laboratory Staff | `laboratory@smartqueue.test` |
| Pharmacy Staff | `pharmacy@smartqueue.test` |

Set `DEMO_USER_PASSWORD` and optionally `DEMO_ADMIN_EMAIL` in the local `.env` before seeding to customize the demonstration accounts. Do not use these credentials for real staff. Demo seeding is blocked when `APP_ENV=production`.

## Five-minute demonstration

1. Sign in as the system administrator and review departments, service points, roles, staff assignments, targets, and ticket prefix under Configuration.
2. Sign in as Reception and register three fictional patients, leaving patient name blank for one to show quick entry.
3. Open `/display/reception` in another tab or screen.
4. Call next from Reception and observe the ticket appear without a refresh. Start service and complete the stage.
5. Transfer a waiting ticket to Triage. The same ticket number continues in a new queue stage.
6. Open `/departments/triage` and `/display/triage`, then call the transferred ticket.
7. Show the recorded call, transfer, wait, and service times in the dashboard and queue history.

## Public displays and LAN demonstration

Display routes are `/display/reception`, `/display/triage`, `/display/doctor`, `/display/laboratory`, and `/display/pharmacy`. They poll every 1.5 seconds, pause while hidden, show connection loss, and clear the current ticket if state becomes unavailable. Speech is optional, explicitly enabled by a user gesture, and scoped to one department display per browser profile.

For a local LAN demonstration, bind the app to the host's LAN interface:

```powershell
php artisan serve --host 0.0.0.0 --port 8000
```

Open `http://<host-lan-ip>:8000/display/reception` from the TV/browser. Allow inbound TCP 8000 only on the trusted demonstration network. Connected screens depend on the host, network, and power remaining available. This basic local server setup is for demonstrations, not production hosting.

## Safe demonstration reset

`php artisan migrate:fresh --seed` permanently drops every table in the configured database. Do not use it on an existing or clinic database.

The optional `smartqueue:demo-reset` command is intentionally restricted. To enable it, set `APP_ENV=local`, `QUEUE_DEMO_MODE=true`, and use the repository's `database/database.sqlite` file. The command refuses MySQL/MariaDB, other SQLite paths, and production/testing environments. It resets only that configured SQLite demonstration database and re-seeds fictional staff/configuration data; use it only when that file contains disposable demo records.

## Troubleshooting

- **`could not find driver`:** enable `pdo_sqlite` or `pdo_mysql` in the CLI PHP used by Artisan/Composer.
- **`No application encryption key`:** run `php artisan key:generate` after creating `.env`; do not commit `.env`.
- **Vite manifest missing:** run `npm install` and `npm run build`, or run `npm run dev` while developing.
- **Sign-in denied:** run `php artisan db:seed` in a non-production environment; seeders are repeatable and do not reset visits.
- **Department unavailable:** check that it is active and the staff member is assigned to its department and at least one active service point.
- **Display offline:** confirm the host is running, the screen can reach it, and firewall rules allow the selected port.

## Verification

```powershell
php artisan test
php artisan route:list
npm run build
```

Feature tests use an in-memory SQLite database. They cover queue ordering, priority, authorization, ticket retry safety, transfers, timing, cancellation, analytics, and public display privacy. A parallel multi-connection MySQL integration test remains necessary to empirically verify concurrent claims against the deployment database.