import { Suspense, useCallback, useEffect, useState, type ReactNode } from 'react';
import { api, can, type DocumentInfo, type DocVisibility } from '../api';
import brand from '../generated/brand';
import {
  DOC_SORT_LABELS,
  docGroupKey,
  groupDocuments,
  groupKey,
  loadDocSort,
  saveDocSort,
  type DocGroup,
  type DocSort,
} from '../documents';
import { formatBytes, formatDate } from '../format';
import { detectPlatform } from '../install';
import { pruneDocumentCache, whenControlled } from '../offline';
import { navigate } from '../router';
import { Empty, ErrorBox, lazyView, Loading, useAsync } from '../ui';

const PdfViewer = lazyView(() => import('./PdfViewer'));

/** Zuletzt angezeigtes Dokument: Seine Kategorie ist beim Zurückkehren zur Liste aufgeklappt. */
let lastOpened: number | null = null;

/** Dateitypen für die Auswahl beim Hochladen (prüft auch der Server). */
export const ACCEPT = '.pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp';

const TYPE_LABELS: Record<string, string> = {
  pdf: 'PDF',
  jpg: 'Bild',
  jpeg: 'Bild',
  png: 'Bild',
  doc: 'Word',
  docx: 'Word',
  odt: 'Text',
  xls: 'Excel',
  xlsx: 'Excel',
  ods: 'Tabelle',
  ppt: 'PowerPoint',
  pptx: 'PowerPoint',
  odp: 'Präsentation',
};

export const typeLabel = (doc: DocumentInfo) => TYPE_LABELS[doc.ext] ?? doc.ext.toUpperCase();

export const VISIBILITY_LABELS: Record<DocVisibility, string> = {
  members: 'Alle Mitglieder',
  managers: `Nur wer ${brand.labels.sections.documents} pflegt`,
};

/** Gewählte Sortierung, auf dem Gerät gemerkt (für Mitgliederansicht und „Dokumente verwalten“ gemeinsam). */
export function useDocSort() {
  const [sort, setSort] = useState<DocSort>(loadDocSort);
  const change = useCallback((next: DocSort) => {
    setSort(next);
    saveDocSort(next);
  }, []);
  return [sort, change] as const;
}

/** Aufgeklappte Kategorien. Beim Öffnen des Bereichs sind alle zu; gespeichert wird das nicht. */
export function useOpenGroups() {
  const [open, setOpen] = useState<ReadonlySet<string>>(() => new Set());
  const toggle = useCallback(
    (key: string) =>
      setOpen((o) => {
        const next = new Set(o);
        if (!next.delete(key)) next.add(key);
        return next;
      }),
    [],
  );
  const show = useCallback((key: string) => setOpen((o) => (o.has(key) ? o : new Set(o).add(key))), []);
  return { isOpen: (key: string) => open.has(key), toggle, show };
}

export function DocSortSelect({ value, onChange }: { value: DocSort; onChange: (sort: DocSort) => void }) {
  return (
    // Kein umschließendes <label>: dessen Text enthielte sonst alle Optionen („Stand neu → alt“ …).
    <div className="field doc-sort">
      <span id="doc-sort-label">Sortierung</span>
      <select aria-labelledby="doc-sort-label" value={value} onChange={(e) => onChange(e.target.value as DocSort)}>
        {(Object.keys(DOC_SORT_LABELS) as DocSort[]).map((s) => (
          <option key={s} value={s}>
            {DOC_SORT_LABELS[s]}
          </option>
        ))}
      </select>
    </div>
  );
}

/**
 * Eine Kategorie als einklappbarer Abschnitt: Kopf mit Name und Anzahl, z. B. „Protokolle (12)“.
 * Gibt es gar keine Kategorien (Gruppe ohne Titel), steht die Liste direkt da.
 */
export function DocGroupSection({
  group,
  open,
  onToggle,
  children,
}: {
  group: DocGroup;
  open: boolean;
  onToggle: () => void;
  children: ReactNode;
}) {
  if (!group.title) return <section className="doc-group">{children}</section>;
  const bodyId = `doc-group-${groupKey(group)}`;
  return (
    <section className="doc-group">
      <h3 className="section-title doc-group-title">
        <button type="button" className="doc-group-toggle" aria-expanded={open} aria-controls={bodyId} onClick={onToggle}>
          {group.title} ({group.docs.length})
          <span className="doc-group-arrow" aria-hidden="true">
            ›
          </span>
        </button>
      </h3>
      <div id={bodyId} className="doc-group-body" hidden={!open}>
        {children}
      </div>
    </section>
  );
}

