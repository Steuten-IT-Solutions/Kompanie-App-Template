import { useEffect, useState } from 'react';
import brand from '../generated/brand';
import { t } from '../i18n';
import { api, config, type MailPreference, type PushDevice, type PushStatus, type PushType } from '../api';
import { currentDevice, disablePush, enablePush, matchesServerKey, pushSupport } from '../push';
import { androidBrowser } from '../androidBrowser';
import { detectPlatform, isInAppBrowser } from '../install';
import { previewRole } from '../preview';
import { navigate } from '../router';
import { ErrorBox, Loading } from '../ui';

/**
 * Benachrichtigungen auf diesem Gerät (Push) und, für Konten, zusätzlich per E-Mail. Jede Änderung wird sofort
 * gespeichert.
 */
export default function Benachrichtigungen({ onToast }: { onToast: (msg: string) => void }) {
  return (
    <div className="stack">
      <PushCard onToast={onToast} />
      {/* Mails gehören zum Konto, nicht zum Gerät; in der Vorschau nicht (der Server lehnt Änderungen ab). */}
      {config?.user && !previewRole() && <MailCard onToast={onToast} />}
    </div>
  );
}

/**
 * Benachrichtigungen auf diesem Gerät: für Konten (Punkte, Strafen, Termine …) und für Gäste ohne Konto
 * (nur Öffentliches).
 */
