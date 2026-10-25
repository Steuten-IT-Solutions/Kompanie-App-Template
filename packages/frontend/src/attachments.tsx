// Dateianhänge am Schwarzen Brett und an E-Mails aus der Verwaltung: Auswahl und Hochladen (als Entwurf auf dem Server,
// gespeichert erst mit der Nachricht bzw. Mail) und die Liste zum Herunterladen.

import { useRef, useState } from 'react';
import { api, type Attachment } from './api';
import { formatBytes } from './format';

let limit: Promise<number> | null = null;
/** Höchstgröße je Datei, einmal je Sitzung geladen. */
function maxBytes(): Promise<number> {
  limit ??= api
    .attachmentLimits()
    .then((r) => r.max_bytes)
    .catch((err) => {
      limit = null;
      throw err;
    });
  return limit;
}

/** Anhänge zum Herunterladen (immer als Datei, auch Bilder und PDFs). */
export function AttachmentList({ items }: { items: Attachment[] | undefined }) {
  if (!items || items.length === 0) return null;
  return (
    <ul className="attachment-list" aria-label="Anhänge">
      {items.map((a) => (
        <li key={a.uid}>
          <a href={a.url} download={a.name} className="attachment-link">
            <span className="attachment-icon" aria-hidden="true">
              📎
            </span>
            <span className="attachment-name">{a.name}</span>
            <span className="muted small"> ({formatBytes(a.size)})</span>
          </a>
        </li>
      ))}
    </ul>
  );
}

/**
 * Anhänge beim Schreiben: angehängte Dateien mit „×“, darunter „Datei anhängen“ (mehrere auf einmal). Jede Datei wird
 * gleich hochgeladen; zu große meldet die App vorher, ohne sie zu senden.
 */
export function AttachmentsField({
  items,
  onAdd,
  onRemove,
  id,
}: {
  items: Attachment[];
  onAdd: (a: Attachment) => void;
  onRemove: (uid: string) => void;
  id: string;
}) {
  const input = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [errors, setErrors] = useState<string[]>([]);

  async function add(files: FileList | null) {
    if (!files || files.length === 0) return;
    const list = Array.from(files);
    if (input.current) input.current.value = '';
    const problems: string[] = [];
    let max: number | null = null;
    try {
      max = await maxBytes();
    } catch {
      // ohne Grenze vorab: der Server prüft ohnehin
    }
    for (const file of list) {
      if (max !== null && file.size > max) {
        problems.push(`„${file.name}“ ist ${formatBytes(file.size)} groß; Anhänge dürfen höchstens ${formatBytes(max)} groß sein.`);
        continue;
      }
      setBusy(file.name);
      try {
        onAdd(await api.uploadAttachment(file));
      } catch (err) {
        problems.push(`„${file.name}“: ${(err as Error).message}`);
      }
    }
    setBusy(null);
    setErrors(problems);
  }

  return (
    <div className="field attachments-field">
      <span id={`${id}-label`}>Anhänge</span>
      {items.length > 0 && (
        <ul className="attachment-list attachment-list-edit" aria-labelledby={`${id}-label`}>
          {items.map((a) => (
            <li key={a.uid}>
              <span className="attachment-icon" aria-hidden="true">
                📎
              </span>
              <span className="attachment-name">{a.name}</span>
              <span className="muted small"> ({formatBytes(a.size)})</span>
              <button type="button" className="attachment-remove" aria-label={`${a.name} entfernen`} onClick={() => onRemove(a.uid)}>
                ×
              </button>
            </li>
          ))}
        </ul>
      )}
      <label className={`btn btn-secondary attachment-add ${busy ? 'disabled' : ''}`}>
        <input
          ref={input}
          id={id}
          type="file"
          multiple
          className="visually-hidden"
          disabled={busy !== null}
          onChange={(e) => void add(e.target.files)}
        />
        {busy ? `Lädt „${busy}“ …` : 'Datei anhängen'}
      </label>
      {errors.map((e) => (
        <p key={e} className="form-error small">
          {e}
        </p>
      ))}
    </div>
  );
}
