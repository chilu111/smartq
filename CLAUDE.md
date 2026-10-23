# SmartQueue repository guidance

- Laravel 13, PHP 8.3+, Eloquent, Blade, Vite, and MySQL/MariaDB. SQLite is used for local demonstration and feature tests.
- Queue lifecycle and ticket allocation belong in `app/Services`; controllers authorize/validate and delegate.
- Keep public display responses limited to ticket, department, service point, and connection state. Never expose patient identity or priority reason on public screens.
- Queue transitions must use transactions, row locks, active-stage constraints, and append-only queue/audit events.
- Never run destructive database commands against an existing database. `smartqueue:demo-reset` is restricted to an explicitly enabled local SQLite demo database and requires confirmation.
- Run `php artisan test`, `php artisan view:cache`, and `npm run build` after relevant changes. Use `php artisan migrate` for incremental schema updates.
- Do not commit `.env`, patient data, or the local SQLite database.