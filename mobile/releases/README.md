# MARA Water Ops — Android APK

- **MARA-Water-Ops.apk** — installable debug build (`com.marawater.ops`)
- Loads live ops from https://marawater.online (Vercel). Web redeploys update the app UI automatically.
- Data syncs with the web app via the shared Railway API.

## Install

```bash
adb install -r MARA-Water-Ops.apk
```

Or copy the APK to a phone and open it (allow install from unknown sources).

## Rebuild

See [`mobile/README.md`](../../mobile/README.md).
