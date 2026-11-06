import { Suspense, useCallback, useEffect, useRef, useState } from 'react';
import { api, ApiError, can, config, CONFIG_CHANGED, toLogin, newsTitle, type Capability } from './api';
import { caps } from './format';
import brand from './generated/brand';
import logoUrl from './generated/logo-small.webp';
import { detectPlatform, isStandalone, promptInstall, useCanPrompt } from './install';
import BoardBanner from './BoardBanner';
import { boardVisible, canManagePolls, canPostBoard, pollsVisible } from './board';
import { ErrorBoundary, lazyView, Loading } from './ui';
import { birthdaysVisible } from './upcomingBirthdays';
import { forgetPushOnLogout } from './push';
import { endPreview, previewRole, PUBLIC_PREVIEW, stopPreview } from './preview';
import { navigate, useRoute, viewKey } from './router';
import { focusId } from './focus';
import { AdminBack, adminTabs } from './adminNav';
import { MenuBack, InternMenu } from './internNav';
import { targetLabels } from './navLabels';
import { buildNav, isPageTarget, menuOf, pageTarget, type NavEntry, type NavTarget } from './navigation';
import { helpSlug } from './helpSlug';
import { setReportOrigin } from './lastError';
import { shareLink, shareUrl } from './share';
import { toggleTheme, useTheme } from './theme';
import News from './views/News';
import Termine from './views/Termine';
import { hasEvents, prefetcher, tabSources, type TabId } from './viewData';
import { whenIdle } from './prefetch';

// Erst bei Bedarf laden: Der App-Start braucht nur die Ansichten, mit denen Gäste meist beginnen (Termine, News).
// Der Service Worker speichert alle Teile trotzdem vorab, damit sie auch offline da sind.
const Anmelden = lazyView(() => import('./views/Anmelden'));
const Registrieren = lazyView(() => import('./views/Registrieren'));
const Galerie = lazyView(() => import('./views/Galerie'));
const GalerieEinreichungen = lazyView(() => import('./views/GalerieEinreichungen'));
const Kompanien = lazyView(() => import('./views/Kompanien'));
const Sponsoren = lazyView(() => import('./views/Sponsoren'));
const Seiten = lazyView(() => import('./views/Seiten'));
const Meins = lazyView(() => import('./views/Meins'));
const MaintenancePage = lazyView(() => import('./maintenance').then((m) => ({ default: m.MaintenancePage })));
const MaintenanceBanner = lazyView(() => import('./maintenance').then((m) => ({ default: m.MaintenanceBanner })));
const Einrichtung = lazyView(() => import('./views/Einrichtung'));
const Einstellungen = lazyView(() => import('./views/Einstellungen'));
const Installieren = lazyView(() => import('./views/Installieren'));
const TermineVerwalten = lazyView(() => import('./views/TermineVerwalten'));
const Verwaltung = lazyView(() => import('./views/Verwaltung'));
const Hilfe = lazyView(() => import('./views/Hilfe'));
const Benachrichtigungen = lazyView(() => import('./views/Benachrichtigungen'));
const Dokumente = lazyView(() => import('./views/Dokumente'));
const DokumenteVerwalten = lazyView(() => import('./views/DokumenteVerwalten'));
const Brett = lazyView(() => import('./views/Brett'));
const Umfragen = lazyView(() => import('./views/Umfragen'));
const Inventar = lazyView(() => import('./views/Inventar'));
const Mitglieder = lazyView(() => import('./views/Mitglieder'));
const Dienstgrade = lazyView(() => import('./views/Dienstgrade'));
const Punktekatalog = lazyView(() => import('./views/Punktekatalog'));

// Programmteile der Tabs, die erst bei Bedarf geladen werden: vorab anstoßen (Issue #104).
// Untermenüs (Intern oder eingerichtet) laden die Teile ihrer Bereiche (preloadEntry).
const tabViews: Partial<Record<string, { preload: () => void }>> = {
  galerie: Galerie,
  kompanien: Kompanien,
  sponsoren: Sponsoren,
  seiten: Seiten,
  dokumente: Dokumente,
  mitglieder: Mitglieder,
  brett: Brett,
  dienstgrade: Dienstgrade,
  punktekatalog: Punktekatalog,
  meins: Meins,
  verwaltung: Verwaltung,
};

/** Bereiche unter „Intern“ (Knöpfe auf seiner Startseite) bzw. in Untermenüs der einrichtbaren Navigation
 * (navigation.ts, Issue #277). In der Standardnavigation stehen ohne das Recht view_intern Mitglieder und Dokumente unten
 * als eigene Tabs (z. B. Mitglieder für Gäste); Schwarzes Brett, Dienstgrade und Punktekatalog gibt es dort nur unter
 * „Intern“ (Mitglieder haben das Recht immer; das Brett erreicht man sonst über das Banner). */
