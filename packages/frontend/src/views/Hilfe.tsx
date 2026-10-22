import { useMemo, useState } from 'react';
import { can, config, levelLabel, type Capability, type Feature } from '../api';
import brand from '../generated/brand';
import handbuch from '../generated/handbuch.json';
import { navigate } from '../router';
import { Empty } from '../ui';
import FehlerMelden, { ReportButton } from './FehlerMelden';

interface Seite {
  slug: string;
  titel: string;
  gruppe: string;
  rechte: string[];
  bereiche: string[];
  variante: string | null;
  html: string;
}

const GRUPPEN = ['Für alle', 'Für Mitglieder', 'Für Aufgaben in der Verwaltung', 'Für Admins'];

const escape = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
/** Text einer Seite für die Suche: ohne Tags, Zeichen wie & und " wieder als Zeichen. */
const plain = (html: string) =>
  html
    .replace(/<[^>]+>/g, ' ')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#0?39;|&apos;/g, "'")
    .replace(/&amp;/g, '&');

/** Bezeichnungen der Kompanie für die Platzhalter im Handbuch (scripts/handbuch.php). */
function labels(): Record<string, string> {
  const sec = brand.labels.sections;
  return {
    'kompanie.name': brand.name,
    'bereich.events': sec.events,
    'bereich.calendar': 'Kalender',
    'bereich.news': sec.news,
    'bereich.gallery': sec.gallery,
    'bereich.points': sec.points,
    'bereich.penalties': sec.penalties,
    'bereich.ranks': sec.ranks,
    'bereich.titles': sec.titles,
    'bereich.attendance': sec.attendance,
    'bereich.documents': sec.documents,
    'bereich.board': sec.board,
    'bereich.polls': sec.polls,
    'bereich.birthdays': sec.birthdays,
    'bereich.inventory': sec.inventory,
    'bereich.directory': sec.directory,
    'bereich.offices': sec.offices,
    'begriff.rank': brand.labels.rank,
    'rolle.guest': levelLabel('guest'),
    'rolle.member': levelLabel('member'),
    'rolle.admin': levelLabel('admin'),
  };
}

/** Platzhalter ersetzen; html ist schon maskiert, die Bezeichnungen werden es hier. */
function fill(text: string, map: Record<string, string>, html: boolean): string {
  return text.replace(/\{([a-z]+\.[a-z]+)\}/g, (m, key: string) => (key in map ? (html ? escape(map[key]) : map[key]) : m));
}

/** Seiten, die zu Rechten, eingeschalteten Bereichen und Variante passen. */
function visible(): Seite[] {
  return (handbuch.seiten as Seite[]).filter(
    (s) =>
      s.rechte.every((r) => can(r as Capability)) &&
      s.bereiche.every((b) => !!config?.features[b as Feature]) &&
      (s.variante === null || s.variante === config?.variant),
  );
}

/**
 * Hilfe (Issue #12): das Handbuch in der App, passend zur installierten Version, zu den Rechten,
 * den eingeschalteten Bereichen und zur Variante; offline verfügbar. Das ganze Handbuch steht
 * zusätzlich auf der Webseite der Kompanie (GitHub Pages, /handbuch/).
 */
export default function Hilfe({ slug }: { slug?: string }) {
  const map = useMemo(labels, []);
  const seiten = useMemo(visible, []);
  const [suche, setSuche] = useState('');
  const seite = slug ? seiten.find((s) => s.slug === slug) : undefined;

  // „Fehler melden“ (Issue #64): #/hilfe/melden (keine Handbuchseite heißt so)
  if (slug === 'melden') return <FehlerMelden />;

  if (seite) {
    return (
      <article className="hilfe-seite">
        <button className="btn btn-link back" onClick={() => navigate('hilfe')}>
          ‹ Alle Hilfeseiten
        </button>
        <h2 className="news-headline">{fill(seite.titel, map, false)}</h2>
        <div className="hilfe-text" dangerouslySetInnerHTML={{ __html: fill(seite.html, map, true) }} />
        <ReportButton />
      </article>
    );
  }

  const q = suche.trim().toLowerCase();
  const treffer = q
    ? seiten.filter((s) => (fill(s.titel, map, false) + ' ' + plain(fill(s.html, map, false))).toLowerCase().includes(q))
    : seiten;

  return (
    <div className="hilfe">
      <input
        type="search"
        className="search"
        placeholder="Hilfe durchsuchen …"
        aria-label="Hilfe durchsuchen"
        value={suche}
        onChange={(e) => setSuche(e.target.value)}
      />
      {treffer.length === 0 && <Empty>Nichts gefunden.</Empty>}
      {GRUPPEN.map((g) => {
        const liste = treffer.filter((s) => s.gruppe === g);
        if (liste.length === 0) return null;
        return (
          <section key={g}>
            <h2 className="section-title">{g}</h2>
            <ul className="card-list">
              {liste.map((s) => (
                <li key={s.slug} className="card row clickable" onClick={() => navigate(`hilfe/${s.slug}`)}>
                  <div className="row-main">
                    <div className="row-title">{fill(s.titel, map, false)}</div>
                  </div>
                </li>
              ))}
            </ul>
          </section>
        );
      })}
      <ReportButton />
      {config?.handbookUrl && (
        <p className="small muted hilfe-web">
          Das ganze Handbuch, auch für Bereiche, die eure Kompanie nicht nutzt:{' '}
          <a href={config.handbookUrl} target="_blank" rel="noopener noreferrer">
            Handbuch im Browser öffnen
          </a>
        </p>
      )}
    </div>
  );
}
