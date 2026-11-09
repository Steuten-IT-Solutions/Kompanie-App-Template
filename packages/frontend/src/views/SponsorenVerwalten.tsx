import { useRef, useState, type FormEvent } from 'react';
import { api, type Sponsor } from '../api';
import { useDragSort, useSaveQueue } from '../dragSort';
import brand from '../generated/brand';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';
import { sources } from '../viewData';
import { SponsorLogo } from './Sponsoren';
import { confirmAsk } from '../ask';

const sec = brand.labels.sections;

/** Logo: erlaubte Formate und Höchstgröße wie auf dem Server (SponsorService). */
const LOGO_ACCEPT = '.png,.jpg,.jpeg,.webp,.svg';
const LOGO_MAX_BYTES = 2 * 1024 * 1024;
const NAME_MAX = 100;

/** Felder Name und Link (beim Anlegen und Bearbeiten). */
function SponsorFields({
  name,
  url,
  onName,
  onUrl,
  autoFocus,
}: {
  name: string;
  url: string;
  onName: (v: string) => void;
  onUrl: (v: string) => void;
  autoFocus?: boolean;
}) {
  return (
    <>
      <label className="field">
        <span>Name</span>
        <input type="text" maxLength={NAME_MAX} value={name} onChange={(e) => onName(e.target.value)} required autoFocus={autoFocus} />
      </label>
      <label className="field">
        <span>Link (optional)</span>
        <input type="url" inputMode="url" placeholder="https://" value={url} onChange={(e) => onUrl(e.target.value)} />
      </label>
    </>
  );
}

/**
 * Verwaltung → Einrichtungen → Sponsoren (Issue #279): anlegen, Name und Link ändern, Logo hochladen oder entfernen,
 * Reihenfolge per Griff, löschen. Die Reihenfolge gilt für die Ansicht Sponsoren.
 */
export function SponsorAdmin({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(sources.sponsors.load, sources.sponsors.deps, sources.sponsors.key);
  const [name, setName] = useState('');
  const [url, setUrl] = useState('');
  const [editing, setEditing] = useState<number | null>(null);
  const [editName, setEditName] = useState('');
  const [editUrl, setEditUrl] = useState('');
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const logoInput = useRef<HTMLInputElement>(null);
  const logoFor = useRef<number | null>(null);

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
    if (await run(() => api.createSponsor({ name: name.trim(), url: url.trim() }), 'Sponsor angelegt')) {
      setName('');
      setUrl('');
    }
  }

  function chooseLogo(id: number) {
    logoFor.current = id;
    setFormError(null);
    logoInput.current?.click();
  }

  async function uploadLogo(file: File | null) {
    const id = logoFor.current;
    if (logoInput.current) logoInput.current.value = '';
    if (!file || id === null) return;
    const ext = file.name.toLowerCase().split('.').pop() ?? '';
    if (!['png', 'jpg', 'jpeg', 'webp', 'svg'].includes(ext)) {
      setFormError('Erlaubt sind PNG, JPG, WebP und SVG.');
      return;
    }
    if (file.size > LOGO_MAX_BYTES) {
      setFormError('Das Logo darf höchstens 2 MB groß sein.');
      return;
    }
    await run(() => api.uploadSponsorLogo(id, file), 'Logo gespeichert');
  }

  const saveOrder = useSaveQueue();
  function reorder(next: Sponsor[]) {
    // Gleich in der neuen Reihenfolge zeigen, damit der Eintrag nach dem Loslassen nicht zurückspringt
    setData(next);
    setFormError(null);
    saveOrder(
      () => api.reorderSponsors(next.map((s) => s.id)),
      setData,
      (e) => {
        setFormError(e.message);
        reload();
      },
    );
  }

  const sort = useDragSort(data ?? [], reorder, editing !== null);

  if (loading && !data) return <Loading />;
  if (error || !data) return <ErrorBox message={error ?? 'Die Einträge konnten nicht geladen werden.'} onRetry={reload} />;
  const list = data;

  return (
    <div>
      {formError && (
        <p className="form-error" role="alert">
          {formError}
        </p>
      )}
      {list.length === 0 ? (
        <Empty>Noch keine {sec.sponsors}.</Empty>
      ) : (
        <ul className="card-list sponsor-admin-list">
          {list.map((s, i) =>
            editing === s.id ? (
              <li key={s.id} {...sort.itemProps(i, 'card')}>
                <form
                  className="form"
                  onSubmit={async (e) => {
                    e.preventDefault();
                    if (await run(() => api.updateSponsor(s.id, { name: editName.trim(), url: editUrl.trim() }), 'Gespeichert'))
                      setEditing(null);
                  }}
                >
                  <SponsorFields name={editName} url={editUrl} onName={setEditName} onUrl={setEditUrl} autoFocus />
                  <div className="button-row">
                    <button className="btn" type="submit" disabled={busy}>
                      Speichern
                    </button>
                    <button className="btn btn-secondary" type="button" onClick={() => setEditing(null)}>
                      Abbrechen
                    </button>
                  </div>
                </form>
              </li>
            ) : (
              <li key={s.id} {...sort.itemProps(i, 'card row')}>
                {list.length > 1 && sort.handle(i, s.name)}
                <div className="sponsor-admin-logo">
                  <SponsorLogo sponsor={s} />
                </div>
                <div className="row-main">
                  <div className="row-title">{s.name}</div>
                  <div className="row-sub">{s.url || 'ohne Link'}</div>
                  <div className="row-actions">
                    <button
                      className="btn btn-small btn-secondary"
                      disabled={busy}
                      onClick={() => {
                        setEditName(s.name);
                        setEditUrl(s.url);
                        setEditing(s.id);
                      }}
                    >
                      Bearbeiten
                    </button>
                    <button className="btn btn-small btn-secondary" disabled={busy} onClick={() => chooseLogo(s.id)}>
                      {s.logo_url ? 'Logo ersetzen' : 'Logo hochladen'}
                    </button>
                    {s.logo_url && (
                      <button
                        className="btn btn-small btn-secondary"
                        disabled={busy}
                        onClick={async () =>
                          (await confirmAsk(`Logo von „${s.name}“ entfernen?`)) && run(() => api.removeSponsorLogo(s.id), 'Logo entfernt')
                        }
                      >
                        Logo entfernen
                      </button>
                    )}
                    <button
                      className="btn btn-small btn-danger"
                      disabled={busy}
                      onClick={async () => (await confirmAsk(`„${s.name}“ löschen?`)) && run(() => api.deleteSponsor(s.id), 'Gelöscht')}
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

      <input
        ref={logoInput}
        type="file"
        accept={LOGO_ACCEPT}
        hidden
        aria-label="Logo-Datei"
        onChange={(e) => uploadLogo(e.target.files?.[0] ?? null)}
      />

      <form className="form card" onSubmit={add}>
        <h3>Neuer Sponsor</h3>
        <SponsorFields name={name} url={url} onName={setName} onUrl={setUrl} />
        <button className="btn btn-block" type="submit" disabled={busy}>
          Anlegen
        </button>
      </form>
    </div>
  );
}