type InternView = 'mitglieder' | 'dokumente' | 'brett' | 'dienstgrade' | 'punktekatalog';
type Tab = 'termine' | 'news' | 'galerie' | 'kompanien' | 'sponsoren' | 'seiten' | 'intern' | InternView | 'meins' | 'verwaltung';
type View =
  | Tab
  | 'menue'
  | 'brett'
  | 'umfragen'
  | 'inventar'
  | 'benachrichtigungen'
  | 'installieren'
  | 'anmelden'
  | 'registrieren'
  | 'einstellungen'
  | 'einrichtung'
  | 'hilfe';

const VIEWS: View[] = [
  'termine',
  'news',
  'galerie',
  'kompanien',
  'sponsoren',
  'seiten',
  'intern',
  'menue',
  'dokumente',
  'mitglieder',
  'dienstgrade',
  'punktekatalog',
  'meins',
  'verwaltung',
  'brett',
  'umfragen',
  'inventar',
  'benachrichtigungen',
  'installieren',
  'anmelden',
  'registrieren',
  'einstellungen',
  'einrichtung',
  'hilfe',
];

/** Ältere Links weiter unterstützen. */
const ALIASES: Record<string, string[]> = {
  punkte: ['meins', 'punkte'],
  strafen: ['meins', 'strafen'],
  spiess: ['verwaltung'],
  // Das Konto steht unter „Meins“.
  konto: ['meins', 'konto'],
};

const features = config?.features;
const sec = brand.labels.sections;

const tabLabels = (): Record<Tab | 'menue', string> => ({
  termine: sec.events,
  news: sec.news,
  galerie: sec.gallery,
  kompanien: sec.companies,
  sponsoren: sec.sponsors,
  seiten: sec.pages,
  intern: 'Intern',
  // Untermenü der einrichtbaren Navigation: Titel ist sein Name (App)
  menue: 'Menü',
  ...internLabels(),
  meins: 'Meins',
  verwaltung: 'Verwaltung',
});

function internLabels(): Record<InternView, string> {
  return {
    // Nur Ämter ohne Mitgliederliste (Issue #112): Der Bereich heißt dann wie die Ämter.
    mitglieder: features?.directory || !features?.offices ? sec.directory : sec.offices,
    dokumente: sec.documents,
    brett: sec.board,
    dienstgrade: sec.ranks,
    punktekatalog: `${sec.points}katalog`,
  };
}

/** Programmteile eines Tabs vorab laden; bei einem Untermenü die seiner Bereiche. */
function preloadEntry(e: NavEntry) {
  (e.children ?? [e.key]).forEach((k) => tabViews[isPageTarget(k) ? 'seiten' : k]?.preload());
}

const titles = (): Record<View, string> => ({
  ...tabLabels(),
  news: newsTitle(),
  brett: sec.board,
  umfragen: sec.polls,
  inventar: sec.inventory,
  benachrichtigungen: 'Benachrichtigungen',
  installieren: 'App installieren',
  anmelden: 'Anmelden',
  registrieren: 'Zugang beantragen',
  einstellungen: 'Einstellungen',
  einrichtung: 'Einrichtung',
  hilfe: 'Hilfe',
});

/** Kopfzeile: zwischen hell und dunkel wechseln (feste Wahl und „Wie Gerät“ unter Meins → Konto). */
function ThemeButton() {
  const dark = useTheme().shown === 'dark';
  const label = dark ? 'Helle Darstellung' : 'Dunkle Darstellung';
  return (
    <button className="btn btn-link topbar-theme" onClick={toggleTheme} aria-label={label} title={label}>
      <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
        {dark ? (
          <path
            fill="currentColor"
            d="M12 7a5 5 0 1 1 0 10 5 5 0 0 1 0-10zm-1-6h2v3h-2V1zm0 19h2v3h-2v-3zM1 11h3v2H1v-2zm19 0h3v2h-3v-2zM4.22 5.64l1.42-1.42 2.12 2.12-1.42 1.42-2.12-2.12zm12.02 12.02 1.42-1.42 2.12 2.12-1.42 1.42-2.12-2.12zM4.22 18.36l2.12-2.12 1.42 1.42-2.12 2.12-1.42-1.42zM16.24 6.34l2.12-2.12 1.42 1.42-2.12 2.12-1.42-1.42z"
          />
        ) : (
          <path fill="currentColor" d="M20.5 14.6A8.5 8.5 0 0 1 9.4 3.5a8.5 8.5 0 1 0 11.1 11.1z" />
        )}
      </svg>
    </button>
  );
}

