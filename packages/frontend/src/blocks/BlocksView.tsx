import type {
  AlbumBlock,
  Block,
  ButtonBlock,
  ContactsBlock,
  DocumentBlock,
  EventsBlock,
  FactsBlock,
  MapBlock,
  NoticeBlock,
  PollBlock,
  ScheduleBlock,
  SponsorsBlock,
} from '../api';
import { useState } from 'react';
import { mapLinkTarget, mapPlatform, mapSearchUrl } from '../address';
import { safeFileName } from '../downloadFiles';
import { formatBytes, formatDate, formatUntil } from '../format';
import { saveFile } from '../saveFile';
import { MemberAvatar } from '../memberPhoto';
import { formatEventWhen } from '../format';
import { officeSingular } from '../officeName';
import RichTextView from '../richtext/RichTextView';
import Countdown from './Countdown';
import { t } from '../i18n';

/**
 * Inhalt aus Blöcken (eigene Seiten, News, Schwarzes Brett): jeder Block mit seiner Anzeige. Unbekannte Typen (Daten eines neueren Servers)
 * übergeht die App; wer pflegt, sieht einen Hinweis.
 */
export default function BlocksView({ blocks, manager, textClassName }: { blocks: Block[]; manager?: boolean; textClassName?: string }) {
  return (
    <div className="blocks">
      {blocks.map((b) => {
        const members =
          manager && b.audience === 'members' ? <span className="badge badge-level-guest block-badge">{t('nur Mitglieder')}</span> : null;
        let view;
        switch (b.type) {
          case 'text':
            view = <RichTextView html={b.html} className={textClassName} />;
            break;
          case 'countdown':
            view = <Countdown block={b} />;
            break;
          case 'button':
            view = <LinkButton block={b} />;
            break;
          case 'events':
            view = <NextEvents block={b} />;
            break;
          case 'contacts':
            view = <Contacts block={b} />;
            break;
          case 'notice':
            view = <Notice block={b} />;
            break;
          case 'schedule':
            view = <Schedule block={b} />;
            break;
          case 'facts':
            view = <Facts block={b} />;
            break;
          case 'poll':
            view = <PollCard block={b} />;
            break;
          case 'album':
            view = <AlbumPreview block={b} />;
            break;
          case 'document':
            view = <DocumentCard block={b} />;
            break;
          case 'sponsors':
            view = <SponsorsRow block={b} />;
            break;
          case 'map':
            view = <MapPlace block={b} />;
            break;
          case 'divider':
            view = b.style === 'space' ? <div className="block-space" aria-hidden="true" /> : <hr className="block-divider" />;
            break;
          default:
            view = manager ? <p className="muted small">{t('Dieser Block wird von dieser Version der App nicht unterstützt.')}</p> : null;
        }
        if (!view) return null;
        return (
          <div key={(b as { id: string }).id} className={`block-item block-item-${(b as { type: string }).type}`}>
            {members}
            {view}
          </div>
        );
      })}
    </div>
  );
}

