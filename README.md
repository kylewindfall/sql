# Herd Studio

Herd Studio is a Laravel web app for browsing and editing MySQL databases with a TablePlus-style interface for local development.

## Requirements

- PHP 8.5
- Composer
- Node.js and npm
- MySQL running locally through Laravel Herd
- Laravel Herd for local site hosting

## Install

1. Clone the repository and change into the project directory.
2. Install PHP dependencies:

```bash
composer install
```

3. Install frontend dependencies:

```bash
npm install
```

4. Create the environment file if it does not already exist:

```bash
cp .env.example .env
```

5. Generate the application key:

```bash
php artisan key:generate
```

6. Update the database and Herd-specific environment values in `.env` if needed.

These are the most relevant settings for the local app:

```env
APP_NAME="Herd Studio"
APP_ENV=local
APP_DEBUG=true

DB_CONNECTION=sqlite

HERD_MYSQL_HOST=127.0.0.1
HERD_MYSQL_PORT=3306
HERD_MYSQL_SOCKET=
HERD_MYSQL_USERNAME=root
HERD_MYSQL_PASSWORD=
HERD_MYSQL_BINARY=
HERD_MYSQLDUMP_BINARY=
```

The app uses your local Herd MySQL server for browsing databases. The default saved local source expects MySQL at `127.0.0.1:3306` with username `root`.

The codebase does not hardcode one machine's filesystem paths anymore:

- Herd CLI binaries default to `~/Library/Application Support/Herd/bin/mysql` and `~/Library/Application Support/Herd/bin/mysqldump` for the current macOS user.
- SSH key paths in the UI can be entered as `~/.ssh/id_rsa`; the app expands `~` to the current user's home directory at runtime.

Set overrides in `.env` only if your machine uses non-standard locations:

```env
HERD_MYSQL_BINARY="/custom/path/to/mysql"
HERD_MYSQLDUMP_BINARY="/custom/path/to/mysqldump"
HERD_MYSQL_SOCKET="/custom/path/to/mysql.sock"
```

Useful commands for finding those paths on another Mac:

```bash
which mysql
which mysqldump
echo ~/.ssh/id_rsa
ls ~/Library/Application\\ Support/Herd/bin
```

7. Run the database migrations used by the app itself:

```bash
php artisan migrate
```

8. Build frontend assets:

```bash
npm run build
```

## Run Locally

For active development, run:

```bash
composer run dev
```

That starts:

- the Laravel dev server
- the queue listener
- Laravel Pail
- the Vite dev server

If you only need compiled assets and are using Herd to serve the site, `npm run build` is enough.

## Testing

Run the feature test suite with:

```bash
php artisan test --compact
```

Format PHP changes with:

```bash
vendor/bin/pint --dirty --format agent
```

## Notes

- The Laravel web app lives in the repository root.
- The desktop rewrite is in `desktop/` and is separate from the Laravel install flow above.
- If the UI looks stale after frontend changes, run `npm run build` or `npm run dev`.