function Icon({ name }: { name: string }) {
  const paths: Record<string, string> = {
    termine: 'M7 2v2H5a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2V2h-2v2H9V2H7zm-2 7h14v10H5V9z',
    news: 'M4 4h13v14a2 2 0 0 0 2 2H6a2 2 0 0 1-2-2V4zm15 4h1a1 1 0 0 1 1 1v9a2 2 0 0 1-4 0V8h2zM7 7v4h7V7H7zm0 6v1.5h7V13H7zm0 3v1.5h7V16H7z',
    galerie:
      'M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zm1 11.5V17h14v-2.5l-4-4-3.5 3.5-2-2L5 16.5zM8.5 7a1.75 1.75 0 1 0 0 3.5 1.75 1.75 0 0 0 0-3.5z',
    // Schild: Kompanien
    kompanien: 'M12 2 4 5v6c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V5l-8-3zm0 2.2 6 2.2V11c0 3.9-2.5 7.6-6 8.9V4.2z',
    // Herz: Sponsoren unterstützen die Kompanie
    sponsoren:
      'M12 21l-1.5-1.3C5.4 15.1 2 12 2 8.3 2 5.3 4.3 3 7.3 3c1.7 0 3.4.8 4.7 2.1C13.3 3.8 15 3 16.7 3 19.7 3 22 5.3 22 8.3c0 3.7-3.4 6.8-8.5 11.4L12 21z',
    // Blatt mit Zeilen: eigene Seiten
    seiten: 'M5 2h10l5 5v15H5V2zm9 1.5V8h4.5L14 3.5zM8 11v1.5h9V11H8zm0 3.5V16h9v-1.5H8zm0 3.5v1.5h5V18H8z',
    dokumente:
      'M6 2h8l6 6v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm7 1.5V9h5.5L13 3.5zM8 13v1.5h8V13H8zm0 3v1.5h8V16H8zm0-6v1.5h4V10H8z',
    mitglieder:
      'M8 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zm8.5 0a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM8 13c-3 0-7 1.5-7 4.5V20h14v-2.5C15 14.5 11 13 8 13zm8.5 0c-.6 0-1.3.1-2 .2 1.4 1 2.5 2.4 2.5 4.3V20h6v-2.5c0-3-3.9-4.5-6.5-4.5z',
    brett: 'M5 4h14a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zm2 4v2h10V8H7zm0 4v2h6v-2H7z',
    dienstgrade: 'M12 3 4 8.5v3.5L12 6.5l8 5.5V8.5L12 3zm0 7-8 5.5V19l8-5.5 8 5.5v-3.5L12 10z',
    punktekatalog: 'M4 5h2v2H4V5zm4 0h12v2H8V5zm-4 6h2v2H4v-2zm4 0h12v2H8v-2zm-4 6h2v2H4v-2zm4 0h12v2H8v-2z',
    intern: 'M3 6a2 2 0 0 1 2-2h5l2 2h7a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6zm2 4v8h14v-8H5z',
    meins: 'M12 2l2.9 6.3 6.9.7-5.2 4.6 1.5 6.8L12 17l-6.1 3.4 1.5-6.8L2.2 9l6.9-.7L12 2z',
    verwaltung:
      'M12 2l8 3v6c0 5-3.4 9.4-8 11-4.6-1.6-8-6-8-11V5l8-3zm0 5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zm-4 9.5c.8-1.6 2.3-2.5 4-2.5s3.2.9 4 2.5',
  };
  // Symbole der Untermenüs (Einstellungen → Navigation): Ordner wie „Intern“, Stern wie „Meins“
  paths.ordner = paths.intern;
  paths.stern = paths.meins;
  return (
    <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true">
      <path d={paths[name] ?? paths.intern} fill="currentColor" />
    </svg>
  );
}

/** Banner „App aufs Handy holen“: auf Android ein Knopf, sonst Link zur bebilderten Anleitung. */
function InstallBanner({ onToast }: { onToast: (msg: string) => void }) {
  const key = 'kompanie-install-hint-dismissed';
  const canPrompt = useCanPrompt();
  const [hidden, setHidden] = useState(() => {
    try {
      return isStandalone() || localStorage.getItem(key) === '1';
    } catch {
      return isStandalone();
    }
  });
  // Am Computer nur anzeigen, wenn der Browser die Installation direkt anbietet.
  if (hidden || (!canPrompt && detectPlatform() === 'other')) return null;
  const dismiss = () => {
    try {
      localStorage.setItem(key, '1');
    } catch {
      /* egal */
    }
    setHidden(true);
  };
  async function install() {
    const result = await promptInstall();
    if (result === 'accepted') onToast('Die App wird installiert – gleich ist sie auf dem Startbildschirm');
  }
  return (
    <div className="install-hint" role="note">
      <img src={logoUrl} alt="" className="install-hint-logo" />
      <div className="install-hint-text">
        <strong>Die App aufs Handy holen</strong>
        <span>Mit unserem Logo auf dem Startbildschirm – wie jede andere App.</span>
      </div>
      {canPrompt ? (
        <button className="btn btn-small install-hint-btn" onClick={install}>
          Installieren
        </button>
      ) : (
        <button className="btn btn-small install-hint-btn" onClick={() => navigate('installieren')}>
          So geht's
        </button>
      )}
      <button className="btn btn-link install-hint-close" onClick={dismiss} aria-label="Hinweis schließen">
        ✕
      </button>
    </div>
  );
}

