import { useState, type ReactNode } from 'react';
import { config } from '../api';
import brand from '../generated/brand';
import logoUrl from '../generated/logo.png';
import {
  CHROME_PLAY_URL,
  chromeUrl,
  detectPlatform,
  installUrl,
  isInAppBrowser,
  isStandalone,
  promptInstall,
  useCanPrompt,
  type Platform,
} from '../install';
import { androidBrowser, BROWSER_NAMES, shortcutOnly, type AndroidBrowser } from '../androidBrowser';
import { shareLink } from '../share';
import { promptAsk } from '../ask';

/*
 * Schritt-für-Schritt-Anleitung „App aufs Handy holen“ – mit großen Bildern,
 * gedacht auch für Mitglieder, die selten mit dem Smartphone arbeiten.
 * Die Bilder sind vereinfachte Zeichnungen der Safari-/Chrome-Oberfläche.
 */

const HL = 'var(--accent)';
/** Adresse, unter der die App gerade läuft – so stimmt sie auf jedem Bild. */
const HOST = window.location.host;

function Frame({ children, label }: { children: ReactNode; label: string }) {
  return (
    <svg className="install-pic" viewBox="0 0 240 150" role="img" aria-label={label}>
      <rect x="1" y="1" width="238" height="148" rx="16" fill="var(--card)" stroke="var(--line-strong)" strokeWidth="2" />
      {children}
    </svg>
  );
}

/** Ring in der Akzentfarbe auf dem Element, auf das getippt werden soll. */
function Tap({ x, y, r = 16 }: { x: number; y: number; r?: number }) {
  return (
    <g>
      <circle cx={x} cy={y} r={r} fill="none" stroke={HL} strokeWidth="4" />
      <circle cx={x} cy={y} r={r + 7} fill="none" stroke={HL} strokeWidth="2" opacity="0.45" />
    </g>
  );
}

const ShareIcon = ({ x, y, s = 1 }: { x: number; y: number; s?: number }) => (
  <g
    transform={`translate(${x} ${y}) scale(${s})`}
    fill="none"
    stroke="#1f6fe5"
    strokeWidth="2.2"
    strokeLinecap="round"
    strokeLinejoin="round"
  >
    <path d="M-7 -2h-3v14h20v-14h-3" />
    <path d="M0 6v-17M-5 -6l5-5 5 5" />
  </g>
);

function IosStep1() {
  return (
    <Frame label="Safari-Leiste unten: Teilen-Symbol bzw. drei Punkte">
      <text x="14" y="26" className="pic-cap">
        Ältere iPhones
      </text>
      <rect x="12" y="34" width="216" height="40" rx="10" fill="var(--bg)" />
      <text x="34" y="60" className="pic-ico">
        ‹
      </text>
      <text x="72" y="60" className="pic-ico">
        ›
      </text>
      <ShareIcon x={120} y={56} />
      <rect x="156" y="45" width="14" height="17" rx="2" fill="none" stroke="#1f6fe5" strokeWidth="2" />
      <rect x="194" y="45" width="15" height="15" rx="3" fill="none" stroke="#1f6fe5" strokeWidth="2" />
      <Tap x={120} y={54} r={21} />
      <text x="14" y="98" className="pic-cap">
        Neuere iPhones
      </text>
      <rect x="12" y="106" width="216" height="34" rx="17" fill="var(--bg)" />
      <text x="28" y="129" className="pic-ico">
        ‹
      </text>
      <rect x="52" y="113" width="132" height="20" rx="10" fill="var(--line)" />
      <text x="118" y="127" textAnchor="middle" className="pic-small">
        {HOST}
      </text>
      <text x="206" y="128" textAnchor="middle" className="pic-dots">
        •••
      </text>
      <Tap x={206} y={123} r={14} />
    </Frame>
  );
}

function IosStep2() {
  return (
    <Frame label="Teilen-Menü mit dem Eintrag Zum Home-Bildschirm">
      <rect x="20" y="14" width="200" height="30" rx="8" fill="var(--bg)" />
      <text x="34" y="34" className="pic-row">
        Kopieren
      </text>
      <rect x="20" y="50" width="200" height="30" rx="8" fill="var(--bg)" />
      <text x="34" y="70" className="pic-row">
        Zu Favoriten hinzufügen
      </text>
      <rect x="20" y="86" width="200" height="34" rx="8" fill="var(--bg)" stroke={HL} strokeWidth="4" />
      <text x="34" y="108" className="pic-row pic-strong">
        Zum Home-Bildschirm
      </text>
      <rect x="194" y="95" width="16" height="16" rx="4" fill="none" stroke="var(--ink)" strokeWidth="2" />
      <path d="M202 99v8M198 103h8" stroke="var(--ink)" strokeWidth="2" />
    </Frame>
  );
}