/** Knopf mit Link: Seiten der App im selben Fenster, Webseiten in einem neuen. */
function LinkButton({ block }: { block: ButtonBlock }) {
  const external = block.url.startsWith('https://');
  return (
    <p className="block-button">
      <a
        className={`btn${block.style === 'secondary' ? ' btn-secondary' : ''}`}
        href={block.url}
        {...(external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
      >
        {block.label}
      </a>
    </p>
  );
}

/** Nächste Termine (vom Server, immer aktuell), mit Link zu allen Terminen. */
function NextEvents({ block }: { block: EventsBlock }) {
  return (
    <section className="block-events">
      {block.title && <h3>{block.title}</h3>}
      {block.events.length === 0 ? (
        <p className="muted">{t('Zurzeit steht nichts an.')}</p>
      ) : (
        <ul className="block-events-list">
          {block.events.map((e) => (
            <li key={e.id}>
              <span className="block-events-when">{formatEventWhen(e)}</span>
              <strong className="block-events-title">{e.title}</strong>
              {e.location && <span className="muted small"> · {e.location}</span>}
            </li>
          ))}
        </ul>
      )}
      <a className="btn btn-link block-events-all" href="#/termine">
        {t('Alle Termine')}
      </a>
    </section>
  );
}

/** Ansprechpartner: je Amt die Inhaber mit Foto; E-Mail und Telefon nur, wenn der Server sie liefert (view_personal). */
function Contacts({ block }: { block: ContactsBlock }) {
  return (
    <section className="block-contacts">
      {block.title && <h3>{block.title}</h3>}
      {block.offices.length === 0 ? (
        <p className="muted">{t('Zurzeit keine Ansprechpartner.')}</p>
      ) : (
        <ul className="block-contacts-list">
          {block.offices.flatMap((o) =>
            (o.holders.length === 0 ? [null] : o.holders).map((h, i) => (
              <li key={`${o.id}-${i}`} className="block-contact">
                {h && <MemberAvatar url={h.photo_url} name={h.name} size="medium" />}
                <span className="block-contact-text">
                  <span className="muted small">{officeSingular(o)}</span>
                  <strong>{h ? h.name : t('zurzeit nicht besetzt')}</strong>
                  {h?.email && <a href={`mailto:${h.email}`}>{h.email}</a>}
                  {h?.phone && <a href={`tel:${h.phone.replace(/\s+/g, '')}`}>{h.phone}</a>}
                </span>
              </li>
            )),
          )}
        </ul>
      )}
    </section>
  );
}

/** Hinweis-Kasten in der Farbe seiner Art. */
function Notice({ block }: { block: NoticeBlock }) {
  return (
    <aside className={`block-notice block-notice-${block.tone}`} role="note">
      {block.title && <strong className="block-notice-title">{block.title}</strong>}
      <p>{block.text}</p>
    </aside>
  );
}

/** Ablauf: Zeit links, Programmpunkt rechts. */
function Schedule({ block }: { block: ScheduleBlock }) {
  return (
    <section className="block-schedule">
      {block.title && <h3>{block.title}</h3>}
      <dl className="block-schedule-list">
        {block.items.map((i, n) => (
          <div key={n} className="block-schedule-item">
            <dt>{i.time}</dt>
            <dd>{i.text}</dd>
          </div>
        ))}
      </dl>
    </section>
  );
}

/** Zahlen und Fakten nebeneinander (auf schmalen Geräten umbrechend). */
function Facts({ block }: { block: FactsBlock }) {
  return (
    <section className="block-facts">
      {block.title && <h3>{block.title}</h3>}
      <ul className="block-facts-list">
        {block.items.map((i, n) => (
          <li key={n}>
            <strong className="block-facts-value">{i.value}</strong>
            <span className="block-facts-label">{i.label}</span>
          </li>
        ))}
      </ul>
    </section>
  );
}

/** Umfrage: Titel und Stand mit dem Weg zum Abstimmen bzw. zur Antwort. */
function PollCard({ block }: { block: PollBlock }) {
  const p = block.poll;
  if (!p) return <p className="muted small">{t('Diese Umfrage gibt es nicht mehr.')}</p>;
  return (
    <section className="block-poll card">
      <span className="muted small">
        {t('Umfrage · {status}', {
          status: p.status === 'ended' ? t('beendet') : p.ends_at ? t('bis {date}', { date: formatUntil(p.ends_at) }) : t('läuft'),
        })}
      </span>
      <strong className="block-poll-title">{p.title}</strong>
      <a className={`btn${p.has_voted || p.status === 'ended' ? ' btn-secondary' : ''}`} href={`#/umfragen/${p.id}`}>
        {p.has_voted || p.status === 'ended' ? t('Antwort und Ergebnis ansehen') : t('Teilnehmen')}
      </a>
    </section>
  );
}

/** Album: einige Vorschaubilder, ein Tipp öffnet das Album in der Galerie. */
function AlbumPreview({ block }: { block: AlbumBlock }) {
  const a = block.album;
  if (!a) return <p className="muted small">{t('Dieses Album gibt es nicht mehr oder es ist leer.')}</p>;
  return (
    <section className="block-album">
      <h3>{a.title}</h3>
      <a className="block-album-grid" href={`#/galerie/${a.id}`} aria-label={t('Album {title} öffnen', { title: a.title })}>
        {a.photos.map((p) => (
          <img key={p.id} src={p.thumb} alt="" loading="lazy" width={p.width} height={p.height} />
        ))}
      </a>
      <a className="btn btn-link" href={`#/galerie/${a.id}`}>
        {t('Alle {count} im Album ansehen', { count: a.total })}
      </a>
    </section>
  );
}

/** Dokument: Titel, Art, Größe und Stand; Öffnen in der App (PDF, Bild) und Herunterladen. */
function DocumentCard({ block }: { block: DocumentBlock }) {
  const d = block.document;
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  if (!d) return <p className="muted small">{t('Dieses Dokument gibt es nicht mehr oder es ist nicht für alle sichtbar.')}</p>;
  const doc = d;
  async function save() {
    setBusy(true);
    setError(null);
    try {
      const res = await fetch(doc.url, { credentials: 'same-origin' });
      if (!res.ok)
        throw new Error(res.status === 404 ? t('Das Dokument wurde nicht gefunden.') : t('Fehler {status}', { status: res.status }));
      await saveFile(await res.blob(), `${safeFileName(doc.title, 'Dokument')}.${doc.ext}`, { title: doc.title });
    } catch (e) {
      setError(e instanceof TypeError ? t('Keine Verbindung.') : (e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <section className="block-document card">
      <strong>{d.title}</strong>
      <span className="muted small">
        {d.ext.toUpperCase()} · {formatBytes(d.size)} · {t('Stand {date}', { date: formatDate(d.date) })}
      </span>
      <div className="button-row">
        {d.kind !== 'file' && (
          <a className="btn" href={`#/dokumente/${d.id}`}>
            {t('Öffnen')}
          </a>
        )}
        <button className="btn btn-secondary" disabled={busy} onClick={() => void save()}>
          {t('Herunterladen')}
        </button>
      </div>
      {error && (
        <p className="form-error small" role="alert">
          {error}
        </p>
      )}
    </section>
  );
}

/** Sponsoren: Logos (ohne Logo der Name) in ihrer Reihenfolge, mit Link in einem neuen Tab. */
function SponsorsRow({ block }: { block: SponsorsBlock }) {
  if (block.sponsors.length === 0) return <p className="muted small">{t('Noch keine Einträge.')}</p>;
  return (
    <section className="block-sponsors">
      {block.title && <h3>{block.title}</h3>}
      <ul className="block-sponsors-list">
        {block.sponsors.map((s) => {
          const logo = s.logo_url ? (
            <img className="sponsor-logo" src={s.logo_url} alt={s.name} loading="lazy" decoding="async" />
          ) : (
            <span className="sponsor-name">{s.name}</span>
          );
          return (
            <li key={s.id}>
              {s.url ? (
                <a href={s.url} target="_blank" rel="sponsored noopener noreferrer" title={s.name}>
                  {logo}
                </a>
              ) : (
                logo
              )}
            </li>
          );
        })}
      </ul>
    </section>
  );
}

/** Karte zur Anfahrt: Ort und ein Knopf, der ihn in der Karten-App zeigt (wie beim Ort eines Termins). */
function MapPlace({ block }: { block: MapBlock }) {
  const map = mapSearchUrl(block.place, mapPlatform());
  return (
    <section className="block-map card">
      {block.title && <h3>{block.title}</h3>}
      <p className="block-map-place">{block.place}</p>
      {map && (
        <a className="btn btn-secondary" href={map} {...mapLinkTarget(map)}>
          {t('Karte')}
        </a>
      )}
    </section>
  );
}