/**
 * Vorschau „App als Rolle ansehen“ (Issue #63): auf jeder Seite oben, mit „Beenden“ (zurück zu Einstellungen → Rollen).
 * Änderungen lehnt der Server in der Vorschau ab; die Knöpfe bleiben, wie die Rolle sie sieht.
 */
function PreviewBanner() {
  const p = config?.preview;
  if (!p) return null;
  return (
    <div className="preview-banner" role="status">
      <span>
        <strong>{p.role === PUBLIC_PREVIEW ? 'Vorschau ohne Anmeldung' : `Vorschau als ${p.label}`}</strong> – nur ansehen
      </span>
      <button className="btn btn-small" onClick={endPreview}>
        Beenden
      </button>
    </div>
  );
}

/** Beim Abmelden: alle gespeicherten Daten löschen. Die Programmdateien bleiben (sie enthalten keine Daten), damit die
 * App weiter offline startet. */
async function clearCaches() {
  if ('caches' in window) {
    const keys = await caches.keys();
    await Promise.all(keys.filter((k) => !k.startsWith('kompanie-static-')).map((k) => caches.delete(k)));
  }
}

/**
 * Abmelden. Im Formular-Modus erst auf dem Server: Klappt das nicht (z. B. offline),
 * bleibt alles, wie es ist – sonst sähe die App abgemeldet aus, die Sitzung bestünde aber weiter.
 */
async function logout(onError: (msg: string) => void) {
  // In der Vorschau: erst beenden, sonst lehnte der Server das Abmelden der Benachrichtigungen ab.
  if (previewRole()) stopPreview();
  // Solange die Sitzung noch gilt: Dieses Gerät bekommt keine Nachrichten des Kontos mehr.
  const finishPush = await forgetPushOnLogout();
  if (config?.auth.mode === 'form') {
    try {
      await api.logout();
    } catch (err) {
      // 401: Die Sitzung ist auf dem Server schon beendet.
      if (!(err instanceof ApiError && err.status === 401)) {
        await finishPush(false);
        onError('Abmelden hat nicht geklappt. Bitte mit Internetverbindung erneut versuchen.');
        return;
      }
    }
  }
  await finishPush(true);
  await clearCaches().catch(() => undefined);
  if (config?.auth.mode === 'redirect' && config.auth.logoutUrl) {
    window.location.href = config.auth.logoutUrl;
  } else {
    window.location.href = config?.appUrl ?? './';
  }
}

export function greeting(name: string): string {
  // Als Funktion, damit $-Zeichen im Namen nicht als Ersetzungsmuster gelten.
  return brand.greeting.replace('{name}', () => name);
}

