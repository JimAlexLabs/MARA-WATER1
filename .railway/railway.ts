import { defineRailway, github, mysql, preserve, project, ref, service, volume } from "railway/iac";

export default defineRailway(() => {
  const MySQL = mysql("MySQL", { region: "iad" });
  MySQL.deploy = { startCommand: "docker-entrypoint.sh mysqld --innodb-use-native-aio=0 --disable-log-bin --performance_schema=0 --innodb-buffer-pool-size=1G" };
  MySQL.networking = { privateNetworkEndpoint: "mysql" };
  // Phase 11 (Backups: never lose data): daily platform-level volume
  // snapshot -- protects against infrastructure failure (disk corruption,
  // a lost/corrupted MySQL instance) that an app-level backup stored in
  // that same database can't protect against. This is the "platform's
  // built-in backup" the spec asks for as the first choice; the app-level
  // Backup table/backups:run command (below) is the second, independent
  // layer -- fast in-app point-in-time restore for admin mistakes, and a
  // portable JSON export that isn't tied to a Railway account at all.
  const mysqlVolume = volume("mysql-volume", { alerts: { usage: { "100": {}, "80": {}, "95": {} } }, allowOnlineResize: true, region: "iad", sizeMB: 500, backupSchedules: ["DAILY"] });
  const MARAWATER1 = service("MARA-WATER1", {
    source: github("JimAlexLabs/MARA-WATER1", { commitSha: "6a2b2c84a430faf49c333f65002b326e21432118", rootDirectory: "backend/mara-water-api", upstreamUrl: "https://github.com/JimAlexLabs/MARA-WATER1" }),
    replicas: { "iad": 1 },
    networking: { privateNetworkEndpoint: "mara-water1" },
    env: { APP_DEBUG: preserve(), APP_ENV: preserve(), APP_KEY: preserve(), APP_NAME: preserve(), APP_URL: preserve(), ASSET_URL: preserve(), CACHE_STORE: preserve(), DB_CONNECTION: preserve(), DB_DATABASE: preserve(), DB_HOST: preserve(), DB_PASSWORD: preserve(), DB_PORT: preserve(), DB_USERNAME: preserve(), LOG_CHANNEL: preserve(), LOG_LEVEL: preserve(), QUEUE_CONNECTION: preserve(), SANCTUM_STATEFUL_DOMAINS: preserve(), SESSION_DRIVER: preserve(), VIEW_COMPILED_PATH: preserve() },
  });

  // Phase 11: the app-level daily backup (routes/console.php's
  // `backups:run` schedule). This app runs as a single persistent
  // FrankenPHP process with no system cron of its own, so Railway's own
  // Cron Schedule deploy mode runs this as a separate, otherwise-idle
  // service: same codebase/build as MARA-WATER1 (same repo + root
  // directory), but its deploy command IS the backup command instead of
  // starting the web server, and it only spins up once a day to run it.
  // DB credentials are referenced from MARA-WATER1's own env (ref(), not
  // copied literal values) so nothing sensitive is duplicated in source.
  const BackupCron = service("mara-water1-backup-cron", {
    source: github("JimAlexLabs/MARA-WATER1", { rootDirectory: "backend/mara-water-api", upstreamUrl: "https://github.com/JimAlexLabs/MARA-WATER1" }),
    deploy: { cronSchedule: "0 2 * * *", startCommand: "php artisan backups:run scheduled" },
    env: {
      APP_ENV: ref(MARAWATER1, "APP_ENV"),
      APP_KEY: ref(MARAWATER1, "APP_KEY"),
      DB_CONNECTION: ref(MARAWATER1, "DB_CONNECTION"),
      DB_DATABASE: ref(MARAWATER1, "DB_DATABASE"),
      DB_HOST: ref(MARAWATER1, "DB_HOST"),
      DB_PASSWORD: ref(MARAWATER1, "DB_PASSWORD"),
      DB_PORT: ref(MARAWATER1, "DB_PORT"),
      DB_USERNAME: ref(MARAWATER1, "DB_USERNAME"),
      LOG_CHANNEL: ref(MARAWATER1, "LOG_CHANNEL"),
      LOG_LEVEL: ref(MARAWATER1, "LOG_LEVEL"),
      VIEW_COMPILED_PATH: ref(MARAWATER1, "VIEW_COMPILED_PATH"),
    },
  });

  // Round 5B Phase 3: weekly stock reconciliation Excel (Sundays 03:00 UTC)
  const StockReconWeekly = service("mara-water1-stock-recon-weekly", {
    source: github("JimAlexLabs/MARA-WATER1", { rootDirectory: "backend/mara-water-api", upstreamUrl: "https://github.com/JimAlexLabs/MARA-WATER1" }),
    deploy: { cronSchedule: "0 3 * * 0", startCommand: "php artisan stock-reconciliation:run weekly" },
    env: {
      APP_ENV: ref(MARAWATER1, "APP_ENV"),
      APP_KEY: ref(MARAWATER1, "APP_KEY"),
      DB_CONNECTION: ref(MARAWATER1, "DB_CONNECTION"),
      DB_DATABASE: ref(MARAWATER1, "DB_DATABASE"),
      DB_HOST: ref(MARAWATER1, "DB_HOST"),
      DB_PASSWORD: ref(MARAWATER1, "DB_PASSWORD"),
      DB_PORT: ref(MARAWATER1, "DB_PORT"),
      DB_USERNAME: ref(MARAWATER1, "DB_USERNAME"),
      LOG_CHANNEL: ref(MARAWATER1, "LOG_CHANNEL"),
      LOG_LEVEL: ref(MARAWATER1, "LOG_LEVEL"),
      VIEW_COMPILED_PATH: ref(MARAWATER1, "VIEW_COMPILED_PATH"),
    },
  });

  // Round 5B Phase 3: monthly stock reconciliation Excel (1st of month 04:00 UTC)
  const StockReconMonthly = service("mara-water1-stock-recon-monthly", {
    source: github("JimAlexLabs/MARA-WATER1", { rootDirectory: "backend/mara-water-api", upstreamUrl: "https://github.com/JimAlexLabs/MARA-WATER1" }),
    deploy: { cronSchedule: "0 4 1 * *", startCommand: "php artisan stock-reconciliation:run monthly" },
    env: {
      APP_ENV: ref(MARAWATER1, "APP_ENV"),
      APP_KEY: ref(MARAWATER1, "APP_KEY"),
      DB_CONNECTION: ref(MARAWATER1, "DB_CONNECTION"),
      DB_DATABASE: ref(MARAWATER1, "DB_DATABASE"),
      DB_HOST: ref(MARAWATER1, "DB_HOST"),
      DB_PASSWORD: ref(MARAWATER1, "DB_PASSWORD"),
      DB_PORT: ref(MARAWATER1, "DB_PORT"),
      DB_USERNAME: ref(MARAWATER1, "DB_USERNAME"),
      LOG_CHANNEL: ref(MARAWATER1, "LOG_CHANNEL"),
      LOG_LEVEL: ref(MARAWATER1, "LOG_LEVEL"),
      VIEW_COMPILED_PATH: ref(MARAWATER1, "VIEW_COMPILED_PATH"),
    },
  });

  return project("pure-prosperity", {
    resources: [MySQL, MARAWATER1, BackupCron, StockReconWeekly, StockReconMonthly, mysqlVolume],
  });
});
