import { Suspense, useState, type FormEvent } from 'react';
import { api, ApiError, type PageAudience, type PageDetail, type PageSummary } from '../api';
import { useDragSort, useSaveQueue } from '../dragSort';
import { formatShortTime } from '../format';
import brand from '../generated/brand';
import { Empty, ErrorBox, lazyView, Loading, useAsync } from '../ui';
import { sources } from '../viewData';
import { AudienceBadge } from './Seiten';
import { blocksInput, draftsFrom, newBlock, type BlockDraft } from '../blocks/blockDrafts';

const BlocksEditor = lazyView(() => import('../blocks/BlocksEditor'));

const sec = brand.labels.sections;

/** Grenze wie auf dem Server (PageService::TITLE_MAX). */
const TITLE_MAX = 100;

type Draft = { title: string; audience: PageAudience; blocks: BlockDraft[] };
/** Neue Seiten beginnen mit einem leeren Textblock. */
const empty = (): Draft => ({ title: '', audience: 'members', blocks: [newBlock('text')] });

function PageFields({
  draft,
  onChange,
  idPrefix,
  autoFocus,
}: {
  draft: Draft;
  onChange: (d: Draft) => void;
  idPrefix: string;
  autoFocus?: boolean;
}) {
  const set = (patch: Partial<Draft>) => onChange({ ...draft, ...patch });
  return (
    <>
      <label className="field">
        <span>Titel</span>
        <input
          type="text"
          maxLength={TITLE_MAX}
          value={draft.title}
          onChange={(e) => set({ title: e.target.value })}
          required
          autoFocus={autoFocus}
        />
      </label>
      <label className="field">
        <span>Für wen?</span>
        <select value={draft.audience} onChange={(e) => set({ audience: e.target.value as PageAudience })}>
          <option value="members">Nur Mitglieder</option>
          <option value="public">Alle, auch ohne Anmeldung</option>
        </select>
      </label>
      <div className="field">
        <span>Inhalt</span>
        <Suspense fallback={<Loading shape="text" chunk />}>
          <BlocksEditor value={draft.blocks} onChange={(blocks) => set({ blocks })} idPrefix={idPrefix} />
        </Suspense>
      </div>
    </>
  );
}