function IosStep3() {
  return (
    <Frame label="Fenster Zum Home-Bildschirm mit dem Knopf Hinzufügen oben rechts">
      <text x="16" y="30" className="pic-row" fill="#1f6fe5">
        Abbrechen
      </text>
      <text x="224" y="30" textAnchor="end" className="pic-row pic-strong" fill="#1f6fe5">
        Hinzufügen
      </text>
      <rect x="141" y="10" width="92" height="26" rx="8" fill="none" stroke={HL} strokeWidth="4" />
      <line x1="10" y1="44" x2="230" y2="44" stroke="var(--line)" strokeWidth="2" />
      <image href={logoUrl} x="22" y="58" width="44" height="44" />
      <text x="80" y="78" className="pic-row">
        {brand.shortName}
      </text>
      <text x="80" y="96" className="pic-small">
        {HOST}
      </text>
      <text x="22" y="132" className="pic-row">
        Als Web-App öffnen
      </text>
      <rect x="182" y="117" width="40" height="22" rx="11" fill="#34c759" />
      <circle cx="211" cy="128" r="9" fill="#fff" />
    </Frame>
  );
}

function HomeScreen({ label }: { label: string }) {
  const apps = [0, 1, 2, 3];
  return (
    <Frame label={label}>
      {apps.map((i) => (
        <rect key={i} x={24 + i * 52} y="18" width="40" height="40" rx="10" fill="var(--line)" />
      ))}
      <rect x="24" y="80" width="40" height="40" rx="10" fill="var(--line)" />
      <rect x="76" y="80" width="40" height="40" rx="10" fill="var(--line)" />
      <rect x="128" y="80" width="40" height="40" rx="10" fill="#fff" stroke="var(--line-strong)" />
      <image href={logoUrl} x="131" y="83" width="34" height="34" />
      <text x="148" y="140" textAnchor="middle" className="pic-small pic-strong">
        {brand.shortName.length > 14 ? brand.shortName.slice(0, 13) + '…' : brand.shortName}
      </text>
      <Tap x={148} y={100} r={25} />
    </Frame>
  );
}

function AndroidStep1() {
  return (
    <Frame label="Chrome-Leiste oben mit den drei Punkten rechts">
      <rect x="12" y="20" width="216" height="40" rx="20" fill="var(--bg)" />
      <text x="30" y="45" className="pic-ico">
        ⌂
      </text>
      <rect x="52" y="28" width="132" height="24" rx="12" fill="var(--line)" />
      <text x="118" y="44" textAnchor="middle" className="pic-small">
        {HOST}
      </text>
      <circle cx="207" cy="32" r="2.6" fill="var(--ink)" />
      <circle cx="207" cy="40" r="2.6" fill="var(--ink)" />
      <circle cx="207" cy="48" r="2.6" fill="var(--ink)" />
      <Tap x={207} y={40} r={15} />
      <text x="120" y="100" textAnchor="middle" className="pic-row">
        oben rechts: drei Punkte ⋮
      </text>
    </Frame>
  );
}

function AndroidStep2() {
  return (
    <Frame label="Chrome-Menü mit dem Eintrag App installieren">
      <rect x="20" y="14" width="200" height="30" rx="8" fill="var(--bg)" />
      <text x="34" y="34" className="pic-row">
        Neuer Tab
      </text>
      <rect x="20" y="50" width="200" height="30" rx="8" fill="var(--bg)" />
      <text x="34" y="70" className="pic-row">
        Verlauf
      </text>
      <rect x="20" y="86" width="200" height="40" rx="8" fill="var(--bg)" stroke={HL} strokeWidth="4" />
      <text x="34" y="103" className="pic-row pic-strong">
        App installieren
      </text>
      <text x="34" y="119" className="pic-small">
        oder: Zum Startbildschirm hinzufügen
      </text>
    </Frame>
  );
}

function Step({ n, title, children, pic }: { n: number; title: ReactNode; children?: ReactNode; pic: ReactNode }) {
  return (
    <li className="install-step card">
      <div className="install-step-head">
        <span className="install-num" aria-hidden="true">
          {n}
        </span>
        <div className="install-step-title">{title}</div>
      </div>
      {pic}
      {children && <div className="install-step-text">{children}</div>}
    </li>
  );
}