function PushCard({ onToast }: { onToast: (msg: string) => void }) {
  const support = pushSupport();
  const android = detectPlatform() === 'android';
  const inAppBrowser = isInAppBrowser() || (android && androidBrowser(navigator.userAgent) === 'webview');
  const [device, setDevice] = useState<PushDevice | null>(null);
  const [status, setStatus] = useState<PushStatus | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [types, setTypes] = useState<PushType[] | null>(null);
  const [details, setDetails] = useState(false);
  const [tick, setTick] = useState(0);

  useEffect(() => {
    if (support !== 'ok' || previewRole()) return;
    let alive = true;
    (async () => {
      let d = await currentDevice().catch(() => null);
      let s = await api.pushStatus(d);
      // Abo zu einem früheren Schlüssel des Servers (z. B. nach einer Neuinstallation): still erneuern, sonst kämen
      // keine Nachrichten mehr an.
      if (d && s.subscription && s.supported && Notification.permission === 'granted' && !(await matchesServerKey(s.publicKey))) {
        const old = s.subscription;
        d = await enablePush(s.publicKey);
        s = { ...s, subscription: await api.pushSubscribe(d, old.types, config?.user ? old.details : undefined) };
      }
      if (!alive) return;
      setDevice(d);
      setStatus(s);
      // Gespeicherte Arten, die es nicht mehr gibt (Bereich ausgeschaltet), nicht mitzählen.
      const available = s.types.map((t) => t.key);
      setTypes(s.subscription ? s.subscription.types.filter((k) => available.includes(k)) : available);
      setDetails(s.subscription?.details ?? false);
      setError(null);
    })().catch((e: Error) => alive && setError(e.message));
    return () => {
      alive = false;
    };
  }, [support, tick]);

  // Vorschau (Issue #63): Schon das Abfragen kann Abos dieses Geräts ändern; der Server lehnt es ab.
  if (previewRole()) {
    return (
      <div className="card push-card">
        <h3>{t('Benachrichtigungen')}</h3>
        <p className="small muted">
          {t('In der Vorschau lassen sich Benachrichtigungen nicht ansehen oder einrichten: Sie gehören zu diesem Gerät und deinem Konto.')}
        </p>
      </div>
    );
  }
  if (support === 'ios-install') {
    return (
      <div className="card push-card">
        <h3>{t('Benachrichtigungen')}</h3>
        <p className="small">
          {t(
            'Auf dem iPhone und iPad gibt es Benachrichtigungen nur in der installierten App (ab iOS 16.4). Hol dir die App auf den Home-Bildschirm und öffne sie von dort.',
          )}
        </p>
        <button className="btn btn-block" onClick={() => navigate('installieren')}>
          {t('App installieren')}
        </button>
      </div>
    );
  }
  if (support === 'unsupported') {
    return (
      <div className="card push-card">
        <h3>{t('Benachrichtigungen')}</h3>
        <p className="small muted">
          {inAppBrowser
            ? t(
                'Diese Seite ist im eingebauten Browser einer anderen App geöffnet (z. B. WhatsApp, Facebook). Solche Browser können keine Benachrichtigungen zeigen. Öffne die Adresse in Chrome (Android) bzw. Safari (iPhone).',
              )
            : t('Dieser Browser unterstützt keine Benachrichtigungen. Probier es mit einem aktuellen Chrome, Firefox, Edge oder Safari.')}
        </p>
      </div>
    );
  }
  if (error) return <ErrorBox message={error} onRetry={() => setTick((n) => n + 1)} />;
  if (!status || types === null) return <Loading shape="form" />;
  if (!status.supported) {
    return (
      <div className="card push-card">
        <h3>{t('Benachrichtigungen')}</h3>
        <p className="small muted">{t('Der Server dieser App kann noch keine Benachrichtigungen senden. Sag bitte dem Admin Bescheid.')}</p>
      </div>
    );
  }
  if (status.types.length === 0) {
    return (
      <div className="card push-card">
        <h3>{t('Benachrichtigungen')}</h3>
        <p className="small muted">{t('Zurzeit gibt es nichts, worüber die App dich benachrichtigen könnte.')}</p>
      </div>
    );
  }

  const active = status.subscription !== null && device !== null;
  const blocked = Notification.permission === 'denied';
  // Der Schalter betrifft nur Strafen (Betrag und Grund); Punkte zeigen immer Wert und Grund.
  const personal = status.types.some((t) => t.key === 'penalties');

  async function run(action: () => Promise<void>) {
    setBusy(true);
    setActionError(null);
    try {
      await action();
    } catch (err) {
      setActionError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  const enable = () =>
    run(async () => {
      const d = await enablePush(status.publicKey);
      const sub = await api.pushSubscribe(d, types, config?.user ? details : undefined);
      setDevice(d);
      setStatus({ ...status, subscription: sub });
      setTypes(sub.types);
      setDetails(sub.details);
      onToast(t('Benachrichtigungen sind an'));
    });

  const disable = () =>
    run(async () => {
      if (device) await disablePush(device);
      setDevice(null);
      setStatus({ ...status, subscription: null });
      onToast(t('Benachrichtigungen sind aus'));
    });

  const toggleType = (key: PushType, on: boolean) => {
    const next = status.types.map((t) => t.key).filter((k) => (k === key ? on : types.includes(k)));
    setTypes(next);
    if (active) {
      run(async () => {
        const sub = await api.pushUpdate(device, { types: next });
        setStatus({ ...status, subscription: sub });
      });
    }
  };

  const toggleDetails = (on: boolean) => {
    setDetails(on);
    if (active) {
      run(async () => {
        const sub = await api.pushUpdate(device, { details: on });
        setStatus({ ...status, subscription: sub });
      });
    }
  };

  const test = () =>
    run(async () => {
      if (device) await api.pushTest(device);
      onToast(t('Testnachricht gesendet'));
    });

  return (
    <div className="card push-card">
      <h3>{t('Benachrichtigungen')}</h3>
      <p className="small muted">
        {active
          ? t('Dieses Gerät bekommt Benachrichtigungen zu:')
          : config?.user
            ? t('Lass dich auf diesem Gerät benachrichtigen, wenn es Neues für dich gibt.')
            : t('Lass dich benachrichtigen, wenn es etwas Neues gibt – ganz ohne Konto.')}
      </p>
      <div className="push-types">
        {status.types.map((t) => (
          <label key={t.key} className="toggle">
            {/* Mindestens eine Art bleibt an; ganz aus geht über „ausschalten“. */}
            <input
              type="checkbox"
              checked={types.includes(t.key)}
              disabled={busy || (active && types.length === 1 && types.includes(t.key))}
              onChange={(e) => toggleType(t.key, e.target.checked)}
            />
            <span>{t.label}</span>
          </label>
        ))}
      </div>
      {config?.user && personal && (
        <label className="toggle">
          <input type="checkbox" checked={details} disabled={busy} onChange={(e) => toggleDetails(e.target.checked)} />
          <span>
            {t('{section}: Betrag und Grund in der Nachricht zeigen', { section: brand.labels.sections.penalties })}
            <span className="small muted feature-desc">{t('Aus: nur ein Hinweis – gut, wenn andere deinen Sperrbildschirm sehen.')}</span>
          </span>
        </label>
      )}
      {blocked && !active && (
        <p className="small" role="alert">
          {t(
            'Benachrichtigungen sind für diese Seite blockiert, deshalb fragt der Browser nicht mehr nach. Erlaube sie in den Einstellungen des Browsers und lade die Seite neu.',
          )}
          {android && (
            <>
              {' '}
              {t('In Chrome: links neben der Adresse auf das Symbol tippen')} → <strong>{t('Berechtigungen')}</strong> →{' '}
              <strong>{t('Benachrichtigungen')}</strong> → <strong>{t('Zulassen')}</strong>.{' '}
              {t('In der installierten App außerdem: Einstellungen des Handys')} → <strong>{t('Apps')}</strong> → {t('die App')} →{' '}
              <strong>{t('Benachrichtigungen')}</strong>.
            </>
          )}
        </p>
      )}
      {actionError && (
        <p className="form-error" role="alert">
          {actionError}
        </p>
      )}
      {active ? (
        <>
          <button className="btn btn-secondary btn-block push-test" onClick={test} disabled={busy}>
            {t('Testnachricht senden')}
          </button>
          <button className="btn btn-link btn-block push-off" onClick={disable} disabled={busy}>
            {t('Benachrichtigungen ausschalten')}
          </button>
        </>
      ) : (
        <button className="btn btn-block push-on" onClick={enable} disabled={busy || blocked || types.length === 0}>
          {busy ? t('Einen Moment …') : t('Benachrichtigungen einschalten')}
        </button>
      )}
    </div>
  );
}

/**
 * Benachrichtigungen per E-Mail: dieselben Arten wie auf dem Gerät (Standard alle an), dazu die Mails vom Brett mit
 * ganzer Nachricht (eigener Schalter, nur für Mitglieder und Anwärter). Nur, wenn der Mailversand eingerichtet ist.
 */
function MailCard({ onToast }: { onToast: (msg: string) => void }) {
  const [pref, setPref] = useState<MailPreference | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    api.mailPreference().then(setPref, () => setPref(null));
  }, []);
  if (!pref) return null;
  const types = pref.notifications.available ? pref.notifications.types : [];
  if (!pref.available && types.length === 0) return null;

  async function save(action: () => Promise<MailPreference>, done: (p: MailPreference) => string) {
    setBusy(true);
    setError(null);
    try {
      const p = await action();
      setPref(p);
      onToast(done(p));
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  const selected = pref.notifications.selected;
  const toggleType = (key: PushType, on: boolean) => {
    const next = types.map((x) => x.key).filter((k) => (k === key ? on : selected.includes(k)));
    void save(
      () => api.setNotificationMails(next),
      () => (on ? t('Per E-Mail: an') : t('Per E-Mail: aus')),
    );
  };

  return (
    <div className="card push-card mail-notifications">
      <h3>{t('Per E-Mail')}</h3>
      <p className="small muted">
        {t('Zusätzlich eine E-Mail an deine Adresse. Gilt für dein Konto, egal auf welchem Gerät du die App nutzt.')}
      </p>
      {!pref.email && <p className="small">{t('Für dein Konto ist keine Mailadresse hinterlegt; bitte beim Admin melden.')}</p>}
      <div className="push-types">
        {pref.available && (
          <label className="toggle board-mail-pref">
            <input
              type="checkbox"
              checked={pref.board}
              disabled={busy}
              onChange={(e) =>
                void save(
                  () => api.setMailPreference(e.target.checked),
                  (p) => (p.board ? t('Mails vom Brett: an') : t('Mails vom Brett: aus')),
                )
              }
            />
            <span>{t('Nachrichten aus „{section}“ per E-Mail', { section: brand.labels.sections.board })}</span>
          </label>
        )}
        {types.map((x) => (
          <label key={x.key} className="toggle">
            <input
              type="checkbox"
              checked={selected.includes(x.key)}
              disabled={busy}
              onChange={(e) => toggleType(x.key, e.target.checked)}
            />
            <span>{x.label}</span>
          </label>
        ))}
      </div>
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
    </div>
  );
}
