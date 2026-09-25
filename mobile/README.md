# MARA Water — Android Ops App

Native Android shell for daily MARA Water field operations. **Slightly detached** from the web repo layout (`mobile/`), shared backend with the web app.

## Sync model (web ↔ mobile)

| Concern | How it stays in sync |
|--------|----------------------|
| Data (trips, sales, inventory, attendance) | Same Railway API: `https://mara-water1-production.up.railway.app` |
| UI / features after deploy | Capacitor loads **live** `https://marawater.online` — Vercel prod redeploys update the app **without a new APK** |
| Offline / first boot | Bundled `www/` boot screen; reconnects to live ops when online |

Web and mobile are **not** two backends. Drivers logging a sale on Android write the same records Manager/Director see on web.

## Architecture

```
┌─────────────────────┐     HTTPS      ┌──────────────────────┐
│  Android APK        │ ─────────────► │  marawater.online    │
│  (Capacitor shell)  │                │  (Vercel React app)  │
└─────────────────────┘                └──────────┬───────────┘
                                                  │
                                                  ▼
                                       ┌──────────────────────┐
                                       │  Railway Laravel API │
                                       │  (shared MySQL)      │
                                       └──────────────────────┘
```

## Build the APK (local)

Requires Android SDK + JDK 17+ (Android Studio JBR works).

```bash
export JAVA_HOME="/Applications/Android Studio.app/Contents/jbr/Contents/Home"
export ANDROID_HOME="$HOME/Library/Android/sdk"
export PATH="$JAVA_HOME/bin:$PATH"

cd mobile/mara-water-android
npm ci
npx cap sync android
npm run build:apk
npm run copy:apk
# → dist/android/MARA-Water-Ops.apk
```

Install:

```bash
adb install -r ../../dist/android/MARA-Water-Ops.apk
```

## Release / Play Store

Use `assembleRelease` with a signing keystore (not committed). Debug APK is for field pilots.

## Branch policy

- Develop mobile shell on `feature/mobile-android`.
- Keep `main` as web/API source of truth.
- Merge mobile when the APK pipeline is stable; live URL means feature work still lands via web deploy.

## Ops UX notes

- Same roles: Driver, Sales Exec, Manager, Director, Investor.
- Prefer large tap targets and bottom-heavy actions on phones (web layout already responsive).
- `?mobile=1` marks Capacitor sessions for optional shell styling.