function CopyLink() {
  const url = config?.appUrl ?? window.location.href.split('#')[0];
  const [copied, setCopied] = useState(false);
  async function copy() {
    try {
      await navigator.clipboard.writeText(url);
      setCopied(true);
    } catch {
      await promptAsk('Zum Kopieren markieren:', url, { title: 'Adresse kopieren', okLabel: 'Fertig' });
    }
  }
  return (
    <div className="install-copy">
      <code>{url}</code>
      <button className="btn btn-secondary btn-small" onClick={copy}>
        {copied ? 'Kopiert ✓' : 'Adresse kopieren'}
      </button>
    </div>
  );
}

function ShareGuide({ onToast }: { onToast: (msg: string) => void }) {
  const url = installUrl();
  const share = () => shareLink(url, `${brand.shortName}: App installieren`, onToast, `So kommt die App der ${brand.name} aufs Handy:`);
  return (
    <div className="card install-share">
      <h3>Anleitung weitergeben</h3>
      <p className="muted small">Diesen Link kann man per WhatsApp oder E-Mail verschicken – er öffnet genau diese Anleitung.</p>
      <code>{url}</code>
      <button className="btn btn-secondary btn-block" onClick={share}>
        Link teilen
      </button>
    </div>
  );
}

function IosGuide() {
  return (
    <ol className="install-steps">
      <Step
        n={1}
        title={
          <>
            Auf das <strong>Teilen-Symbol</strong> tippen
          </>
        }
        pic={<IosStep1 />}
      >
        Wo es steht, hängt von Gerät und Safari ab: am iPhone meist in der Leiste unten, am iPad in der Leiste oben. Ab iOS 26 ist „Kompakt“
        voreingestellt: Dann steckt es hinter <strong>„•••“</strong> an der Adresszeile – zuerst darauf tippen und dann{' '}
        <strong>„Teilen“</strong> wählen. Die Darstellung lässt sich unter Einstellungen → Apps → Safari → Tabs umstellen.
      </Step>
      <Step
        n={2}
        title={
          <>
            <strong>„Zum Home-Bildschirm“</strong> wählen
          </>
        }
        pic={<IosStep2 />}
      >
        Der Eintrag steht weiter unten in der Liste – mit dem Finger nach oben wischen, bis er erscheint.
      </Step>
      <Step
        n={3}
        title={
          <>
            Oben rechts auf <strong>„Hinzufügen“</strong> tippen
          </>
        }
        pic={<IosStep3 />}
      >
        Falls dort der Schalter „Als Web-App öffnen“ steht: eingeschaltet (grün) lassen.
      </Step>
      <Step n={4} title={<>Fertig! Die App mit unserem Logo öffnen</>} pic={<HomeScreen label="Home-Bildschirm mit dem Logo der App" />}>
        Ab jetzt die App immer über dieses Symbol starten. Die Anmeldung bleibt gespeichert.
      </Step>
    </ol>
  );
}

function AndroidGuide() {
  return (
    <ol className="install-steps">
      <Step
        n={1}
        title={
          <>
            Oben rechts auf die <strong>drei Punkte ⋮</strong> tippen
          </>
        }
        pic={<AndroidStep1 />}
      />
      <Step
        n={2}
        title={
          <>
            <strong>„App installieren“</strong> wählen
          </>
        }
        pic={<AndroidStep2 />}
      >
        Je nach Handy und Chrome-Version heißt der Eintrag auch „Zum Startbildschirm hinzufügen“ oder ähnlich. Danach mit „Installieren“
        bzw. „Hinzufügen“ bestätigen. Fragt Chrome „Installieren“ oder „Verknüpfung erstellen“: „Installieren“ wählen.
      </Step>
      <Step n={3} title={<>Fertig! Die App mit unserem Logo öffnen</>} pic={<HomeScreen label="Startbildschirm mit dem Logo der App" />} />
    </ol>
  );
}