/** Eine Seite bearbeiten: lädt den Text und den Stand; hat jemand dazwischen gespeichert, sagt der Server 409 conflict. */
function PageEditor({ id, onDone, onToast }: { id: number; onDone: (saved: boolean) => void; onToast: (msg: string) => void }) {
  const [loaded, setLoaded] = useState<PageDetail | null>(null);
  const [draft, setDraft] = useState<Draft>(empty);
  const [editorKey, setEditorKey] = useState(0);
  const { error, reload } = useAsync(
    () =>
      api.page(id).then((p) => {
        setLoaded(p);
        setDraft({ title: p.title, audience: p.audience, blocks: draftsFrom(p.blocks, p.body) });
        setEditorKey((k) => k + 1);
        return p;
      }),
    [id],
  );
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [conflict, setConflict] = useState(false);

  async function save(e: FormEvent) {
    e.preventDefault();
    if (!loaded) return;
    setBusy(true);
    setFormError(null);
    try {
      await api.updatePage(id, {
        title: draft.title.trim(),
        audience: draft.audience,
        blocks: blocksInput(draft.blocks),
        updated_at: loaded.updated_at,
      });
      onToast('Gespeichert');
      onDone(true);
    } catch (err) {
      setConflict(err instanceof ApiError && err.code === 'conflict');
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  if (error) return <ErrorBox message={error} onRetry={reload} />;
  if (!loaded) return <Loading shape="form" />;
  return (
    <form className="form" onSubmit={save}>
      <PageFields key={editorKey} draft={draft} onChange={setDraft} idPrefix={`page-${id}`} autoFocus />
      {formError && (
        <p className="form-error" role="alert">
          {formError}
        </p>
      )}
      <div className="button-row">
        <button className="btn" type="submit" disabled={busy || conflict}>
          Speichern
        </button>
        {conflict && (
          <button
            className="btn btn-secondary"
            type="button"
            onClick={() => {
              if (!window.confirm('Neu laden? Deine Änderungen gehen dabei verloren.')) return;
              setConflict(false);
              setFormError(null);
              setLoaded(null);
              reload();
            }}
          >
            Neu laden
          </button>
        )}
        <button className="btn btn-secondary" type="button" onClick={() => onDone(false)}>
          Abbrechen
        </button>
      </div>
    </form>
  );
}

/**
 * Verwaltung → Einrichtungen → Seiten (Issue #287): eigene Seiten anlegen, bearbeiten (Titel, für wen, Inhalt aus Blöcken),
 * Reihenfolge per Griff, löschen.
 */
export function PageAdmin({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(sources.pages.load, sources.pages.deps, sources.pages.key);
  const [draft, setDraft] = useState<Draft>(empty);
  const [newKey, setNewKey] = useState(0);
  const [editing, setEditing] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  async function run(action: () => Promise<unknown>, msg: string) {
    setBusy(true);
    setFormError(null);
    try {
      await action();
      onToast(msg);
      reload();
      return true;
    } catch (e) {
      setFormError((e as Error).message);
      return false;
    } finally {
      setBusy(false);
    }
  }

  async function add(e: FormEvent) {
    e.preventDefault();
    if (
      await run(
        () => api.createPage({ title: draft.title.trim(), audience: draft.audience, blocks: blocksInput(draft.blocks) }),
        'Seite angelegt',
      )
    ) {
      setDraft(empty());
      setNewKey((k) => k + 1);
    }
  }

  const saveOrder = useSaveQueue();
  function reorder(next: PageSummary[]) {
    setData(next);
    setFormError(null);
    saveOrder(
      () => api.reorderPages(next.map((p) => p.id)),
      setData,
      (e) => {
        setFormError(e.message);
        reload();
      },
    );
  }

  const sort = useDragSort(data ?? [], reorder, editing !== null);

  if (loading && !data) return <Loading />;
  if (error || !data) return <ErrorBox message={error ?? `Die ${sec.pages} konnten nicht geladen werden.`} onRetry={reload} />;
  const list = data;

  return (
    <div>
      <p className="muted small">
        Jede Seite ist ein eigener Punkt der Navigation, in der Standardnavigation in dieser Reihenfolge; zum Ändern einen Eintrag am Griff
        links ziehen. Wo jede Seite steht, legt ihr unter Einstellungen → Navigation fest.
      </p>
      {formError && (
        <p className="form-error" role="alert">
          {formError}
        </p>
      )}
      {list.length === 0 ? (
        <Empty>Noch keine {sec.pages}.</Empty>
      ) : (
        <ul className="card-list page-admin-list">
          {list.map((p, i) =>
            editing === p.id ? (
              <li key={p.id} {...sort.itemProps(i, 'card')}>
                <PageEditor
                  id={p.id}
                  onToast={onToast}
                  onDone={(saved) => {
                    setEditing(null);
                    if (saved) reload();
                  }}
                />
              </li>
            ) : (
              <li key={p.id} {...sort.itemProps(i, 'card row')}>
                {list.length > 1 && sort.handle(i, p.title)}
                <div className="row-main">
                  <div className="row-title">
                    {p.title} <AudienceBadge page={p} />
                  </div>
                  {p.updated_at && (
                    <div className="row-sub">
                      Zuletzt geändert {formatShortTime(p.updated_at)}
                      {p.updated_by_name ? ` von ${p.updated_by_name}` : ''}
                    </div>
                  )}
                  <div className="row-actions">
                    <button className="btn btn-small btn-secondary" disabled={busy} onClick={() => setEditing(p.id)}>
                      Bearbeiten
                    </button>
                    <button
                      className="btn btn-small btn-danger"
                      disabled={busy}
                      onClick={() =>
                        window.confirm(`Seite „${p.title}“ samt ihren Bildern löschen?`) && run(() => api.deletePage(p.id), 'Gelöscht')
                      }
                    >
                      Löschen
                    </button>
                  </div>
                </div>
              </li>
            ),
          )}
        </ul>
      )}

      <form className="form card" onSubmit={add}>
        <h3>Neue Seite</h3>
        <PageFields key={newKey} draft={draft} onChange={setDraft} idPrefix="page-new" />
        <button className="btn btn-block" type="submit" disabled={busy}>
          Anlegen
        </button>
      </form>
    </div>
  );
}
