import { useState, type ReactNode } from 'react';
import { config } from '../api';
import brand from '../generated/brand';
import logoUrl from '../generated/logo.png';
import { detectPlatform, isInAppBrowser, isStandalone, promptInstall, useCanPrompt, type Platform } from '../install';

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
  <g transform={`translate(${x} ${y}) scale(${s})`} fill="none" stroke="#1f6fe5" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
    <path d="M-7 -2h-3v14h20v-14h-3" />
    <path d="M0 6v-17M-5 -6l5-5 5 5" />
  </g>
);

function IosStep1() {
  return (
    <Frame label="Safari-Leiste unten: Teilen-Symbol bzw. drei Punkte">
      <text x="14" y="26" className="pic-cap">Ältere iPhones</text>
      <rect x="12" y="34" width="216" height="40" rx="10" fill="var(--bg)" />
      <text x="34" y="60" className="pic-ico">‹</text>
      <text x="72" y="60" className="pic-ico">›</text>
      <ShareIcon x={120} y={56} />
      <rect x="156" y="45" width="14" height="17" rx="2" fill="none" stroke="#1f6fe5" strokeWidth="2" />
      <rect x="194" y="45" width="15" height="15" rx="3" fill="none" stroke="#1f6fe5" strokeWidth="2" />
      <Tap x={120} y={54} r={21} />
      <text x="14" y="98" className="pic-cap">Neuere iPhones</text>
      <rect x="12" y="106" width="216" height="34" rx="17" fill="var(--bg)" />
      <text x="28" y="129" className="pic-ico">‹</text>
      <rect x="52" y="113" width="132" height="20" rx="10" fill="var(--line)" />
      <text x="118" y="127" textAnchor="middle" className="pic-small">{HOST}</text>
      <text x="206" y="128" textAnchor="middle" className="pic-dots">•••</text>
      <Tap x={206} y={123} r={14} />
    </Frame>
  );
}

function IosStep2() {
  return (
    <Frame label="Teilen-Menü mit dem Eintrag Zum Home-Bildschirm">
      <rect x="20" y="14" width="200" height="30" rx="8" fill="var(--bg)" />
      <text x="34" y="34" className="pic-row">Kopieren</text>
      <rect x="20" y="50" width="200" height="30" rx="8" fill="var(--bg)" />
      <text x="34" y="70" className="pic-row">Zu Favoriten hinzufügen</text>
      <rect x="20" y="86" width="200" height="34" rx="8" fill="var(--bg)" stroke={HL} strokeWidth="4" />
      <text x="34" y="108" className="pic-row pic-strong">Zum Home-Bildschirm</text>
      <rect x="194" y="95" width="16" height="16" rx="4" fill="none" stroke="var(--ink)" strokeWidth="2" />
      <path d="M202 99v8M198 103h8" stroke="var(--ink)" strokeWidth="2" />
    </Frame>
  );
}

function IosStep3() {
  return (
    <Frame label="Fenster Zum Home-Bildschirm mit dem Knopf Hinzufügen oben rechts">
      <text x="16" y="30" className="pic-row" fill="#1f6fe5">Abbrechen</text>
      <text x="224" y="30" textAnchor="end" className="pic-row pic-strong" fill="#1f6fe5">Hinzufügen</text>
      <rect x="141" y="10" width="92" height="26" rx="8" fill="none" stroke={HL} strokeWidth="4" />
      <line x1="10" y1="44" x2="230" y2="44" stroke="var(--line)" strokeWidth="2" />
      <image href={logoUrl} x="22" y="58" width="44" height="44" />
      <text x="80" y="78" className="pic-row">{brand.shortName}</text>
      <text x="80" y="96" className="pic-small">{HOST}</text>
      <text x="22" y="132" className="pic-row">Als Web-App öffnen</text>
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
      <text x="148" y="140" textAnchor="middle" className="pic-small pic-strong">{brand.shortName.length > 14 ? brand.shortName.slice(0, 13) + '…' : brand.shortName}</text>
      <Tap x={148} y={100} r={25} />
    </Frame>
  );
}

