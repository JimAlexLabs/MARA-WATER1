import React from 'react';

/**
 * Shared dashboard chrome — same language as Pricing:
 * deep-water hero, soft panels, pill tabs, tabular stats.
 */

type Tone = 'water' | 'forest' | 'ink' | 'amber';

const HERO_TONE: Record<Tone, string> = {
  water: 'linear-gradient(135deg, #0c4a6e 0%, #0369a1 55%, #0e7490 100%)',
  forest: 'linear-gradient(135deg, #14532d 0%, #166534 50%, #0f766e 100%)',
  ink: 'linear-gradient(135deg, #0f172a 0%, #1e293b 55%, #334155 100%)',
  amber: 'linear-gradient(135deg, #78350f 0%, #92400e 45%, #b45309 100%)',
};

export const PageShell: React.FC<{ children: React.ReactNode; className?: string }> = ({
  children,
  className = '',
}) => <div className={`space-y-6 ${className}`}>{children}</div>;

export const PageHero: React.FC<{
  eyebrow?: string;
  title: React.ReactNode;
  description?: React.ReactNode;
  actions?: React.ReactNode;
  stats?: Array<{ label: string; value: React.ReactNode; hint?: string }>;
  tone?: Tone;
}> = ({ eyebrow, title, description, actions, stats, tone = 'water' }) => (
  <div
    className="rounded-2xl px-5 py-6 sm:px-7 sm:py-8 text-white shadow-sm"
    style={{ background: HERO_TONE[tone] }}
  >
    <div className="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
      <div className="min-w-0">
        {eyebrow && (
          <p className="text-white/75 text-xs font-semibold tracking-[0.18em] uppercase mb-2">
            {eyebrow}
          </p>
        )}
        <h1 className="text-3xl sm:text-4xl font-semibold tracking-tight truncate">{title}</h1>
        {description && (
          <p className="mt-2 text-white/90 max-w-2xl text-sm sm:text-base">{description}</p>
        )}
      </div>
      {actions && <div className="flex flex-wrap gap-2 shrink-0">{actions}</div>}
    </div>
    {stats && stats.length > 0 && (
      <div className={`mt-6 grid gap-2 ${
        stats.length <= 3 ? 'grid-cols-2 sm:grid-cols-3'
          : stats.length <= 4 ? 'grid-cols-2 sm:grid-cols-4'
          : 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-6'
      }`}>
        {stats.map((s) => (
          <div
            key={String(s.label)}
            className="rounded-xl bg-white/10 border border-white/15 px-3 py-3 backdrop-blur-sm"
          >
            <p className="text-[11px] uppercase tracking-wide text-white/75">{s.label}</p>
            <p className="text-xl font-semibold mt-1 tabular-nums">{s.value}</p>
            {s.hint && <p className="text-[11px] text-white/65 mt-0.5">{s.hint}</p>}
          </div>
        ))}
      </div>
    )}
  </div>
);

export const HeroButton: React.FC<{
  children: React.ReactNode;
  onClick?: () => void;
  href?: string;
  variant?: 'solid' | 'ghost';
  type?: 'button' | 'submit';
  disabled?: boolean;
  className?: string;
}> = ({ children, onClick, variant = 'ghost', type = 'button', disabled, className = '' }) => (
  <button
    type={type}
    onClick={onClick}
    disabled={disabled}
    className={`inline-flex items-center px-4 py-2.5 rounded-xl text-sm font-medium transition disabled:opacity-50 ${
      variant === 'solid'
        ? 'bg-white text-sky-900 hover:bg-sky-50 font-semibold'
        : 'bg-white/15 hover:bg-white/25 border border-white/20'
    } ${className}`}
  >
    {children}
  </button>
);

export const Panel: React.FC<{
  children: React.ReactNode;
  className?: string;
  title?: React.ReactNode;
  action?: React.ReactNode;
  padded?: boolean;
}> = ({ children, className = '', title, action, padded = true }) => (
  <section className={`mara-panel ${className}`}>
    {(title || action) && (
      <div className="px-5 py-3 border-b border-slate-100 dark:border-gray-700 flex items-center justify-between bg-slate-50/80 dark:bg-gray-900/40">
        <h2 className="text-sm font-semibold tracking-wide text-slate-800 dark:text-gray-100 uppercase">
          {title}
        </h2>
        {action}
      </div>
    )}
    <div className={padded ? 'p-5' : ''}>{children}</div>
  </section>
);

export const StatGrid: React.FC<{
  items: Array<{ label: string; value: React.ReactNode; icon?: React.ReactNode; tone?: string }>;
}> = ({ items }) => (
  <div className={`grid gap-4 ${
    items.length <= 2 ? 'grid-cols-1 sm:grid-cols-2'
      : items.length === 3 ? 'grid-cols-1 sm:grid-cols-3'
      : 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-4'
  }`}>
    {items.map((item) => (
      <div key={String(item.label)} className="mara-stat">
        <div className="flex items-start justify-between gap-3">
          <div>
            <p className="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-gray-400">
              {item.label}
            </p>
            <p className={`mt-1 text-2xl font-semibold tabular-nums text-slate-900 dark:text-gray-100 ${item.tone || ''}`}>
              {item.value}
            </p>
          </div>
          {item.icon && <div className="text-sky-700/80 dark:text-sky-300">{item.icon}</div>}
        </div>
      </div>
    ))}
  </div>
);

export const TabPills: React.FC<{
  tabs: Array<{ key: string; label: string }>;
  active: string;
  onChange: (key: string) => void;
}> = ({ tabs, active, onChange }) => (
  <div className="flex flex-wrap gap-2">
    {tabs.map((tab) => (
      <button
        key={tab.key}
        type="button"
        onClick={() => onChange(tab.key)}
        className={`px-4 py-2.5 rounded-full text-sm font-medium transition ${
          active === tab.key
            ? 'bg-sky-800 text-white shadow-sm'
            : 'bg-slate-100 dark:bg-gray-800 text-slate-700 dark:text-gray-200 hover:bg-slate-200 dark:hover:bg-gray-700'
        }`}
      >
        {tab.label}
      </button>
    ))}
  </div>
);
