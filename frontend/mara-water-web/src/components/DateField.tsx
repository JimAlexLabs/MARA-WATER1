import React, { useEffect, useState } from 'react';
import { formatDate, toIsoDate } from '../utils/dates';

type Props = Omit<React.InputHTMLAttributes<HTMLInputElement>, 'type' | 'value' | 'onChange'> & {
  value?: string;
  onChange?: (event: React.ChangeEvent<HTMLInputElement>) => void;
};

/** Text date box shown as DD/MM/YYYY. The value sent to the page stays YYYY-MM-DD. */
export default function DateField({ value, onChange, onBlur, placeholder = 'DD/MM/YYYY', min, max, ...rest }: Props) {
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

  return (
    <input
      {...rest}
      type="text"
      inputMode="numeric"
      autoComplete="off"
      placeholder={placeholder}
      value={text}
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
  );
}