/** Was tun, wenn es auf Android nicht klappt (Quellen: docs/handbuch/app-installieren.md). */
function AndroidTrouble() {
  return (
    <div className="card install-trouble stack">
      <h3>Klappt es nicht?</h3>
      <ul className="small">
        <li>
          <strong>Kein Eintrag zum Installieren:</strong> Ist die App schon installiert, bietet Chrome sie nicht noch einmal an. Sieh in der
          Liste deiner Apps nach. Sonst die Seite einmal antippen, kurz warten und das Menü erneut öffnen.
        </li>
        <li>
          <strong>Xiaomi, Redmi, POCO:</strong> Dort darf ein Browser erst Symbole auf den Startbildschirm legen, wenn du es erlaubst: in
          den Einstellungen des Handys bei den Berechtigungen von Chrome „Verknüpfungen auf dem Startbildschirm“ einschalten.
        </li>
        <li>
          <strong>Huawei ohne Google-Dienste:</strong> Ohne Google-Dienste legt Chrome nur eine Verknüpfung an, und Benachrichtigungen
          können ausbleiben, weil sie über Google zugestellt werden.
        </li>
        <li>
          <strong>Benachrichtigungen kommen nicht an:</strong> Manche Handys (z. B. Samsung, Xiaomi, Huawei) beenden Apps im Hintergrund, um
          Akku zu sparen. Nimm Chrome und die App in den Akku-Einstellungen von der Optimierung aus.
        </li>
        <li>
          <strong>Android 8 oder 9:</strong> Dort bekommt Chrome keine neuen Versionen mehr (letzte ist Chrome 138). Die App kann dann
          eingeschränkt laufen.
        </li>
      </ul>
    </div>
  );
}

/** Weg zu Chrome: öffnen, falls vorhanden – sonst erst herunterladen. Ob Chrome installiert ist, kann eine Webseite nicht erkennen. */
function ChromeButtons() {
  return (
    <>
      <a className="btn btn-block install-chrome" href={chromeUrl(installUrl())}>
        In Chrome öffnen
      </a>
      <p className="small install-chrome-get">
        Noch kein Chrome auf dem Handy? Chrome ist kostenlos. Erst herunterladen, dann hier wieder auf „In Chrome öffnen“ tippen.
      </p>
      <a className="btn btn-block btn-secondary install-chrome" href={CHROME_PLAY_URL} target="_blank" rel="noopener noreferrer">
        Chrome herunterladen
      </a>
      Falls „In Chrome öffnen“ nicht funktioniert: Adresse kopieren und in Chrome einfügen.
      <CopyLink />
    </>
  );
}

/** Andere Browser als Chrome auf Android: warum Chrome besser ist und wie es trotzdem geht. */
function OtherBrowserNote({ browser }: { browser: AndroidBrowser }) {
  const name = BROWSER_NAMES[browser];
  const steps: Partial<Record<AndroidBrowser, ReactNode>> = {
    firefox: (
      <>
        Oben rechts auf <strong>⋮</strong> tippen, <strong>„Installieren“</strong> wählen und dann <strong>„Automatisch hinzufügen“</strong>
        .
      </>
    ),
    opera: (
      <>
        Das Menü öffnen, <strong>„Hinzufügen zu …“</strong> und dann <strong>„Startbildschirm“</strong> wählen.
      </>
    ),
  };
  return (
    <div className="install-warn" role="note">
      <strong>Am besten mit Chrome installieren.</strong> {browser === 'other' ? 'Dieser Browser' : name} legt nur eine Verknüpfung auf dem
      Startbildschirm an, keine richtige App: Sie steht nicht in der Liste deiner Apps und öffnet sich über den Browser.{' '}
      {browser === 'firefox' || browser === 'opera'
        ? 'Benachrichtigungen gehen dort zwar auch, '
        : 'Ob Benachrichtigungen dort ankommen, ist nicht sicher; '}
      mit Chrome wird die App eine richtige App mit Benachrichtigungen.
      <ChromeButtons />
      <p className="small install-other-steps">
        Lieber bei {browser === 'other' ? 'diesem Browser' : name} bleiben?{' '}
        {steps[browser] ?? (
          <>
            Im Menü des Browsers den Eintrag <strong>„Zum Startbildschirm hinzufügen“</strong> (oder ähnlich) wählen.
          </>
        )}
      </p>
    </div>
  );
}

