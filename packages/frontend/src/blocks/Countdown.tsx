import { useEffect, useState } from 'react';
import type { CountdownBlock } from '../api';
import { countdownParts, countdownPhase, nextChange, unitLabel } from './countdown';

/** Datum (und Uhrzeit) des Beginns in Ortszeit des Geräts, z. B. „Sa., 12. Juni 2027, 14:00 Uhr“. */
function formatStart(iso: string, withTime: boolean): string {
  const d = new Date(iso);
  const date = d.toLocaleDateString('de-DE', { weekday: 'short', day: 'numeric', month: 'long', year: 'numeric' });
  return withTime ? `${date}, ${d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })} Uhr` : date;
}

/** Aktuelle Zeit, neu gesetzt, wenn sich die Anzeige ändert, und nur solange die Seite sichtbar ist. */
function useNow(startsAt: string | null, endsAt: string | null): number {
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    let timer: ReturnType<typeof setTimeout> | undefined;
    const schedule = () => {
      clearTimeout(timer);
      if (document.visibilityState === 'hidden') return;
      const t = Date.now();
      setNow(t);
      const wait = nextChange(t, startsAt, endsAt);
      if (wait !== null) timer = setTimeout(schedule, wait + 20);
    };
    schedule();
    document.addEventListener('visibilitychange', schedule);
    return () => {
      clearTimeout(timer);
      document.removeEventListener('visibilitychange', schedule);
    };
  }, [startsAt, endsAt]);
  return now;
}

/**
 * Countdown-Block: zählt bis zum Beginn (immer zwei Angaben), zeigt danach bis zum Ende den Text „läuft“ und danach den
 * Text für die Zeit danach oder nichts (after hide).
 */
export default function Countdown({ block }: { block: CountdownBlock }) {
  const now = useNow(block.starts_at, block.ends_at);
  const phase = countdownPhase(now, block.starts_at, block.ends_at);
  const title = block.title || block.event_title || '';
  const text = phase === 'running' ? block.running_text || title : block.after === 'hide' ? '' : block.after_text;
  if (phase !== 'before' && !text) return null;
  const start = block.starts_at ? new Date(block.starts_at) : null;
  const withTime = block.source === 'event' ? !!start && (start.getHours() !== 0 || start.getMinutes() !== 0) : !!block.time;
  return (
    <section className="block-countdown" aria-label={title || 'Countdown'}>
      {title && <h3 className="block-countdown-title">{title}</h3>}
      {phase === 'before' && block.starts_at ? (
        <>
          <div className="block-countdown-parts" role="timer" aria-live="off">
            {countdownParts(Date.parse(block.starts_at) - now).map((p) => (
              <span key={p.unit} className="block-countdown-part">
                <span className="block-countdown-value">{p.value}</span>
                <span className="block-countdown-unit">{unitLabel(p.value, p.unit)}</span>
              </span>
            ))}
          </div>
          <p className="block-countdown-when muted small">{formatStart(block.starts_at, withTime)}</p>
        </>
      ) : (
        <p className="block-countdown-text">{text}</p>
      )}
    </section>
  );
}
