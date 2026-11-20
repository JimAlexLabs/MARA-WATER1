import React, { useEffect, useState } from 'react';
import { formatDate, toIsoDate } from '../utils/dates';

type Props = Omit<React.InputHTMLAttributes<HTMLInputElement>, 'type' | 'value' | 'onChange'> & {
  value?: string;
  onChange?: (event: React.ChangeEvent<HTMLInputElement>) => void;
};

/** Day/month/year text, plus the browser calendar. The value sent to the page stays YYYY-MM-DD. */
export default function DateField({ value, onChange, onBlur, placeholder = 'DD/MM/YYYY', min, max, className, ...rest }: Props) {
  const [text, setText] = useState(() => formatDate(value || ''));

  useEffect(() => {
    setText(formatDate(value || ''));
  }, [value]);

  const emit = (event: React.ChangeEvent<HTMLInputElement>, iso: string) => {
    if (!onChange) return;
    if (iso && typeof min === 'string' && min && iso < min) return;
    if (iso && typeof max === 'string' && max && iso > max) return;
    const target = { ...event.target, value: iso };
    onChange({ ...event, target, currentTarget: target } as React.ChangeEvent<HTMLInputElement>);
  };

  const isoValue = value && /^\d{4}-\d{2}-\d{2}$/.test(value) ? value : '';

  return (
    <span className="relative block">
      <input
        {...rest}
        type="text"
        inputMode="numeric"
        autoComplete="off"
        placeholder={placeholder}
        value={text}
        className={[className, 'pr-9'].filter(Boolean).join(' ')}
        onChange={(event) => {
          const next = event.target.value;
          setText(next);
          if (next.trim() === '') {
            emit(event, '');
            return;
          }
          const iso = toIsoDate(next);
          if (iso) emit(event, iso);
        }}
        onBlur={(event) => {
          setText(formatDate(value || ''));
          onBlur?.(event);
        }}
      />
      <input
        type="date"
        tabIndex={-1}
        aria-label="Open calendar"
        value={isoValue}
        min={typeof min === 'string' ? min : undefined}
        max={typeof max === 'string' ? max : undefined}
        onChange={(event) => {
          setText(formatDate(event.target.value));
          emit(event, event.target.value);
        }}
        className="absolute right-1 top-1/2 h-7 w-7 -translate-y-1/2 cursor-pointer opacity-0"
      />
      <svg
        viewBox="0 0 24 24"
        aria-hidden="true"
        className="pointer-events-none absolute right-2 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400"
      >
        <rect x="3" y="5" width="18" height="16" rx="2" fill="none" stroke="currentColor" strokeWidth="1.8" />
        <path d="M3 10h18M8 3v4M16 3v4" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
      </svg>
    </span>
  );
}