function AndroidStep1() {
  return (
    <Frame label="Chrome-Leiste oben mit den drei Punkten rechts">
      <rect x="12" y="20" width="216" height="40" rx="20" fill="var(--bg)" />
      <text x="30" y="45" className="pic-ico">⌂</text>
      <rect x="52" y="28" width="132" height="24" rx="12" fill="var(--line)" />
      <text x="118" y="44" textAnchor="middle" className="pic-small">{HOST}</text>
      <circle cx="207" cy="32" r="2.6" fill="var(--ink)" />
      <circle cx="207" cy="40" r="2.6" fill="var(--ink)" />
      <circle cx="207" cy="48" r="2.6" fill="var(--ink)" />
      <Tap x={207} y={40} r={15} />
      <text x="120" y="100" textAnchor="middle" className="pic-row">oben rechts: drei Punkte ⋮</text>
    </Frame>
  );
}

function AndroidStep2() {
  return (
    <Frame label="Chrome-Menü mit dem Eintrag App installieren">
      <rect x="20" y="14" width="200" height="30" rx="8" fill="var(--bg)" />
      <text x="34" y="34" className="pic-row">Neuer Tab</text>
      <rect x="20" y="50" width="200" height="30" rx="8" fill="var(--bg)" />
      <text x="34" y="70" className="pic-row">Verlauf</text>
      <rect x="20" y="86" width="200" height="40" rx="8" fill="var(--bg)" stroke={HL} strokeWidth="4" />
      <text x="34" y="103" className="pic-row pic-strong">App installieren</text>
      <text x="34" y="119" className="pic-small">oder: Zum Startbildschirm hinzufügen</text>
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
      window.prompt('Adresse kopieren:', url);
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

/** Adresse dieser Anleitung – zum Weiterschicken per WhatsApp, E-Mail usw. */
export function installUrl(): string {
  const app = config?.appUrl ?? '/app/';
  // Ohne URL-Umschreibung (…?kompanie_app=index) direkt über die Sprungmarke #/installieren.
  return app.includes('?') ? `${app}#/installieren` : `${app}installieren/`;
}

function ShareGuide({ onToast }: { onToast: (msg: string) => void }) {
  const url = installUrl();
  async function share() {
    if (navigator.share) {
      try {
        await navigator.share({ title: `${brand.shortName}: App installieren`, text: `So kommt die App der ${brand.name} aufs Handy:`, url });
      } catch {
        /* abgebrochen */
      }
      return;
    }
    try {
      await navigator.clipboard.writeText(url);
      onToast('Link kopiert');
    } catch {
      window.prompt('Link kopieren:', url);
    }
  }
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
            Unten auf das <strong>Teilen-Symbol</strong> tippen
          </>
        }
        pic={<IosStep1 />}
      >
        Steht unten rechts nur <strong>„•••“</strong>, zuerst darauf tippen und dann <strong>„Teilen“</strong> wählen.
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
        Je nach Handy heißt der Eintrag auch „Zum Startbildschirm hinzufügen“. Danach mit „Installieren“ bzw. „Hinzufügen“
        bestätigen.
      </Step>
      <Step n={3} title={<>Fertig! Die App mit unserem Logo öffnen</>} pic={<HomeScreen label="Startbildschirm mit dem Logo der App" />} />
    </ol>
  );
}

export default function Installieren({ onToast }: { onToast: (msg: string) => void }) {
  const detected = detectPlatform();
  const [platform, setPlatform] = useState<Platform>(detected === 'other' ? 'ios' : detected);
  const canPrompt = useCanPrompt();
  const inApp = isInAppBrowser();
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
          Dann ist die App der {brand.name} mit unserem Logo auf dem Startbildschirm – wie jede andere App. Das dauert keine
          Minute und kostet nichts.
        </p>
        {canPrompt && (
          <button className="btn btn-block install-big" onClick={install}>
            App installieren
          </button>
        )}
      </div>

      {inApp && (
        <div className="install-warn" role="alert">
          <strong>Achtung:</strong> Diese Seite ist gerade in Facebook, Instagram o. Ä. geöffnet. Von dort aus lässt sich die
          App nicht installieren. Bitte die Adresse kopieren und in {detected === 'ios' ? 'Safari' : 'Chrome'} öffnen:
          <CopyLink />
        </div>
      )}
      {iosOtherBrowser && !inApp && (
        <div className="install-warn" role="note">
          Am einfachsten klappt es in <strong>Safari</strong> (blauer Kompass). Bitte die Adresse kopieren und in Safari öffnen:
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
          {platform === 'ios' && (
            <p className="muted small install-foot">
              Kein „Zum Home-Bildschirm“ zu finden? Dann wurde der Link wahrscheinlich in einer anderen App geöffnet. Bitte die
              Adresse in Safari öffnen und noch einmal versuchen.
            </p>
          )}
        </>
      )}

      <ShareGuide onToast={onToast} />
    </div>
  );
}
