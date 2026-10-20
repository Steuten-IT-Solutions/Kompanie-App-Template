import { Suspense, useCallback, useEffect, useState } from 'react';
import { api, ApiError, can, config, CONFIG_CHANGED, isMember, toLogin } from './api';
import { caps } from './format';
import brand from './generated/brand';
import logoUrl from './generated/logo-small.webp';
import { detectPlatform, isStandalone, promptInstall, useCanPrompt } from './install';
import BoardBanner from './BoardBanner';
import { boardVisible, canManagePolls, canPostBoard, pollsVisible } from './board';
import { ErrorBoundary, lazyView, Loading } from './ui';
import { birthdaysVisible } from './upcomingBirthdays';
import { forgetPushOnLogout } from './push';
import { navigate, useRoute } from './router';
import { AdminNav, adminTabs } from './adminNav';
import { helpSlug } from './helpSlug';
import News from './views/News';
import Termine from './views/Termine';

// Erst bei Bedarf laden: Der App-Start braucht nur die Ansichten, mit denen Gäste meist beginnen (Termine, News).
// Der Service Worker speichert alle Teile trotzdem vorab, damit sie auch offline da sind.
const Anmelden = lazyView(() => import('./views/Anmelden'));
const Galerie = lazyView(() => import('./views/Galerie'));
const Meins = lazyView(() => import('./views/Meins'));
const BirthdayPage = lazyView(() => import('./birthdays'));
const MaintenancePage = lazyView(() => import('./maintenance').then((m) => ({ default: m.MaintenancePage })));
const MaintenanceBanner = lazyView(() => import('./maintenance').then((m) => ({ default: m.MaintenanceBanner })));
const Einrichtung = lazyView(() => import('./views/Einrichtung'));
const Einstellungen = lazyView(() => import('./views/Einstellungen'));
const Installieren = lazyView(() => import('./views/Installieren'));
const Konto = lazyView(() => import('./views/Konto'));
const TermineVerwalten = lazyView(() => import('./views/TermineVerwalten'));
const Verwaltung = lazyView(() => import('./views/Verwaltung'));
const Hilfe = lazyView(() => import('./views/Hilfe'));
const Benachrichtigungen = lazyView(() => import('./views/Benachrichtigungen'));
const Dokumente = lazyView(() => import('./views/Dokumente'));
const DokumenteVerwalten = lazyView(() => import('./views/DokumenteVerwalten'));
const Brett = lazyView(() => import('./views/Brett'));
const Umfragen = lazyView(() => import('./views/Umfragen'));
const Inventar = lazyView(() => import('./views/Inventar'));

type Tab = 'termine' | 'news' | 'galerie' | 'dokumente' | 'meins' | 'verwaltung';
type View =
  | Tab
  | 'brett'
  | 'umfragen'
  | 'inventar'
  | 'konto'
  | 'benachrichtigungen'
  | 'installieren'
  | 'anmelden'
  | 'einstellungen'
  | 'einrichtung'
  | 'hilfe';

const VIEWS: View[] = [
  'termine',
  'news',
  'galerie',
  'dokumente',
  'meins',
  'verwaltung',
  'brett',
  'umfragen',
  'inventar',
  'konto',
  'benachrichtigungen',
  'installieren',
  'anmelden',
  'einstellungen',
  'einrichtung',
  'hilfe',
];

/** Ältere Links weiter unterstützen. */
const ALIASES: Record<string, string[]> = {
  punkte: ['meins', 'punkte'],
  strafen: ['meins', 'strafen'],
  spiess: ['verwaltung'],
};

const features = config?.features;
const sec = brand.labels.sections;

const tabLabels = (): Record<Tab, string> => ({
  termine: sec.events,
  news: sec.news,
  galerie: sec.gallery,
  dokumente: sec.documents,
  meins: 'Meins',
  verwaltung: 'Verwaltung',
});

const titles = (): Record<View, string> => ({
  ...tabLabels(),
  // Standardbezeichnung „News“ in der Überschrift ausgeschrieben; eigene Bezeichnungen unverändert.
  news: sec.news === 'News' ? 'Neuigkeiten' : sec.news,
  brett: sec.board,
  umfragen: sec.polls,
  inventar: sec.inventory,
  konto: 'Mein Konto',
  benachrichtigungen: 'Benachrichtigungen',
  installieren: 'App installieren',
  anmelden: 'Anmelden',
  einstellungen: 'Einstellungen',
  einrichtung: 'Einrichtung',
  hilfe: 'Hilfe',
});

/** Erste Unterseite von „Meins“ (wie in Meins.tsx), für die Hilfe zu #/meins ohne Unterseite. */
function meinsFirst(): string | undefined {
  const f = config?.features;
  return f?.points ? 'punkte' : f?.penalties ? 'strafen' : f?.ranks ? 'dienstgrad' : f?.attendance ? 'anwesenheit' : undefined;
}

