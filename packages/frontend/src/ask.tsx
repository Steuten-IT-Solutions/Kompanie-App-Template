import { useEffect, useRef, useState } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { Sheet } from './ui';

/**
 * Rückfragen der App im eigenen Fenster (Sheet) statt mit window.confirm/prompt/alert (Issue #342): Die Fenster des
 * Browsers sehen auf jedem Gerät anders aus, lassen sich nicht gestalten und heben sich je nach System kaum ab.
 * Aufruf wie bisher, nur mit await: `if (!(await confirmAsk('Wirklich löschen?'))) return;`
 */

interface Request {
  kind: 'confirm' | 'prompt' | 'alert';
  message: string;
  value: string;
  title: string;
  okLabel: string;
  danger: boolean;
  resolve: (value: string | boolean | null) => void;
}

export interface AskOptions {
  /** Überschrift des Fensters; ohne Angabe „Bitte bestätigen“, „Eingabe“ bzw. „Hinweis“. */
  title?: string;
  /** Beschriftung des Knopfs zum Bestätigen; ohne Angabe „OK“. */
  okLabel?: string;
}

let root: Root | null = null;
let show: ((r: Request) => void) | null = null;
const waiting: Request[] = [];

function open(r: Request): void {
  if (show) {
    show(r);
    return;
  }
  waiting.push(r);
  if (!root) {
    const el = document.createElement('div');
    el.className = 'ask-root';
    document.body.appendChild(el);
    root = createRoot(el);
    root.render(<AskHost />);
  }
}

/** Ja/Nein-Frage; true bei „OK“, false bei „Abbrechen“, Schließen oder Esc. Löschen u. ä. erkennt die App am Text. */
export function confirmAsk(message: string, options: AskOptions = {}): Promise<boolean> {
  return new Promise((resolve) =>
    open({
      kind: 'confirm',
      message,
      value: '',
      title: options.title ?? 'Bitte bestätigen',
      okLabel: options.okLabel ?? 'OK',
      danger: /lösch|entfern|zurückzieh|stornier|verwerf|trenn|leeren/i.test(message),
      resolve: (v) => resolve(v === true),
    }),
  );
}

/** Texteingabe; der eingegebene Text oder null bei „Abbrechen“. */
export function promptAsk(message: string, value = '', options: AskOptions = {}): Promise<string | null> {
  return new Promise((resolve) =>
    open({
      kind: 'prompt',
      message,
      value,
      title: options.title ?? 'Eingabe',
      okLabel: options.okLabel ?? 'OK',
      danger: false,
      resolve: (v) => resolve(typeof v === 'string' ? v : null),
    }),
  );
}

/** Hinweis mit einem Knopf „OK“. */
export function alertAsk(message: string, options: AskOptions = {}): Promise<void> {
  return new Promise((resolve) =>
    open({
      kind: 'alert',
      message,
      value: '',
      title: options.title ?? 'Hinweis',
      okLabel: options.okLabel ?? 'OK',
      danger: false,
      resolve: () => resolve(),
    }),
  );
}

function AskHost() {
  const [queue, setQueue] = useState<Request[]>(() => waiting.splice(0));
  useEffect(() => {
    show = (r) => setQueue((q) => [...q, r]);
    return () => {
      show = null;
    };
  }, []);
  const current = queue[0];
  if (!current) return null;
  const done = (value: string | boolean | null) => {
    current.resolve(value);
    setQueue((q) => q.slice(1));
  };
  return <AskDialog key={queue.length + current.message} request={current} onDone={done} />;
}

function AskDialog({ request, onDone }: { request: Request; onDone: (value: string | boolean | null) => void }) {
  const [value, setValue] = useState(request.value);
  const input = useRef<HTMLInputElement>(null);
  const ok = useRef<HTMLButtonElement>(null);
  useEffect(() => {
    // Nach dem Fenster selbst (es setzt den Fokus auf „Schließen“): Eingabefeld bzw. Knopf zum Bestätigen
    const t = window.setTimeout(() => {
      if (input.current) {
        input.current.focus();
        input.current.select();
      } else ok.current?.focus();
    }, 0);
    return () => window.clearTimeout(t);
  }, []);
  const cancel = () => onDone(request.kind === 'confirm' ? false : null);
  return (
    <Sheet top title={request.title} onClose={request.kind === 'alert' ? () => onDone(null) : cancel}>
      <form
        className="ask-dialog"
        onSubmit={(e) => {
          e.preventDefault();
          onDone(request.kind === 'prompt' ? value : true);
        }}
      >
        <p className="ask-message">{request.message}</p>
        {request.kind === 'prompt' && (
          <input
            ref={input}
            type="text"
            className="ask-input"
            aria-label={request.message}
            value={value}
            onChange={(e) => setValue(e.target.value)}
          />
        )}
        <div className="ask-actions">
          {request.kind !== 'alert' && (
            <button type="button" className="btn btn-secondary" onClick={cancel}>
              Abbrechen
            </button>
          )}
          <button ref={ok} type="submit" className={request.danger ? 'btn btn-danger-solid' : 'btn'}>
            {request.okLabel}
          </button>
        </div>
      </form>
    </Sheet>
  );
}
