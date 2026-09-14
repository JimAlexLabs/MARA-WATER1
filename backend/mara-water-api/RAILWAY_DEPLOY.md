# Deploying the MARA-WATER API to Railway

The backend is a **Laravel 12** app (`backend/mara-water-api`). It needs a PHP host + a
MySQL database. Railway provides both. Everything below is done in the Railway
dashboard (railway.app) — no CLI required.

---

## What was fixed to make this deployable

| File | Change |
|---|---|
| `bootstrap/app.php` | Registered `routes/api.php` (`api:` + `apiPrefix: 'api'`). Without this the API served **nothing**. |
| `routes/api.php` | Added `GET /auth/me` (the frontend calls this) and `PUT\|PATCH /auth/profile`; pointed the dangling `/auth/profile` GET at the real `me()` method. |
| `database/migrations/0001_01_01_000000_create_users_table.php` | No longer creates `users` (the raw SQL schema owns it, with a UUID PK + `password_hash`/`role_id`/`department_id`). Still provisions `password_reset_tokens` + `sessions`. |
| `database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php` | `morphs('tokenable')` → `uuidMorphs('tokenable')` so Sanctum tokens match the UUID `users.id`. |
| `config/cors.php` | Added. Allows the Vercel origin to call `api/*` (Bearer-token auth, no cookies). Set `CORS_ALLOWED_ORIGINS` to lock down later. |
| `database/railway_setup.sql` | The full MARA-WATER schema + seed, with `CREATE DATABASE` / `USE` stripped so it loads into Railway's pre-created `railway` database. |

Seeded login: **`director@marawater.com` / `password`**

---

## Deploying via the Railway CLI — gotchas that cost real time

The service has **both** a GitHub source and gets deployed via `railway up` (CLI
upload). Its **Root Directory is set to `backend/mara-water-api`** (needed for the
GitHub source to build correctly in this monorepo). That setting applies to CLI
uploads too, so:

- **Run `railway up` from the repo root**, not from inside `backend/mara-water-api`.
  Running it from the subfolder double-applies the Root Directory (Railway looks for
  `backend/mara-water-api/backend/mara-water-api` and fails with "Root directory ...
  was not found").
- **`composer.lock` must be resolved against the PHP version Railway actually
  provisions**, not whatever PHP is on your dev machine. `composer.json` pins
  `config.platform.php` to `8.2.33` for exactly this reason — regenerate the lock
  with `composer update` (not `composer update --with-all-dependencies` on a newer
  local PHP) if you bump dependencies, or the build fails immediately in
  `composer install --no-scripts` with "your lock file does not contain a
  compatible set of packages."
- **`php artisan view:cache` can fail at build time** with `RuntimeException: View
  path not found` — `config('view.compiled')` is `realpath(storage_path('framework/
  views'))`, evaluated once during `config:cache`, and can resolve to `false`
  depending on build-stage timing/layer ordering. Fixed by setting the
  `VIEW_COMPILED_PATH=/app/storage/framework/views` environment variable on the
  service (already set) so it never depends on `realpath()` at all.

---

## 1. Create the project

1. railway.app → **New Project** → **Deploy from GitHub repo** → `JimAlexLabs/MARA-WATER1`
   (authorize Railway for the repo if prompted).
2. Railway creates one service from the repo. Open it → **Settings**:
   - **Root Directory**: `backend/mara-water-api`
   - **Build**: leave on Nixpacks (auto-detects PHP from `composer.json`).
   - **Deploy → Pre-Deploy Command**: `php artisan migrate --force`
   - **Deploy → Start Command** (only if Nixpacks' default doesn't serve):
     `php artisan serve --host 0.0.0.0 --port $PORT`
     *(fine for now; move to nginx/FrankenPHP later — see TODOs)*

## 2. Add the database

1. In the project → **New** → **Database** → **Add MySQL**.
2. It provisions with a default database named `railway`.

## 3. Set the app service variables

Service → **Variables** → add (use Railway's reference syntax to pull from the MySQL service):

```
APP_NAME=MARA-WATER
APP_ENV=production
APP_DEBUG=false
APP_KEY=            # generate: see below
APP_URL=https://<your-service>.up.railway.app

DB_CONNECTION=mysql
DB_HOST=${{MySQL.MYSQLHOST}}
DB_PORT=${{MySQL.MYSQLPORT}}
DB_DATABASE=${{MySQL.MYSQLDATABASE}}
DB_USERNAME=${{MySQL.MYSQLUSER}}
DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}

SESSION_DRIVER=array
CACHE_STORE=database
QUEUE_CONNECTION=database
LOG_CHANNEL=stderr

# Required -- see "Deploying via the Railway CLI" above for why.
VIEW_COMPILED_PATH=/app/storage/framework/views

# Optional: restrict CORS once the Vercel URL is known
# CORS_ALLOWED_ORIGINS=https://mara-water.vercel.app
```

Generate `APP_KEY` locally and paste the value:
```
php artisan key:generate --show
# or, without PHP:
echo "base64:$(openssl rand -base64 32)"
```

## 4. Load the schema

Railway MySQL service → **Connect** tab → copy the **public** connection details, then from
this repo root:

```bash
mysql -h <PROXY_HOST> -P <PROXY_PORT> -u root -p<PASSWORD> railway < database/railway_setup.sql
```

(Or paste `database/railway_setup.sql` into the service's **Query** tab in chunks — it's ~2500 lines.)

`php artisan migrate --force` (the pre-deploy command) then adds the framework tables
(`personal_access_tokens`, `sessions`, `password_reset_tokens`, `cache*`, `jobs*`).

## 5. Verify

```bash
BASE=https://<your-service>.up.railway.app/api/v1

curl -s $BASE/auth/login -H 'Content-Type: application/json' \
  -d '{"email":"director@marawater.com","password":"password"}'
# -> {"success":true,...,"data":{"token":"...", ...}}

TOKEN=...   # from the response
curl -s $BASE/auth/me -H "Authorization: Bearer $TOKEN"
curl -s $BASE/dashboard/overview -H "Authorization: Bearer $TOKEN"
```

## 6. Point the frontend at it

In the **Vercel** project (`mara-water`) → Settings → Environment Variables:

```
REACT_APP_API_URL = https://<your-service>.up.railway.app/api/v1
```

Redeploy the frontend (Deployments → ⋯ → Redeploy). Log in with the seeded director account.

---

## TODOs / known gaps

- **Serving**: `php artisan serve` is single-process. For real traffic switch to the
  Nixpacks nginx+php-fpm setup, a `Dockerfile`, or FrankenPHP.
- **`composer.lock` is git-ignored** in this repo, so every build resolves fresh
  dependency versions. Commit a lock file for reproducible deploys.
- **Seed depth**: `railway_setup.sql` creates the full schema but only core seed
  data (roles, departments, permissions, the director user). Load
  `database/09_seed_data.sql` etc. for fuller demo data.
- **Storage**: file uploads go to local disk (`storage/app`), which is ephemeral on
  Railway. Move to S3-compatible storage for persistence.
- **`APP_KEY` rotation**: the old committed `.env.backup` files (now removed) contained
  a key — treat it as compromised, use a fresh one.