function Icon({ name }: { name: Tab }) {
  const paths: Record<Tab, string> = {
    termine: 'M7 2v2H5a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2V2h-2v2H9V2H7zm-2 7h14v10H5V9z',
    news: 'M4 4h13v14a2 2 0 0 0 2 2H6a2 2 0 0 1-2-2V4zm15 4h1a1 1 0 0 1 1 1v9a2 2 0 0 1-4 0V8h2zM7 7v4h7V7H7zm0 6v1.5h7V13H7zm0 3v1.5h7V16H7z',
    galerie:
      'M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zm1 11.5V17h14v-2.5l-4-4-3.5 3.5-2-2L5 16.5zM8.5 7a1.75 1.75 0 1 0 0 3.5 1.75 1.75 0 0 0 0-3.5z',
    dokumente:
      'M6 2h8l6 6v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm7 1.5V9h5.5L13 3.5zM8 13v1.5h8V13H8zm0 3v1.5h8V16H8zm0-6v1.5h4V10H8z',
    meins: 'M12 2l2.9 6.3 6.9.7-5.2 4.6 1.5 6.8L12 17l-6.1 3.4 1.5-6.8L2.2 9l6.9-.7L12 2z',
    verwaltung:
      'M12 2l8 3v6c0 5-3.4 9.4-8 11-4.6-1.6-8-6-8-11V5l8-3zm0 5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zm-4 9.5c.8-1.6 2.3-2.5 4-2.5s3.2.9 4 2.5',
  };
  return (
    <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true">
      <path d={paths[name]} fill="currentColor" />
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

export default function App() {
  const rawPath = useRoute();
  const path = ALIASES[rawPath[0]] ?? rawPath;
  const user = config?.user ?? null;
  const allowed: Record<View, boolean> = {
    // Geburtstage (Issue #1) stehen bei den Terminen: für Mitglieder auch, wenn es sonst keine Termine gibt.
    termine: !!(features?.events || (features?.calendar && can('calendar'))) || birthdaysVisible(),
    news: !!features?.news,
    galerie: !!features?.gallery,
    dokumente: isMember() && !!features?.documents,
    meins: isMember() && !!(features?.points || features?.penalties || features?.ranks || features?.titles || features?.attendance),
    // Verwaltung: Mitgliederliste und Pflege (Punkte, Strafen, Dienstgrade …), dazu Schwarzes Brett, Umfragen und Inventar.
    verwaltung: adminTabs().length > 0,
    // Schwarzes Brett (Issue #5): Mitglieder und Anwärter lesen, wer das Recht hat, schreibt.
    brett: boardVisible() || canPostBoard(),
    // Umfragen (Issue #4): Mitglieder antworten, manage_polls verwaltet; laufende stehen am Brett.
    umfragen: pollsVisible() || canManagePolls(),
    // Inventar (Issue #6): nur mit manage_inventory, Einstieg über „Mein Konto“.
    inventar: !!features?.inventory && can('manage_inventory'),
    konto: !!user,
    benachrichtigungen: true,
    installieren: true,
    anmelden: !user && config?.auth.mode === 'form',
    einstellungen: can('manage_settings'),
    einrichtung: can('manage_settings'),
    hilfe: true,
  };
  // „Meins“ ganz rechts, die Verwaltung davor.
  const tabs = (['termine', 'news', 'galerie', 'dokumente', 'verwaltung', 'meins'] as Tab[]).filter((t) => allowed[t]);
  const requested = path[0] as View;
  // Neue Installation ohne Bereiche: Der Admin landet direkt in der Einrichtung.
  const start: View = tabs[0] ?? (config?.setupPending && allowed.einrichtung ? 'einrichtung' : 'installieren');
  const tab: View = VIEWS.includes(requested) && allowed[requested] ? requested : start;
  // Schwarzes Brett, Umfragen und Inventar gehören für alle, die sie pflegen, zur Verwaltung (Reiter oben, Tab unten).
  const adminView = ['brett', 'umfragen', 'inventar'].includes(tab) && adminTabs().some((t) => t.id === tab) ? tab : null;
  const activeTab: View = adminView ? 'verwaltung' : tab;

  const [toast, setToast] = useState<string | null>(null);
  const [online, setOnline] = useState(navigator.onLine);
  const showToast = useCallback((msg: string) => setToast(msg), []);
  // Bereiche wurden ein- oder ausgeschaltet: Tabs und Ansichten neu bestimmen.
  const [, setConfigVersion] = useState(0);
  useEffect(() => {
    const changed = () => setConfigVersion((v) => v + 1);
    window.addEventListener(CONFIG_CHANGED, changed);
    return () => window.removeEventListener(CONFIG_CHANGED, changed);
  }, []);

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
        <ErrorBoundary>
          <Suspense fallback={<Loading />}>
            <MaintenancePage reason={maintenance.reason} />
          </Suspense>
        </ErrorBoundary>
      </div>
    );
  }

  const albumId = tab === 'galerie' && /^\d+$/.test(path[1] ?? '') ? Number(path[1]) : undefined;
  const docId = tab === 'dokumente' && /^\d+$/.test(path[1] ?? '') ? Number(path[1]) : undefined;
  const newsId = tab === 'news' && /^[A-Za-z0-9_-]+$/.test(path[1] ?? '') ? path[1] : undefined;

  return (
    <div className="app">
      <header className="topbar">
        <img src={logoUrl} alt="" className="logo" />
        <div className="topbar-text">
          <div className="topbar-title">{caps(titles()[tab])}</div>
          <div className="topbar-sub">{user ? greeting(user.name) : brand.name}</div>
        </div>
        <button
          className={`btn btn-link topbar-help ${tab === 'hilfe' ? 'active' : ''}`}
          onClick={() => {
            const slug = helpSlug([tab, ...path.slice(1)], { boardWriter: canPostBoard(), meinsFirst: meinsFirst() });
            navigate(slug ? `hilfe/${slug}` : 'hilfe');
          }}
          aria-label="Hilfe"
          aria-current={tab === 'hilfe' ? 'page' : undefined}
        >
          ?
        </button>
        {user ? (
          <button
            className={`btn btn-link topbar-account ${tab === 'konto' ? 'active' : ''}`}
            onClick={() => navigate('konto')}
            aria-current={tab === 'konto' ? 'page' : undefined}
          >
            Konto
          </button>
        ) : (
          tab !== 'anmelden' && (
            <button className="btn btn-link topbar-account" onClick={toLogin}>
              Anmelden
            </button>
          )
        )}
      </header>

      {maintenance && (
        <ErrorBoundary>
          <Suspense fallback={null}>
            <MaintenanceBanner reason={maintenance.reason} onToast={showToast} />
          </Suspense>
        </ErrorBoundary>
      )}
      {boardVisible() && tab !== 'brett' && <BoardBanner />}
      {!online && <div className="offline">Offline – angezeigt werden die zuletzt geladenen Daten.</div>}
      {!['installieren', 'anmelden', 'einstellungen', 'einrichtung'].includes(tab) && <InstallBanner onToast={showToast} />}

      <main className="content">
        <ErrorBoundary key={rawPath.join('/')}>
          <Suspense fallback={<Loading />}>
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
            {!user && brand.guestWelcome && tab === tabs[0] && <p className="guest-welcome card">{brand.guestWelcome}</p>}
            {tab === 'termine' &&
              (path[1] === 'verwalten' && features?.events && can('manage_events') ? (
                <TermineVerwalten onToast={showToast} />
              ) : path[1] === 'geburtstage' && birthdaysVisible() ? (
                <BirthdayPage />
              ) : (
                <Termine />
              ))}
            {tab === 'news' && <News id={newsId} />}
            {tab === 'galerie' && <Galerie albumId={albumId} />}
            {tab === 'dokumente' &&
              (path[1] === 'verwalten' && can('manage_documents') ? (
                <DokumenteVerwalten onToast={showToast} />
              ) : (
                <Dokumente docId={docId} onToast={showToast} />
              ))}
            {tab === 'meins' && <Meins sub={path[1]} />}
            {tab === 'verwaltung' && <Verwaltung path={path.slice(1)} onToast={showToast} />}
            {adminView && <AdminNav active={adminView} />}
            {tab === 'brett' && <Brett onToast={showToast} />}
            {tab === 'umfragen' && <Umfragen path={path.slice(1)} onToast={showToast} />}
            {tab === 'inventar' && <Inventar path={path.slice(1)} onToast={showToast} />}
            {tab === 'konto' && <Konto onToast={showToast} onLogout={() => logout(showToast)} />}
            {tab === 'benachrichtigungen' && <Benachrichtigungen onToast={showToast} />}
            {tab === 'installieren' && <Installieren onToast={showToast} />}
            {tab === 'anmelden' && <Anmelden />}
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
              key={t}
              className={activeTab === t ? 'active' : ''}
              aria-label={tabLabels()[t]}
              onClick={() => navigate(t)}
              aria-current={activeTab === t ? 'page' : undefined}
            >
              <Icon name={t} />
              <span>{caps(tabLabels()[t])}</span>
            </button>
          ))}
        </nav>
      )}
    </div>
  );
}