export function DocIcon({ kind }: { kind: DocumentInfo['kind'] }) {
  const paths = {
    pdf: 'M6 2h8l6 6v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm7 1.5V9h5.5L13 3.5zM8 13v1.5h8V13H8zm0 3v1.5h8V16H8zm0-6v1.5h4V10H8z',
    image:
      'M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zm1 11.5V17h14v-2.5l-4-4-3.5 3.5-2-2L5 16.5zM8.5 7a1.75 1.75 0 1 0 0 3.5 1.75 1.75 0 0 0 0-3.5z',
    file: 'M6 2h8l6 6v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm7 1.5V9h5.5L13 3.5zM11 11v4.2l-1.6-1.6-1.1 1.1L12 18.4l3.7-3.7-1.1-1.1-1.6 1.6V11h-2z',
  };
  return (
    <svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true">
      <path fill="currentColor" d={paths[kind]} />
    </svg>
  );
}

/** Lädt die Datei über den Service Worker – danach liegt sie offline im Cache. */
async function fetchFile(doc: DocumentInfo): Promise<Blob> {
  await whenControlled();
  let res: Response;
  try {
    res = await fetch(doc.url, { credentials: 'same-origin' });
  } catch {
    throw new Error('Keine Verbindung und noch nicht offline gespeichert.');
  }
  if (!res.ok) throw new Error(res.status === 404 ? 'Das Dokument wurde nicht gefunden.' : `Fehler ${res.status}`);
  return res.blob();
}

/** Liegt die Datei schon im Cache des Service Workers (also offline verfügbar)? */
async function isCached(doc: DocumentInfo): Promise<boolean> {
  try {
    return 'caches' in window && !!(await caches.match(doc.url));
  } catch {
    return false;
  }
}

/** iPhone: über „Teilen“ in Dateien sichern; sonst normaler Download. */
async function download(doc: DocumentInfo): Promise<void> {
  const blob = await fetchFile(doc);
  const name = `${doc.title.replace(/[\\/:*?"<>|]+/g, ' ').trim() || 'Dokument'}.${doc.ext}`;
  const file = new File([blob], name, { type: doc.mime });
  if (detectPlatform() === 'ios' && navigator.canShare?.({ files: [file] })) {
    try {
      await navigator.share({ files: [file], title: doc.title });
      return;
    } catch (e) {
      if ((e as Error).name === 'AbortError') return;
      // Teilen nicht erlaubt → normaler Download.
    }
  }
  const href = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = href;
  a.download = name;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(href), 60_000);
}

/** Bild in der App anzeigen (über fetch, damit es offline verfügbar wird). */
function ImageView({ doc }: { doc: DocumentInfo }) {
  const [src, setSrc] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    let url: string | null = null;
    let alive = true;
    fetchFile(doc)
      .then((blob) => {
        url = URL.createObjectURL(blob);
        if (alive) setSrc(url);
      })
      .catch((e: Error) => alive && setError(e.message));
    return () => {
      alive = false;
      if (url) URL.revokeObjectURL(url);
    };
  }, [doc]);
  if (error) return <p className="error">{error}</p>;
  if (!src) return <Loading />;
  return <img className="pdf-page" src={src} alt={doc.title} />;
}

