import { useEffect, useState } from 'react';
import { api, config, type PushStatus } from './api';
import { enablePush, pushSupport } from './push';
import { pushHintDue, pushHintLater } from './pushHint';
import { previewRole } from './preview';
import { navigate } from './router';
import { Sheet } from './ui';

// Bis zum nächsten Start der App nicht wieder öffnen (z. B. nach dem Link zur Seite Benachrichtigungen).
let closedThisRun = false;

/**
 * Fenster „Nichts mehr verpassen“ gleich nach der Anmeldung, auf jeder Seite: für angemeldete Mitglieder, deren Browser
 * auf diesem Gerät noch nie nach Benachrichtigungen gefragt hat (pushHint.ts). Die Abfrage des Browsers kommt erst
 * nach dem Tippen auf „Einschalten“ (Browser zeigen sie nur nach einer Nutzeraktion); dann meldet sich das Gerät mit
 * allen angebotenen Arten an (wie unter Konto → Benachrichtigungen). „Nicht jetzt“, ✕ und „Zurück“ blenden es für eine
 * Weile aus; nach „Blockieren“ im Browser fragt die App nicht wieder. Ohne Push auf dem Server oder ohne Arten bleibt
 * es zu.
 */
export default function PushHint({ onToast }: { onToast: (msg: string) => void }) {
  const eligible = !closedThisRun && !!config?.user && !previewRole() && pushSupport() === 'ok' && pushHintDue(Notification.permission);
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
      .catch(() => undefined); // offline o. ä.: dann eben kein Fenster
    return () => {
      alive = false;
    };
  }, [eligible]);

  if (!eligible || hidden || !status || !status.supported || status.types.length === 0) return null;

  const close = () => {
    closedThisRun = true;
    setHidden(true);
  };

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
      close();
      onToast('Benachrichtigungen sind an');
    } catch (err) {
      setError((err as Error).message);
      if (Notification.permission === 'denied') pushHintLater();
    } finally {
      setBusy(false);
    }
  }

  const later = () => {
    if (busy) return;
    pushHintLater();
    close();
  };

  return (
    <Sheet title="Nichts mehr verpassen" onClose={later}>
      <div className="push-hint stack">
        <p>
          Lass dich auf diesem Gerät benachrichtigen, wenn es Neues für dich gibt. Alle Arten sind schon ausgewählt; ändern kannst du das
          jederzeit unter{' '}
          <button
            type="button"
            className="btn btn-link inline-link"
            onClick={() => {
              close();
              navigate('benachrichtigungen');
            }}
          >
            Benachrichtigungen
          </button>
          .
        </p>
        <p className="small muted">Nach dem Tippen fragt dein Browser, ob die App Benachrichtigungen senden darf.</p>
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
    </Sheet>
  );
}
