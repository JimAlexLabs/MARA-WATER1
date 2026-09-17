# Backups: where they live, and how to restore one

This covers Phase 11 of the upgrade plan. There are **two independent
layers** of protection, because they cover different failure modes and
neither one alone is a complete answer to "never lose data":

1. **App-level backups** (built this phase) — a full JSON snapshot of
   every business table, stored in the `backups` table and manageable
   from **Settings → Danger Zone** in the app. Fast, precise, in-app
   restore for admin mistakes (a bad Danger Zone reset, a bad bulk edit),
   and downloadable as a portable file that isn't tied to a Railway
   account at all.
2. **Railway's own volume backup** (infrastructure-level) — a daily
   snapshot of the actual MySQL disk, taken by Railway itself. This is
   what protects against the app-level layer being unable to help you:
   if the database instance itself were ever corrupted, deleted, or
   lost outright, an app-level backup *stored inside that same
   database* would be lost with it. The volume snapshot is independent
   of the database's own contents.

Both matter. Use #1 for "I made a mistake and need yesterday's data
back right now." Use #2 (via Railway support/dashboard) for "the
database itself is gone."

## Where backups live

**App-level:** the `backups` table in the production MySQL database
itself (`Backup` model, `payload` column holds the full JSON snapshot).
Visible and manageable at **Settings → Danger Zone** (admin/Director
role only) in the app: list, create-now, download, and restore.

**Railway volume:** configured on the `mysql-volume` volume attached to
the MySQL service, via `.railway/railway.ts` (`backupSchedules:
["DAILY"]`). Restoring from this is done through Railway's own
dashboard/support, not this app -- see "Restoring the Railway volume
snapshot" below.

## What's covered

Every real business table is backed up -- see
`App\Services\BackupService::PRESERVE_TABLES` and `::WIPE_TABLES` for
the exact list (these are the same two lists the Danger Zone reset uses
to decide what to wipe vs. keep). Framework tables (sessions, cache,
migrations, etc.) and the `backups`/`reset_logs` tables themselves are
deliberately excluded -- the point is to protect business data, not to
make a backup that includes itself.

**Important for whoever adds a table in a future phase:** these two
lists have to be updated by hand when a new table is added. Phase 11
found and fixed a real instance of this drifting out of sync --
`chart_of_accounts`, `equipment_items`, `driver_trips`,
`driver_trip_items`, `petty_cash_entries`, and `debtor_ledger_entries`
were all added in earlier phases but never added to either list, so
backups silently omitted them until this phase's fix. Add any new
table to one of the two lists in `BackupService` in the same change
that creates it.

## When a backup gets taken automatically

- **Before the Danger Zone "Clear All Data" reset** (`reason:
  pre_reset`) -- always, unconditionally, before anything is wiped.
- **Before restoring a backup** (`reason: pre_restore`) -- so restoring
  the wrong backup by mistake is itself undoable.
- **Daily, on a schedule** (`reason: scheduled`) -- via `php artisan
  backups:run`, registered in `routes/console.php`. This app runs as a
  single persistent process on Railway with no system cron of its own,
  so the actual trigger is a separate Railway service
  (`mara-water1-backup-cron` in `.railway/railway.ts`) configured to
  run on Railway's own Cron Schedule deploy mode, once a day, calling
  that same command. **As of this phase, that second service and the
  volume backup schedule are specified in `.railway/railway.ts` and
  validated (`railway config plan` shows exactly the intended change,
  cleanly, with nothing destroyed) but not yet actually applied to the
  live project.** This was first suspected to be a token-permission
  issue (Phase 11 was built with a project-scoped `RAILWAY_TOKEN`), but
  Phase 12 retried the identical `railway config apply` with a fully
  authenticated account-owner session and got the exact same silent
  no-op a fifth time, across five distinct invocation styles (plain
  apply, verbose apply, apply with `--confirm-destructive`, and the
  documented CI pinned-plan flow of `config plan --out` then
  `config apply --plan ... --yes --confirm-destructive` -- the last of
  which even prints "Applied pinned Railway configuration." on success
  with zero actual effect). That rules out account permissions as the
  cause. It looks instead like a genuine limitation in Railway's
  `config apply` path specifically for **creating a brand-new service**
  and/or **setting `backupSchedules` on an existing volume** via the
  declarative IaC flow -- other applies to *existing* resources (e.g.
  deploying code changes to MARA-WATER1) have worked fine all project.
  Given that, don't spend time re-trying `railway config apply` --
  go straight to the dashboard fallback below, which uses a different,
  more mature code path than the newer IaC apply feature. Until this is
  done, backups only happen when someone clicks "Create Backup Now" in
  Settings, or before a reset/restore.
- **Retention:** backups older than 400 days are pruned automatically
  every time a new one is taken (`BackupService::cleanup()`), except
  the single most recent backup is never deleted regardless of age --
  so there's always at least one to fall back on even if scheduled
  backups have been broken or stopped for longer than the retention
  window. 400 days comfortably covers "a full financial year" with
  margin on both sides of a year boundary, per the spec.

## Applying the pending infrastructure change

`railway config apply` has not worked for this specific change under
any credentials tried so far (see above) -- expect it to report success
and do nothing. Feel free to confirm that for yourself first:

```bash
cd MARA.COM
railway config plan     # review the diff -- should show exactly:
                         #   + Create service mara-water1-backup-cron
                         #   ~ Update mysql-volume config.backupSchedules (null -> ["DAILY"])
railway config apply --yes
```

But don't loop on it -- go straight to the Railway
dashboard: **Volumes → mysql-volume → enable a daily backup schedule**,
and **New Service → GitHub Repo → same repo, root directory
`backend/mara-water-api` → Settings → Cron Schedule: `0 2 * * *`,
Custom Start Command: `php artisan backups:run scheduled`**, with the
same `DB_*`/`APP_KEY`/`APP_ENV` environment variables as the
`MARA-WATER1` service (reference them, don't retype the values, so a
password rotation only has to happen in one place).

## Restoring an app-level backup

From **Settings → Danger Zone** (admin only):

1. Find the backup in the list (each shows its date, reason, row
   count, and size).
2. Click **Restore**. This requires the same unlock as a reset --
   toggle Danger Zone unlocked, then type the confirmation phrase shown
   on screen.
3. Confirm. The app takes a fresh safety backup of whatever's about to
   be overwritten (`reason: pre_restore`) *first*, then truncates and
   re-populates every table the chosen backup covers from its exact
   snapshot.
4. Danger Zone re-locks automatically afterward, same as a reset.

**A restore is destructive to the current data** -- it's not a merge,
it's "make the database look exactly like it did at the moment that
backup was taken." That's why it's gated the same way the reset is, and
why it always takes its own safety backup first: if you restore the
wrong one, restore the `pre_restore` backup it just made to undo it.

Or, without going through the UI: download the backup as a JSON file
(same screen) and hand it to whoever needs to inspect or manually
reload specific rows -- the file is a straightforward `{"table_name":
[{...row...}, ...]}` structure, one key per table.

## Restoring the Railway volume snapshot

This is Railway's own infrastructure feature, not something this app's
UI can trigger. If the MySQL service itself is lost or corrupted:
contact Railway support or use their dashboard's volume restore flow
to roll the disk back to a daily snapshot. This is the "the database
itself is gone" recovery path -- it restores the whole volume, not a
single table or point-in-time app-level state the way the in-app
restore does.