export default function Dokumente({ docId, onToast }: { docId?: number; onToast: (msg: string) => void }) {
  const { data, error, loading, reload } = useAsync(api.documents);
  const [offline, setOffline] = useState<Record<number, boolean>>({});
  const [busy, setBusy] = useState<number | null>(null);
  const [sort, setSort] = useDocSort();
  const { isOpen, toggle, show } = useOpenGroups();

  // Die Kategorie des geöffneten Dokuments ist beim Zurückkehren zur Liste aufgeklappt. Die Ansicht wird beim
  // Wechsel der Adresse neu aufgebaut; deshalb merkt sich das Modul, welches Dokument zuletzt offen war.
  useEffect(() => {
    if (docId) {
      lastOpened = docId;
      return;
    }
    const doc = lastOpened ? data?.documents.find((d) => d.id === lastOpened) : undefined;
    if (doc) {
      show(docGroupKey(doc.category_id, data?.categories ?? []));
      lastOpened = null;
    }
  }, [data, docId, show]);

  // Nur Dokumente aus der aktuellen Liste offline behalten (gelöscht, nicht mehr sichtbar, ältere Fassung).
  useEffect(() => {
    if (data) void pruneDocumentCache(data.documents.map((d) => d.url));
  }, [data]);

  useEffect(() => {
    let alive = true;
    for (const doc of data?.documents ?? []) {
      isCached(doc).then((ok) => alive && ok && setOffline((o) => ({ ...o, [doc.id]: true })));
    }
    return () => {
      alive = false;
    };
    // Auch nach dem Zurückkehren aus der Anzeige: Geöffnetes liegt jetzt im Cache.
  }, [data, docId]);

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const docs = data?.documents ?? [];

  if (docId) {
    const open = docs.find((d) => d.id === docId);
    if (!open) return <Empty>Dieses Dokument gibt es nicht (mehr).</Empty>;
    return (
      <div>
        <button className="btn btn-link back" onClick={() => navigate('dokumente')}>
          ‹ Alle Dokumente
        </button>
        <h2 className="member-name">{open.title}</h2>
        <p className="muted small doc-stand">Stand: {formatDate(open.date)}</p>
        {open.kind === 'pdf' ? (
          <Suspense fallback={<Loading />}>
            <PdfViewer url={open.url} title={open.title} />
          </Suspense>
        ) : open.kind === 'image' ? (
          <ImageView doc={open} />
        ) : (
          <Empty>Dieses Dokument lässt sich nur herunterladen.</Empty>
        )}
      </div>
    );
  }

  async function save(doc: DocumentInfo) {
    setBusy(doc.id);
    try {
      await download(doc);
      if (await isCached(doc)) setOffline((o) => ({ ...o, [doc.id]: true }));
    } catch (e) {
      onToast((e as Error).message);
    } finally {
      setBusy(null);
    }
  }

  const groups = groupDocuments(docs, data?.categories ?? [], sort);

  return (
    <div>
      {can('manage_documents') && (
        <button className="btn btn-secondary btn-block manage-events" onClick={() => navigate('dokumente/verwalten')}>
          {brand.labels.sections.documents} verwalten
        </button>
      )}
      {docs.length === 0 ? <Empty>Es sind noch keine Dokumente hinterlegt.</Empty> : <DocSortSelect value={sort} onChange={setSort} />}
      {groups.map((g) => (
        <DocGroupSection key={groupKey(g)} group={g} open={isOpen(groupKey(g))} onToggle={() => toggle(groupKey(g))}>
          <ul className="card-list">
            {g.docs.map((doc) => (
              <li key={doc.id} className="card doc-card">
                <div className="doc-head">
                  <span className={`doc-icon doc-icon-${doc.kind}`}>
                    <DocIcon kind={doc.kind} />
                  </span>
                  <div className="row-main">
                    <div className="row-title">
                      {doc.title}
                      {doc.visibility === 'managers' && <span className="badge badge-level-guest">Eingeschränkt</span>}
                    </div>
                    <div className="row-sub">
                      Stand: {formatDate(doc.date)} · {typeLabel(doc)}, {formatBytes(doc.size)}
                    </div>
                    {offline[doc.id] && <div className="doc-offline">✓ Auch offline verfügbar</div>}
                  </div>
                </div>
                <div className={doc.kind === 'file' ? 'button-row single' : 'button-row'}>
                  {doc.kind !== 'file' && (
                    <button className="btn" onClick={() => navigate(`dokumente/${doc.id}`)}>
                      Öffnen
                    </button>
                  )}
                  <button className="btn btn-secondary" disabled={busy === doc.id} onClick={() => save(doc)}>
                    Herunterladen
                  </button>
                </div>
              </li>
            ))}
          </ul>
        </DocGroupSection>
      ))}
    </div>
  );
}
