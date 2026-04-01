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
HERD_MYSQL_DUMP_BINARY=
HERD_MYSQL_IMPORT_BINARY=
```

The app uses your local Herd MySQL server for browsing databases. The default saved local source expects MySQL at `127.0.0.1:3306` with username `root`.

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
- The desktop rewrite is in [`desktop/`](/Users/kylemcgowan/Herd/sql/desktop) and is separate from the Laravel install flow above.
- If the UI looks stale after frontend changes, run `npm run build` or `npm run dev`.
