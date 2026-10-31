import { useEffect, useState } from 'react';
import brand from '../generated/brand';
import { api, config, type PushDevice, type PushStatus, type PushType } from '../api';
import { currentDevice, disablePush, enablePush, matchesServerKey, pushSupport } from '../push';
import { previewRole } from '../preview';
import { navigate } from '../router';
import { ErrorBox, Loading } from '../ui';

/**
 * Benachrichtigungen auf diesem Gerät: für Konten (Punkte, Strafen, Termine …) und für Gäste ohne Konto
 * (nur Öffentliches). Jede Änderung wird sofort gespeichert.
 */
export default function Benachrichtigungen({ onToast }: { onToast: (msg: string) => void }) {
  const support = pushSupport();
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
        <h3>Benachrichtigungen</h3>
        <p className="small muted">
          In der Vorschau lassen sich Benachrichtigungen nicht ansehen oder einrichten: Sie gehören zu diesem Gerät und deinem Konto.
        </p>
      </div>
    );
  }
  if (support === 'ios-install') {
    return (
      <div className="card push-card">
        <h3>Benachrichtigungen</h3>
        <p className="small">
          Auf dem iPhone und iPad gibt es Benachrichtigungen nur in der installierten App (ab iOS 16.4). Hol dir die App auf den
          Home-Bildschirm und öffne sie von dort.
        </p>
        <button className="btn btn-block" onClick={() => navigate('installieren')}>
          App installieren
        </button>
      </div>
    );
  }
  if (support === 'unsupported') {
    return (
      <div className="card push-card">
        <h3>Benachrichtigungen</h3>
        <p className="small muted">
          Dieser Browser unterstützt keine Benachrichtigungen. Probier es mit einem aktuellen Chrome, Firefox, Edge oder Safari.
        </p>
      </div>
    );
  }
  if (error) return <ErrorBox message={error} onRetry={() => setTick((n) => n + 1)} />;
  if (!status || types === null) return <Loading shape="form" />;
  if (!status.supported) {
    return (
      <div className="card push-card">
        <h3>Benachrichtigungen</h3>
        <p className="small muted">Der Server dieser App kann noch keine Benachrichtigungen senden. Sag bitte dem Admin Bescheid.</p>
      </div>
    );
  }
  if (status.types.length === 0) {
    return (
      <div className="card push-card">
        <h3>Benachrichtigungen</h3>
        <p className="small muted">Zurzeit gibt es nichts, worüber die App dich benachrichtigen könnte.</p>
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
      onToast('Benachrichtigungen sind an');
    });

  const disable = () =>
    run(async () => {
      if (device) await disablePush(device);
      setDevice(null);
      setStatus({ ...status, subscription: null });
      onToast('Benachrichtigungen sind aus');
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
      onToast('Testnachricht gesendet');
    });

  return (
    <div className="card push-card">
      <h3>Benachrichtigungen</h3>
      <p className="small muted">
        {active
          ? 'Dieses Gerät bekommt Benachrichtigungen zu:'
          : config?.user
            ? 'Lass dich auf diesem Gerät benachrichtigen, wenn es Neues für dich gibt.'
            : 'Lass dich benachrichtigen, wenn es neue Termine oder Neuigkeiten gibt – ganz ohne Konto.'}
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
            {brand.labels.sections.penalties}: Betrag und Grund in der Nachricht zeigen
            <span className="small muted feature-desc">Aus: nur ein Hinweis – gut, wenn andere deinen Sperrbildschirm sehen.</span>
          </span>
        </label>
      )}
      {blocked && !active && (
        <p className="small" role="alert">
          Benachrichtigungen sind für diese Seite blockiert. Erlaube sie in den Einstellungen des Browsers und lade die Seite neu.
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
            Testnachricht senden
          </button>
          <button className="btn btn-link btn-block push-off" onClick={disable} disabled={busy}>
            Benachrichtigungen ausschalten
          </button>
        </>
      ) : (
        <button className="btn btn-block push-on" onClick={enable} disabled={busy || blocked || types.length === 0}>
          {busy ? 'Einen Moment …' : 'Benachrichtigungen einschalten'}
        </button>
      )}
    </div>
  );
}