export default function App({ notice = null }: { notice?: string | null }) {
  const rawPath = useRoute();
  const path = ALIASES[rawPath[0]] ?? rawPath;
  const user = config?.user ?? null;
  // Jeder Knopf mit eigenem Recht (wie die Routen im Kern); ohne Anmeldung die Rechte von Gast (can).
  const docsAllowed = !!features?.documents && (can('view_documents') || can('manage_documents'));
  // Mitgliederliste, Ehemalige und Ämter (Issue #112) unter einem Knopf.
  const membersAllowed = !!(
    (features?.directory && (can('view_directory') || can('view_former'))) ||
    (features?.offices && can('view_offices'))
  );
  // Dienstgrade und Punktekatalog zum Nachschlagen.
  const ranksAllowed = !!features?.ranks && can('view_ranks');
  const catalogAllowed = !!features?.points && can('view_catalog');
  const pageIds = new Set((config?.pages ?? []).map((p) => p.id));
  const allowed: Record<View, boolean> = {
    // Geburtstage (Issue #1) stehen bei den Terminen: für alle, die sie sehen, auch wenn es sonst keine Termine gibt.
    termine: hasEvents() || birthdaysVisible(),
    news: !!features?.news && can('view_news'),
    galerie: !!features?.gallery && (can('view_gallery') || can('view_gallery_internal')),
    // Kompanien (Issue #280): mit view_companies sehen, mit manage_companies (Verwaltung) ohnehin
    kompanien: !!features?.companies && (can('view_companies') || can('manage_companies')),
    // Sponsoren (Issue #279): mit view_sponsors sehen, mit manage_sponsors (Verwaltung) ohnehin
    sponsoren: !!features?.sponsors && (can('view_sponsors') || can('manage_sponsors')),
    // Eigene Seiten (Issue #287): #/seiten/<id>, wenn es eine gibt, die die Person lesen darf; wer sie pflegt, ohnehin
    seiten: !!features?.pages && ((config?.pages?.length ?? 0) > 0 || can('manage_pages')),
    dokumente: docsAllowed,
    mitglieder: membersAllowed,
    dienstgrade: ranksAllowed,
    punktekatalog: catalogAllowed,
    // Intern bzw. ein Untermenü: unten aus der Navigation bestimmt
    intern: false,
    menue: false,
    // Eigene Daten (Mitglieder) und das Konto (alle Angemeldeten)
    meins: !!user,
    // Verwaltung: Mitgliederliste und Pflege (Punkte, Strafen, Dienstgrade …), dazu Schwarzes Brett, Umfragen und Inventar.
    verwaltung: adminTabs().length > 0,
    // Schwarzes Brett (Issue #5): mit view_board lesen, mit post_board schreiben.
    brett: boardVisible() || canPostBoard(),
    // Umfragen (Issue #4): mit vote_polls antworten, manage_polls verwaltet; laufende stehen am Brett.
    umfragen: pollsVisible() || canManagePolls(),
    // Inventar (Issue #6): nur mit manage_inventory, Einstieg über das Menü der Verwaltung.
    inventar: !!features?.inventory && can('manage_inventory'),
    benachrichtigungen: true,
    installieren: true,
    anmelden: !user && config?.auth.mode === 'form',
    // Zugang beantragen (Issue #314): auch der Link aus der Mail (bestaetigen/<token>) und der Stand der Anfrage
    registrieren: !!config?.registration || path[1] === 'bestaetigen' || path[1] === 'stand',
    einstellungen: can('manage_settings'),
    einrichtung: can('manage_settings'),
    hilfe: true,
  };
  // Tab-Leiste: eingerichtet (Einstellungen → Navigation, Issue #277) oder die Standardnavigation; nur, was die Person
  // sehen darf. Das Brett zum Lesen (view_board); wer schreibt, hat es außerdem in der Verwaltung.
  const navEntries = buildNav(config?.navigation, {
    // Eigene Seiten (Issue #287): jede, die die Person lesen darf (/config), als eigener Punkt
    visible: (t: NavTarget) =>
      t === 'brett' ? boardVisible() : isPageTarget(t) ? allowed.seiten && pageIds.has(Number(t.slice(7))) : allowed[t],
    intern: can('view_intern'),
    label: (t: NavTarget) => targetLabels()[t] ?? t,
    pages: (config?.pages ?? []).map((p) => pageTarget(p.id)),
  });
  allowed.intern = navEntries.some((e) => e.key === 'intern');
  // „Meins“ ganz rechts, die Verwaltung davor.
  const fixed: NavEntry[] = (['verwaltung', 'meins'] as const)
    .filter((t) => allowed[t])
    .map((t) => ({ key: t, label: tabLabels()[t], icon: t, children: null }));
  const tabs: NavEntry[] = [...navEntries, ...fixed];
  const requested = path[0] as View;
  allowed.menue = requested === 'menue' && navEntries.some((e) => e.key === `menue/${path[1]}`);
  // Neue Installation ohne Bereiche: Der Admin landet direkt in der Einrichtung.
  const start: string = tabs[0]?.key ?? (config?.setupPending && allowed.einrichtung ? 'einrichtung' : 'installieren');
  const valid = VIEWS.includes(requested) && allowed[requested];
  const tab: View = valid ? requested : (start.split('/')[0] as View);
  // Untermenü (Intern oder eingerichtet), das gerade offen ist bzw. in dem der Bereich steht
  const menuKey = valid ? (tab === 'intern' ? 'intern' : `menue/${path[1]}`) : start;
  const openMenu = tab === 'intern' || tab === 'menue' ? navEntries.find((e) => e.key === menuKey) : undefined;
  // Schwarzes Brett, Umfragen, Inventar und Einstellungen gehören für alle, die sie pflegen, zur Verwaltung (Knopf dort, Tab unten).
  const adminView = ['brett', 'umfragen', 'inventar', 'einstellungen'].includes(tab) && adminTabs().some((t) => t.id === tab) ? tab : null;
  // „‹ Verwaltung“ nur auf der Seite, zu der der Knopf führt; Unterseiten haben ihren eigenen Rückweg.
  // brett/<id> (Ziel einer Benachrichtigung) ist dieselbe Seite wie brett.
  const adminStart = !!adminView && adminTabs().find((t) => t.id === adminView)?.route === (tab === 'brett' ? 'brett' : path.join('/'));
  // Die Bereiche eines Untermenüs („Intern“ oder eingerichtet) gehören zu diesem Tab unten; „‹ Intern“ bzw. „‹ <Name>“
  // auf ihrer ersten Seite, Unterseiten (ein Dokument, eine Person, Dokumente verwalten) haben ihren eigenen Rückweg.
  // Wer am Brett schreibt, hat es in der Verwaltung (adminView), auch wenn er es über das Untermenü öffnet.
  // Eine eigene Seite ist ihr eigener Punkt der Navigation (seiten/<id>).
  const navKey: string = tab === 'seiten' && /^\d+$/.test(path[1] ?? '') ? pageTarget(Number(path[1])) : tab;
  const parentMenu = adminView ? undefined : menuOf(navEntries, navKey);
  const menuStart =
    tab === 'brett' ||
    tab === 'dienstgrade' ||
    tab === 'punktekatalog' ||
    tab === 'seiten' ||
    (['termine', 'news', 'galerie', 'kompanien', 'sponsoren', 'dokumente'].includes(tab) && !path[1]) ||
    (tab === 'mitglieder' && !/^\d+$/.test(path[1] ?? '') && !path[2]);
  const activeTab: string = adminView ? 'verwaltung' : (parentMenu?.key ?? openMenu?.key ?? navKey);
  // Gast öffnet einen geteilten Link auf eine Seite für Angemeldete: Hinweis; nach der Anmeldung geht sie auf (share.ts).
  const canLogin = config?.auth.mode === 'form' || !!config?.auth.loginUrl;
  const loginFirst = !user && canLogin && VIEWS.includes(requested) && requested !== 'anmelden' && !allowed[requested];
  // Gäste: Hinweis „Zugang beantragen“ (Issue #314) auf der ersten Seite und bei Seiten, die eine Anmeldung brauchen
  const canRegister = !user && !!config?.registration;

  const [toast, setToast] = useState<string | null>(notice);
  const [online, setOnline] = useState(navigator.onLine);
  const showToast = useCallback((msg: string) => setToast(msg), []);
  // Bereiche wurden ein- oder ausgeschaltet: Tabs und Ansichten neu bestimmen.
  const [, setConfigVersion] = useState(0);
  useEffect(() => {
    const changed = () => setConfigVersion((v) => v + 1);
    window.addEventListener(CONFIG_CHANGED, changed);
    return () => window.removeEventListener(CONFIG_CHANGED, changed);
  }, []);

  // Daten eines Tabs; „Intern“ zeigt zuerst nur seine Knöpfe, geladen werden die Bereiche dahinter.
  const sourcesOf = (t: NavEntry) => (t.children ? t.children.flatMap((v) => tabSources(v)) : tabSources(t.key as TabId));
  // Nach dem Start im Leerlauf die Daten der übrigen Tabs vorab laden (Issue #104), damit sie schon beim ersten Öffnen
  // ohne „Lädt …“ erscheinen. Nur einmal, in der Reihenfolge der Tab-Leiste; Bedingungen in viewData.ts und prefetch.ts.
  const idleStarted = useRef(false);
  useEffect(() => {
    if (idleStarted.current || !config) return;
    idleStarted.current = true;
    const others = tabs.filter((t) => t.key !== activeTab);
    void prefetcher.idle(others.flatMap(sourcesOf));
    // Programmteile nur, wenn der Service Worker sie schon gespeichert hat (kein zusätzlicher Download im Leerlauf).
    if (navigator.serviceWorker?.controller) void whenIdle().then(() => others.forEach(preloadEntry));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
  // Beim Antippen eines Tabs (pointerdown, etwa 100–300 ms vor dem click) bzw. Fokus per Tastatur.
  const prefetchTab = (t: NavEntry) => {
    if (t.key === activeTab || prefetcher.blocked()) return;
    void prefetcher.tap(sourcesOf(t));
    preloadEntry(t);
  };

  useEffect(() => {
    if (!toast) return;
    const t = setTimeout(() => setToast(null), 3500);
    return () => clearTimeout(t);
  }, [toast]);

  useEffect(() => {
    const on = () => setOnline(true);
    const off = () => setOnline(false);
    window.addEventListener('online', on);
    window.addEventListener('offline', off);
    return () => {
      window.removeEventListener('online', on);
      window.removeEventListener('offline', off);
    };
  }, []);

  if (!config) {
    return (
      <main className="content">
        <p className="error">Die App muss über ihre Adresse auf der Webseite geöffnet werden.</p>
      </main>
    );
  }

  // Wartungsmodus (Issue #42): alle außer Admins sehen nur die Wartungsseite; die Anmeldung bleibt erreichbar.
  const maintenance = config.maintenance?.on ? config.maintenance : null;
  if (maintenance && !can('manage_settings') && tab !== 'anmelden') {
    return (
      <div className="app">
        <PreviewBanner />
        <ErrorBoundary>
          <Suspense fallback={<Loading chunk />}>
            <MaintenancePage reason={maintenance.reason} />
          </Suspense>
        </ErrorBoundary>
      </div>
    );
  }

  const albumId = tab === 'galerie' && /^\d+$/.test(path[1] ?? '') ? Number(path[1]) : undefined;
  const companyId = tab === 'kompanien' && /^\d+$/.test(path[1] ?? '') ? Number(path[1]) : undefined;
  const docId = tab === 'dokumente' && /^\d+$/.test(path[1] ?? '') ? Number(path[1]) : undefined;
  const newsId = tab === 'news' && /^[A-Za-z0-9_-]+$/.test(path[1] ?? '') ? path[1] : undefined;

  // Eine eigene Seite trägt ihren Titel (wie ihr Punkt in der Navigation).
  const title =
    tab === 'menue'
      ? (openMenu?.label ?? titles().menue)
      : navKey !== tab
        ? (targetLabels()[navKey as NavTarget] ?? titles()[tab])
        : titles()[tab];
  // Ist der Start ein Untermenü (menue/<n>), gehört seine Nummer in den Link.
  const pageUrl = shareUrl(config.appUrl, valid ? tab : start, requested, path);
  const head = (
    <header className="topbar">
      <img src={logoUrl} alt="" className="logo" />
      <div className="topbar-text">
        <div className="topbar-title">{caps(title)}</div>
        <div className="topbar-sub">{user ? greeting(user.name) : brand.name}</div>
      </div>
      {!['anmelden', 'einrichtung'].includes(tab) && !(tab === 'registrieren' && path[1] === 'bestaetigen') && (
        <button
          className="btn btn-link topbar-share"
          onClick={() => void shareLink(pageUrl, `${brand.shortName}: ${caps(title)}`, showToast)}
          aria-label="Link zu dieser Seite teilen"
          title="Link zu dieser Seite teilen"
        >
          <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
            <path
              d="M18 16.1c-.8 0-1.5.3-2 .8l-7.1-4.2c.1-.2.1-.5.1-.7s0-.5-.1-.7l7-4.1c.5.5 1.3.8 2.1.8 1.7 0 3-1.3 3-3s-1.3-3-3-3-3 1.3-3 3c0 .2 0 .5.1.7l-7 4.1C7.5 9.3 6.8 9 6 9c-1.7 0-3 1.3-3 3s1.3 3 3 3c.8 0 1.5-.3 2-.8l7.1 4.2c-.1.2-.1.4-.1.6 0 1.6 1.3 2.9 2.9 2.9s2.9-1.3 2.9-2.9-1.2-2.9-2.8-2.9z"
              fill="currentColor"
            />
          </svg>
        </button>
      )}
      <button
        className={`btn btn-link topbar-help ${tab === 'hilfe' ? 'active' : ''}`}
        onClick={() => {
          // Für „Fehler melden“ (Issue #64): die Seite, auf der jemand Hilfe sucht
          if (tab !== 'hilfe') setReportOrigin('#/' + [tab, ...path.slice(1)].join('/'));
          const slug = helpSlug([tab, ...path.slice(1)], {
            boardWriter: canPostBoard(),
            can: (c) => can(c as Capability),
            features: config?.features ?? {},
          });
          navigate(slug ? `hilfe/${slug}` : 'hilfe');
        }}
        aria-label="Hilfe"
        aria-current={tab === 'hilfe' ? 'page' : undefined}
      >
        ?
      </button>
      <ThemeButton />
      {!user && tab !== 'anmelden' && (
        <button className="btn btn-link topbar-account" onClick={toLogin}>
          Login
        </button>
      )}
    </header>
  );

  return (
    <div className="app">
      {config.preview ? (
        // In der Vorschau bleibt das Banner mit der Kopfzeile oben stehen.
        <div className="preview-sticky">
          <PreviewBanner />
          {head}
        </div>
      ) : (
        head
      )}

      {maintenance && (
        <ErrorBoundary>
          <Suspense fallback={null}>
            <MaintenanceBanner reason={maintenance.reason} onToast={showToast} />
          </Suspense>
        </ErrorBoundary>
      )}
      {boardVisible() && tab !== 'brett' && <BoardBanner />}
      {!online && <div className="offline">Offline – angezeigt werden die zuletzt geladenen Daten.</div>}
      {!['installieren', 'anmelden', 'registrieren', 'einstellungen', 'einrichtung'].includes(tab) && <InstallBanner onToast={showToast} />}

      <main className="content">
        <ErrorBoundary key={viewKey(path)}>
          <Suspense fallback={<Loading chunk />}>
            {config.setupPending && tab !== 'einrichtung' && (
              <div className="card setup-hint">
                <p>
                  <strong>Willkommen!</strong> Die App ist installiert. Jetzt noch auswählen, welche Bereiche eure Kompanie nutzt, und sie
                  verbinden – dauert ein paar Minuten.
                </p>
                <button className="btn btn-block" onClick={() => navigate('einrichtung')}>
                  Einrichtung starten
                </button>
              </div>
            )}
            {loginFirst && (
              <div className="card login-first" role="note">
                <p>Diese Seite ist erst nach der Anmeldung zu sehen. Danach geht sie direkt auf.</p>
                <button className="btn btn-block" onClick={toLogin}>
                  Anmelden
                </button>
                {canRegister && (
                  <button className="btn btn-secondary btn-block" onClick={() => navigate('registrieren')}>
                    Noch keinen Zugang? Zugang beantragen
                  </button>
                )}
              </div>
            )}
            {!user && brand.guestWelcome && activeTab === tabs[0]?.key && <p className="guest-welcome card">{brand.guestWelcome}</p>}
            {/* Rückweg ins Untermenü über dem Inhalt, wie „‹ Verwaltung“ (vor allen Bereichen, die darin stehen können) */}
            {parentMenu && menuStart && <MenuBack to={parentMenu.key} label={parentMenu.label} />}
            {canRegister && !loginFirst && activeTab === tabs[0]?.key && !path[1] && (
              <p className="registration-hint muted small">
                Du gehörst dazu und hast noch keinen Zugang?{' '}
                <button type="button" className="btn btn-link inline-link" onClick={() => navigate('registrieren')}>
                  Zugang beantragen
                </button>
              </p>
            )}
            {tab === 'termine' &&
              (path[1] === 'verwalten' && features?.events && can('manage_events') ? (
                <TermineVerwalten onToast={showToast} />
              ) : (
                <Termine focus={path[1] === 'termin' ? focusId(path[2]) : undefined} />
              ))}
            {tab === 'news' && <News id={newsId} onToast={showToast} />}
            {tab === 'galerie' &&
              (path[1] === 'einreichen' && can('submit_gallery') ? (
                <GalerieEinreichungen mode="einreichen" onToast={showToast} />
              ) : path[1] === 'pruefen' && can('review_gallery') ? (
                <GalerieEinreichungen mode="pruefen" onToast={showToast} />
              ) : (
                <Galerie albumId={albumId} />
              ))}
            {tab === 'kompanien' && <Kompanien id={companyId} />}
            {tab === 'sponsoren' && <Sponsoren />}
            {tab === 'seiten' && <Seiten id={/^\d+$/.test(path[1] ?? '') ? Number(path[1]) : null} />}
            {openMenu?.children && (
              <InternMenu label={openMenu.label} tabs={openMenu.children.map((v) => ({ id: v, label: targetLabels()[v] ?? v }))} />
            )}
            {tab === 'dokumente' &&
              (path[1] === 'verwalten' && can('manage_documents') ? (
                <DokumenteVerwalten onToast={showToast} />
              ) : (
                <Dokumente docId={docId} onToast={showToast} />
              ))}
            {tab === 'mitglieder' && <Mitglieder path={path.slice(1)} />}
            {tab === 'dienstgrade' && <Dienstgrade />}
            {tab === 'punktekatalog' && <Punktekatalog />}
            {tab === 'meins' && <Meins sub={path[1]} focus={focusId(path[2])} onToast={showToast} onLogout={() => logout(showToast)} />}
            {tab === 'verwaltung' && <Verwaltung path={path.slice(1)} onToast={showToast} />}
            {adminStart && <AdminBack />}
            {tab === 'brett' && <Brett focus={focusId(path[1])} onToast={showToast} />}
            {tab === 'umfragen' && <Umfragen path={path.slice(1)} onToast={showToast} />}
            {tab === 'inventar' && <Inventar path={path.slice(1)} onToast={showToast} />}
            {tab === 'benachrichtigungen' && <Benachrichtigungen onToast={showToast} />}
            {tab === 'installieren' && <Installieren onToast={showToast} />}
            {tab === 'anmelden' && <Anmelden />}
            {tab === 'registrieren' && <Registrieren path={path.slice(1)} />}
            {tab === 'einstellungen' && <Einstellungen path={path.slice(1)} onToast={showToast} />}
            {tab === 'einrichtung' && <Einrichtung onToast={showToast} />}
            {tab === 'hilfe' && <Hilfe slug={path[1]} />}
          </Suspense>
        </ErrorBoundary>
      </main>

      {toast && (
        <div className="toast" role="status" aria-live="polite">
          {toast}
        </div>
      )}

      {tabs.length > 1 && (
        <nav className="tabbar" aria-label="Hauptnavigation">
          {tabs.map((t) => (
            <button
              key={t.key}
              className={activeTab === t.key ? 'active' : ''}
              aria-label={t.label}
              onClick={() => navigate(t.key)}
              onPointerDown={() => prefetchTab(t)}
              onFocus={() => prefetchTab(t)}
              aria-current={activeTab === t.key ? 'page' : undefined}
            >
              <Icon name={t.icon} />
              <span>{caps(t.label)}</span>
            </button>
          ))}
        </nav>
      )}
    </div>
  );
}
