import type { ReactNode } from 'react';
import type { BlockKind } from './blockDrafts';

/** Symbol je Blockart für das Fenster „Block einfügen“ (24×24, Strich wie bei den anderen Symbolen, icons.tsx). */
const BLOCK_ICONS: Record<BlockKind, ReactNode> = {
  text: <path d="M4 6h16M4 10.5h16M4 15h16M4 19.5h10" />,
  countdown: (
    <>
      <path d="M8 3h8M8 21h8M8.5 3v3.5L12 12l-3.5 5.5V21M15.5 3v3.5L12 12l3.5 5.5V21" />
    </>
  ),
  button: (
    <>
      <rect x="3" y="7" width="18" height="10" rx="5" />
      <path d="M9.5 12h5M12.5 10l2 2-2 2" />
    </>
  ),
  events: (
    <>
      <rect x="4" y="5.5" width="16" height="14.5" rx="1.5" />
      <path d="M4 10h16M8.5 3.5v4M15.5 3.5v4M8 13.5h2M8 16.5h2M12.5 13.5h3.5" />
    </>
  ),
  contacts: (
    <>
      <circle cx="12" cy="8.5" r="3.5" />
      <path d="M5 20c.5-3.5 3.3-6 7-6s6.5 2.5 7 6" />
    </>
  ),
  notice: (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="M12 11v5.5M12 7.5v.5" />
    </>
  ),
  divider: <path d="M3 12h18M7 6.5h10M7 17.5h10" />,
  schedule: (
    <>
      <circle cx="6" cy="6" r="1" />
      <circle cx="6" cy="12" r="1" />
      <circle cx="6" cy="18" r="1" />
      <path d="M10 6h10M10 12h10M10 18h10" />
    </>
  ),
  facts: <path d="M4 20h16M6.5 20v-6M11 20V8M15.5 20v-9M20 20V4.5" />,
  poll: (
    <>
      <rect x="4" y="4" width="5" height="5" rx="1" />
      <rect x="4" y="15" width="5" height="5" rx="1" />
      <path d="M5.5 6.5l1 1 1.5-2M12 6.5h8M12 17.5h8" />
    </>
  ),
  album: (
    <>
      <rect x="3" y="4.5" width="18" height="15" rx="1.5" />
      <circle cx="8.5" cy="9.5" r="1.5" />
      <path d="M3 17l5-4.5 4 3.5 3-2.5 6 5" />
    </>
  ),
  document: (
    <>
      <path d="M6 3h8l4 4v14H6z" />
      <path d="M14 3v4h4M9 12.5h6M9 16h6" />
    </>
  ),
  sponsors: <path d="M12 20.5s-8-4.6-8-10.4A4.4 4.4 0 0 1 12 7.6a4.4 4.4 0 0 1 8 2.5c0 5.8-8 10.4-8 10.4z" />,
  map: (
    <>
      <path d="M12 21s-6.5-6-6.5-11a6.5 6.5 0 0 1 13 0c0 5-6.5 11-6.5 11z" />
      <circle cx="12" cy="10" r="2.5" />
    </>
  ),
};

export function BlockIcon({ type }: { type: BlockKind }) {
  return (
    <svg
      viewBox="0 0 24 24"
      width="24"
      height="24"
      aria-hidden="true"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      {BLOCK_ICONS[type]}
    </svg>
  );
}