export default function Installieren({ onToast }: { onToast: (msg: string) => void }) {
  const detected = detectPlatform();
  const [platform, setPlatform] = useState<Platform>(detected === 'other' ? 'ios' : detected);
  const canPrompt = useCanPrompt();
  const browser = detected === 'android' ? androidBrowser(navigator.userAgent, 'brave' in navigator) : null;
  const inApp = isInAppBrowser() || browser === 'webview';
  const samsung = browser === 'samsung';
  const iosOtherBrowser = detected === 'ios' && /CriOS|FxiOS|EdgiOS/i.test(navigator.userAgent);

  async function install() {
    const result = await promptInstall();
    if (result === 'accepted') onToast('Die App wird installiert – gleich ist sie auf dem Startbildschirm');
  }

  if (isStandalone()) {
    return (
      <div className="card install-intro">
        <h2>Alles erledigt</h2>
        <p>Die App ist bereits installiert – du benutzt sie gerade.</p>
      </div>
    );
  }

  return (
    <div className="install">
      <div className="card install-intro">
        <h2>Die App aufs Handy holen</h2>
        <p>
          Dann ist die App der {brand.name} mit unserem Logo auf dem Startbildschirm – wie jede andere App. Das dauert keine Minute und
          kostet nichts.
        </p>
        {canPrompt && (
          <button className="btn btn-block install-big" onClick={install}>
            App installieren
          </button>
        )}
        {!canPrompt && browser === 'chrome' && !inApp && (
          // Chrome meldet „installierbar“ (beforeinstallprompt) erst, wenn die Seite insgesamt etwa 30 Sekunden offen war und
          // einmal angetippt wurde (https://web.dev/articles/install-criteria). Das lässt sich von der Seite aus nicht beschleunigen.
          <p className="small install-wait" role="status">
            Gleich erscheint hier der Knopf <strong>„App installieren“</strong>. Chrome zeigt ihn erst, wenn die Seite etwa 30 Sekunden
            offen war und du einmal irgendwo getippt hast. Ohne Warten geht es mit den Schritten unten.
          </p>
        )}
      </div>

      {inApp && (
        <div className="install-warn" role="alert">
          <strong>Achtung:</strong> Diese Seite ist gerade im eingebauten Browser einer anderen App geöffnet (z. B. WhatsApp, Facebook,
          Instagram). Solche Browser können keine Apps installieren und keine Benachrichtigungen zeigen. Bitte die Adresse kopieren und in{' '}
          {detected === 'ios' ? 'Safari' : 'Chrome'} öffnen:
          <CopyLink />
        </div>
      )}
      {samsung && !inApp && (
        <div className="install-warn" role="alert">
          <strong>Bitte nicht im Samsung-Browser installieren.</strong> Warum: Ab Android 14 blockiert Google Play Protect die App, die der
          Samsung-Browser anlegt, als „unsicher“ bzw. „für eine ältere Android-Version entwickelt“. Das ist ein Fehler des Samsung-Browsers,
          nicht der App; er ist seit April 2026 gemeldet und noch nicht behoben. Mit <strong>Chrome</strong> klappt es, auch auf
          Samsung-Handys:
          <ChromeButtons />
        </div>
      )}
      {browser && shortcutOnly(browser) && !inApp && <OtherBrowserNote browser={browser} />}
      {iosOtherBrowser && !inApp && (
        <div className="install-warn" role="note">
          Am einfachsten klappt es in <strong>Safari</strong> (blauer Kompass). Ab iOS 16.4 können auch Chrome, Edge oder Firefox die App
          über <strong>Teilen → „Zum Home-Bildschirm“</strong> hinzufügen, wenn der Browser den Eintrag anbietet. Fehlt er, die Adresse
          kopieren und in Safari öffnen:
          <CopyLink />
        </div>
      )}

      {!canPrompt && (
        <>
          <nav className="segmented" aria-label="Handy auswählen">
            <button className={platform === 'ios' ? 'active' : ''} onClick={() => setPlatform('ios')} aria-pressed={platform === 'ios'}>
              iPhone / iPad
            </button>
            <button
              className={platform === 'android' ? 'active' : ''}
              onClick={() => setPlatform('android')}
              aria-pressed={platform === 'android'}
            >
              Android
            </button>
          </nav>
          {platform === 'ios' ? <IosGuide /> : <AndroidGuide />}
          {platform === 'android' && <AndroidTrouble />}
          {platform === 'ios' && (
            <p className="muted small install-foot">
              Kein „Zum Home-Bildschirm“ zu finden? Dann wurde der Link wahrscheinlich in einer anderen App geöffnet. Bitte die Adresse in
              Safari öffnen und noch einmal versuchen.
            </p>
          )}
        </>
      )}

      <ShareGuide onToast={onToast} />
    </div>
  );
}
