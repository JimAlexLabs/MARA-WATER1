/**
 * Capacitor / Android shell detection.
 * Live app is the same React web app; this only tunes touch chrome when
 * opened from the native shell (?mobile=1 or Capacitor user agent).
 */
export function initMobileShell(): void {
  if (typeof window === 'undefined' || typeof document === 'undefined') return;

  const params = new URLSearchParams(window.location.search);
  const ua = navigator.userAgent || '';
  const isCapacitor = !!(window as unknown as { Capacitor?: { isNativePlatform?: () => boolean } }).Capacitor?.isNativePlatform?.()
    || /Capacitor/i.test(ua)
    || params.get('mobile') === '1';

  if (!isCapacitor) return;

  document.documentElement.classList.add('mara-mobile');
  document.body.classList.add('mara-mobile');

  // Prefer standing viewport for field use; avoid accidental zoom.
  const meta = document.querySelector('meta[name="viewport"]');
  if (meta) {
    meta.setAttribute(
      'content',
      'viewport-fit=cover, width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no'
    );
  }
}
