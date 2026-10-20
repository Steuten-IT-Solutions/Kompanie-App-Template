import { Suspense, useCallback, useEffect, useState } from 'react';
import { api, ApiError, can, config, CONFIG_CHANGED, isMember, toLogin } from './api';
import { caps } from './format';
import brand from './generated/brand';
import logoUrl from './generated/logo-small.webp';
import { detectPlatform, isStandalone, promptInstall, useCanPrompt } from './install';
import { ErrorBoundary, lazyView, Loading } from './ui';
import { forgetPushOnLogout } from './push';
import { navigate, useRoute } from './router';
import Anmelden from './views/Anmelden';
import Galerie from './views/Galerie';
import Meins from './views/Meins';
import News from './views/News';
import Termine from './views/Termine';

// Selten gebraucht: erst bei Bedarf laden (der Service Worker speichert sie trotzdem vorab).
const Einrichtung = lazyView(() => import('./views/Einrichtung'));
const Einstellungen = lazyView(() => import('./views/Einstellungen'));
const Installieren = lazyView(() => import('./views/Installieren'));
const Konto = lazyView(() => import('./views/Konto'));
const TermineVerwalten = lazyView(() => import('./views/TermineVerwalten'));
const Verwaltung = lazyView(() => import('./views/Verwaltung'));
const Hilfe = lazyView(() => import('./views/Hilfe'));
const Benachrichtigungen = lazyView(() => import('./views/Benachrichtigungen'));

type Tab = 'termine' | 'news' | 'galerie' | 'meins' | 'verwaltung';
type View = Tab | 'konto' | 'benachrichtigungen' | 'installieren' | 'anmelden' | 'einstellungen' | 'einrichtung' | 'hilfe';

const VIEWS: View[] = [
  'termine',
  'news',
  'galerie',
  'meins',
  'verwaltung',
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
  meins: 'Meins',
  verwaltung: 'Verwaltung',
});

const titles = (): Record<View, string> => ({
  ...tabLabels(),
  // Standardbezeichnung „News“ in der Überschrift ausgeschrieben; eigene Bezeichnungen unverändert.
  news: sec.news === 'News' ? 'Neuigkeiten' : sec.news,
  konto: 'Mein Konto',
  benachrichtigungen: 'Benachrichtigungen',
  installieren: 'App installieren',
  anmelden: 'Anmelden',
  einstellungen: 'Einstellungen',
  einrichtung: 'Einrichtung',
  hilfe: 'Hilfe',
});

function Icon({ name }: { name: Tab }) {
  const paths: Record<Tab, string> = {
    termine: 'M7 2v2H5a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2V2h-2v2H9V2H7zm-2 7h14v10H5V9z',
    news: 'M4 4h13v14a2 2 0 0 0 2 2H6a2 2 0 0 1-2-2V4zm15 4h1a1 1 0 0 1 1 1v9a2 2 0 0 1-4 0V8h2zM7 7v4h7V7H7zm0 6v1.5h7V13H7zm0 3v1.5h7V16H7z',
    galerie:
      'M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zm1 11.5V17h14v-2.5l-4-4-3.5 3.5-2-2L5 16.5zM8.5 7a1.75 1.75 0 1 0 0 3.5 1.75 1.75 0 0 0 0-3.5z',
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

async function clearCaches() {
  if ('caches' in window) {
    const keys = await caches.keys();
    await Promise.all(keys.map((k) => caches.delete(k)));
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
    termine: !!(features?.events || (features?.calendar && can('calendar'))),
    news: !!features?.news,
    galerie: !!features?.gallery,
    meins: isMember() && !!(features?.points || features?.penalties),
    // Die Verwaltung beginnt mit der Mitgliederliste: für alle, die Punkte oder Strafen vergeben,
    // Mitglieder anlegen oder Rollen vergeben (auch wenn Punkte und Strafen aus sind).
    verwaltung:
      (!!features?.points && can('manage_points')) ||
      (!!features?.penalties && can('manage_penalties')) ||
      can('create_users') ||
      can('promote_users'),
    konto: !!user,
    benachrichtigungen: true,
    installieren: true,
    anmelden: !user && config?.auth.mode === 'form',
    einstellungen: can('manage_settings'),
    einrichtung: can('manage_settings'),
    hilfe: true,
  };
  const tabs = (['termine', 'news', 'galerie', 'meins', 'verwaltung'] as Tab[]).filter((t) => allowed[t]);
  const requested = path[0] as View;
  // Neue Installation ohne Bereiche: Der Admin landet direkt in der Einrichtung.
  const start: View = tabs[0] ?? (config?.setupPending && allowed.einrichtung ? 'einrichtung' : 'installieren');
  const tab: View = VIEWS.includes(requested) && allowed[requested] ? requested : start;

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

  const albumId = tab === 'galerie' && /^\d+$/.test(path[1] ?? '') ? Number(path[1]) : undefined;
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
          onClick={() => navigate('hilfe')}
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
              ) : (
                <Termine />
              ))}
            {tab === 'news' && <News id={newsId} />}
            {tab === 'galerie' && <Galerie albumId={albumId} />}
            {tab === 'meins' && <Meins sub={path[1]} />}
            {tab === 'verwaltung' && <Verwaltung path={path.slice(1)} onToast={showToast} />}
            {tab === 'konto' && <Konto onToast={showToast} onLogout={() => logout(showToast)} />}
            {tab === 'benachrichtigungen' && <Benachrichtigungen onToast={showToast} />}
            {tab === 'installieren' && <Installieren onToast={showToast} />}
            {tab === 'anmelden' && <Anmelden />}
            {tab === 'einstellungen' && <Einstellungen onToast={showToast} />}
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
              className={tab === t ? 'active' : ''}
              aria-label={tabLabels()[t]}
              onClick={() => navigate(t)}
              aria-current={tab === t ? 'page' : undefined}
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
