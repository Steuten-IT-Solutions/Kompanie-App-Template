import type { ReactNode } from 'react';

/** Linien der Symbole auf Knöpfen nur mit Symbol (24×24, Strich wie beim Herunterladen). */
const ICONS = {
  submit: <path d="M12 15V4M7 8.5l5-5 5 5M5 20h14" />,
  review: (
    <>
      <path d="M9 4h6v3H9z" />
      <path d="M8 5.5H6v15h12v-15h-2" />
      <path d="M9 13.5l2 2 4-4" />
    </>
  ),
  albumAdd: (
    <>
      <path d="M3 7a1.5 1.5 0 0 1 1.5-1.5h4.5l2 2h8.5A1.5 1.5 0 0 1 21 9v9.5a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 18.5z" />
      <path d="M12 10.5v6M9 13.5h6" />
    </>
  ),
  year: (
    <>
      <rect x="4" y="5.5" width="16" height="14.5" rx="1.5" />
      <path d="M4 10h16M8.5 3.5v4M15.5 3.5v4" />
    </>
  ),
  delete: <path d="M4 7h16M9.5 7V4.5h5V7M6.5 7l1 13h9l1-13M10.5 11v5.5M13.5 11v5.5" />,
  edit: <path d="M4 20h4.5L19.5 9 15 4.5 4 15.5zM13 6.5l4.5 4.5" />,
  selectAll: <path d="M2.5 12.5l4.5 4.5 9.5-9.5M12.5 16l1 1 9-9" />,
  move: (
    <>
      <path d="M3 7a1.5 1.5 0 0 1 1.5-1.5h4.5l2 2h8.5A1.5 1.5 0 0 1 21 9v9.5a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 18.5z" />
      <path d="M8.5 13.5h7M12.5 10.5l3 3-3 3" />
    </>
  ),
  intern: (
    <>
      <rect x="5" y="10.5" width="14" height="10" rx="1.5" />
      <path d="M8.5 10.5V7.5a3.5 3.5 0 0 1 7 0v3" />
    </>
  ),
  public: (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="M3 12h18M12 3c2.5 2.5 3.5 5.5 3.5 9s-1 6.5-3.5 9c-2.5-2.5-3.5-5.5-3.5-9s1-6.5 3.5-9z" />
    </>
  ),
  history: (
    <>
      <path d="M3.5 12a8.5 8.5 0 1 0 2.5-6L3.5 8.5" />
      <path d="M3.5 4v4.5H8M12 7.5V12l3 2" />
    </>
  ),
  view: (
    <>
      <path d="M2 12s3.5-6.5 10-6.5S22 12 22 12s-3.5 6.5-10 6.5S2 12 2 12z" />
      <circle cx="12" cy="12" r="3" />
    </>
  ),
  copy: (
    <>
      <rect x="9" y="9" width="11" height="11" rx="1.5" />
      <path d="M15 9V5.5A1.5 1.5 0 0 0 13.5 4h-8A1.5 1.5 0 0 0 4 5.5v8A1.5 1.5 0 0 0 5.5 15H9" />
    </>
  ),
  print: (
    <>
      <path d="M7 9V3.5h10V9M7 17H4.5A1.5 1.5 0 0 1 3 15.5v-5A1.5 1.5 0 0 1 4.5 9h15a1.5 1.5 0 0 1 1.5 1.5v5a1.5 1.5 0 0 1-1.5 1.5H17" />
      <path d="M7 14h10v6.5H7z" />
    </>
  ),
  download: <path d="M12 4v11M7 10.5l5 5 5-5M5 20h14" />,
} satisfies Record<string, ReactNode>;

export type IconName = keyof typeof ICONS;

export function Icon({ name }: { name: IconName }) {
  return (
    <svg
      viewBox="0 0 24 24"
      width="20"
      height="20"
      aria-hidden="true"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      {ICONS[name]}
    </svg>
  );
}

/**
 * Runder Knopf nur mit Symbol: label ist der Name für Screenreader und der Tooltip (title) am Rechner. variant wie bei
 * .btn (secondary, danger), lb für die dunkle Leiste der Großansicht der Galerie. Nur für Aktionen mit eindeutigem
 * Symbol (Bearbeiten, Löschen, Verlauf, Ansehen, Kopieren, Drucken); sonst bleibt der Knopf mit Text.
 */
export function IconButton({
  icon,
  label,
  variant = 'secondary',
  className,
  disabled,
  expanded,
  onClick,
}: {
  icon: IconName;
  label: string;
  variant?: 'secondary' | 'danger' | 'lb' | 'lb-danger';
  className?: string;
  disabled?: boolean;
  /** Für Knöpfe, die etwas auf- und zuklappen (z. B. Verlauf) */
  expanded?: boolean;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      className={`icon-btn icon-btn-${variant}${className ? ` ${className}` : ''}`}
      aria-label={label}
      title={label}
      aria-expanded={expanded}
      disabled={disabled}
      onClick={onClick}
    >
      <Icon name={icon} />
    </button>
  );
}
