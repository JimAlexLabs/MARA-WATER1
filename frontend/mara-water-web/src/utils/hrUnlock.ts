/**
 * Ops brief §9: client-side mirror of the HR password re-auth unlock.
 * Server enforces via EnsureHrUnlocked + cache user:{id}:hr_unlocked;
 * sessionStorage only drives UX (show/hide locked content) and the
 * optional X-Hr-Unlock header.
 */

const STORAGE_KEY = 'hr_unlock';

export type HrUnlockPayload = {
  token: string;
  expires_at: string;
};

export function getHrUnlock(): HrUnlockPayload | null {
  try {
    const raw = sessionStorage.getItem(STORAGE_KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw) as HrUnlockPayload;
    if (!parsed?.token || !parsed?.expires_at) {
      clearHrUnlock();
      return null;
    }
    if (new Date(parsed.expires_at).getTime() <= Date.now()) {
      clearHrUnlock();
      return null;
    }
    return parsed;
  } catch {
    clearHrUnlock();
    return null;
  }
}

export function isHrUnlocked(): boolean {
  return getHrUnlock() !== null;
}

export function setHrUnlock(payload: HrUnlockPayload): void {
  sessionStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
}

export function clearHrUnlock(): void {
  sessionStorage.removeItem(STORAGE_KEY);
}
