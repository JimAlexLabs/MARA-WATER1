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
