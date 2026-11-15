/** Show dates as day/month/year. Stored values stay YYYY-MM-DD. */

export function formatDate(value?: string | null): string {
  if (value == null) return '';
  const raw = String(value).trim();
  if (!raw || raw === '—') return '';

  const iso = raw.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (iso) return `${iso[3]}/${iso[2]}/${iso[1]}`;

  const dmy = raw.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
  if (dmy) {
    return `${dmy[1].padStart(2, '0')}/${dmy[2].padStart(2, '0')}/${dmy[3]}`;
  }

  const parsed = new Date(raw);
  if (Number.isNaN(parsed.getTime())) return raw;
  const day = String(parsed.getDate()).padStart(2, '0');
  const month = String(parsed.getMonth() + 1).padStart(2, '0');
  return `${day}/${month}/${parsed.getFullYear()}`;
}

/** Turn a finished DD/MM/YYYY into YYYY-MM-DD. Incomplete typing returns null. */
export function toIsoDate(value: string): string | null {
  const match = value.trim().match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
  if (!match) return null;
  const day = Number(match[1]);
  const month = Number(match[2]);
  const year = Number(match[3]);
  if (month < 1 || month > 12 || day < 1 || day > 31) return null;
  const date = new Date(year, month - 1, day);
  if (date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) return null;
  return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

/** Browser locale dates follow day, then month, then year. Machine ISO calls pass en-CA and stay unchanged. */
export function installDayMonthYear(): void {
  const date = Date.prototype.toLocaleDateString;
  Date.prototype.toLocaleDateString = function (
    locales?: Intl.LocalesArgument,
    options?: Intl.DateTimeFormatOptions,
  ) {
    return date.call(this, locales == null ? 'en-GB' : locales, options);
  };
  const both = Date.prototype.toLocaleString;
  Date.prototype.toLocaleString = function (
    locales?: Intl.LocalesArgument,
    options?: Intl.DateTimeFormatOptions,
  ) {
    return both.call(this, locales == null ? 'en-GB' : locales, options);
  };
}
