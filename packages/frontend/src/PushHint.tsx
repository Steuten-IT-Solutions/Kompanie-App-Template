import { useEffect, useState } from 'react';
import { api, config, type PushStatus } from './api';
import { enablePush, pushSupport } from './push';
import { pushHintDue, pushHintLater } from './pushHint';
import { previewRole } from './preview';
import { navigate } from './router';

/**
 * Startseite: Hinweis für angemeldete Mitglieder, deren Gerät noch keine Benachrichtigungen hat. „Einschalten“ fragt
 * den Browser nach der Erlaubnis und meldet das Gerät mit allen angebotenen Arten an (wie unter Konto →
 * Benachrichtigungen); „Nicht jetzt“ blendet ihn für eine Weile aus. Ohne Push auf dem Server oder ohne Arten bleibt er weg.
 */
export default function PushHint({ onToast }: { onToast: (msg: string) => void }) {
  const eligible = !!config?.user && !previewRole() && pushSupport() === 'ok' && pushHintDue(Notification.permission);
  const [status, setStatus] = useState<PushStatus | null>(null);
  const [hidden, setHidden] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!eligible) return;
    let alive = true;
    api
      .pushStatus(null)
      .then((s) => alive && setStatus(s))
      .catch(() => undefined); // offline o. ä.: dann eben kein Hinweis
    return () => {
      alive = false;
    };
  }, [eligible]);

  if (!eligible || hidden || !status || !status.supported || status.types.length === 0) return null;

  async function enable() {
    if (!status) return;
    setBusy(true);
    setError(null);
    try {
      const device = await enablePush(status.publicKey);
      await api.pushSubscribe(
        device,
        status.types.map((t) => t.key),
      );
      setHidden(true);
      onToast('Benachrichtigungen sind an');
    } catch (err) {
      setError((err as Error).message);
      if (Notification.permission === 'denied') pushHintLater();
    } finally {
      setBusy(false);
    }
  }

  const later = () => {
    pushHintLater();
    setHidden(true);
  };

  return (
    <div className="card push-hint" role="note">
      <p>
        <strong>Nichts mehr verpassen</strong>
        <br />
        Lass dich auf diesem Gerät benachrichtigen, wenn es Neues für dich gibt. Alle Arten sind schon ausgewählt; ändern kannst du das
        jederzeit unter{' '}
        <button type="button" className="btn btn-link inline-link" onClick={() => navigate('benachrichtigungen')}>
          Benachrichtigungen
        </button>
        .
      </p>
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
      <button className="btn btn-block push-hint-on" onClick={enable} disabled={busy}>
        {busy ? 'Einen Moment …' : 'Benachrichtigungen einschalten'}
      </button>
      <button className="btn btn-link btn-block push-hint-later" onClick={later} disabled={busy}>
        Nicht jetzt
      </button>
    </div>
  );
}
