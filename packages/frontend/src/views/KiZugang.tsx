import { useState } from 'react';
import { api, config, type OAuthRequest } from '../api';
import { SCOPE_LABELS } from '../aiAccess';
import { t } from '../i18n';
import { ErrorBox, Loading, useAsync } from '../ui';

/**
 * Anmeldung eines KI-Assistenten (Plan „MCP-Server“, #/ki-zugang/<Schlüssel>): Der Server leitet nach /oauth/authorize
 * hierher. Die angemeldete Person sieht Programm, Ziel der Antwort und Umfang, wählt „Nur lesen“ oder „Lesen und ändern“
 * (höchstens das Erbetene) und stimmt zu oder lehnt ab; danach geht es zurück zum Programm.
 */
export default function KiZugang({ requestKey }: { requestKey: string }) {
  const { data, error, loading } = useAsync<OAuthRequest>(() => api.oauthRequest(requestKey), [requestKey]);
  const [scope, setScope] = useState<'read' | 'write' | null>(null);
  const [busy, setBusy] = useState(false);
  const [decideError, setDecideError] = useState<string | null>(null);

  if (loading && !data) return <Loading shape="form" />;
  if (error || !data) return <ErrorBox message={error ?? t('Diese Anfrage ist abgelaufen. Bitte im KI-Assistenten neu verbinden.')} />;
  const chosen = scope ?? data.scope;
  const scopes: ('read' | 'write')[] = data.scope === 'write' ? ['read', 'write'] : ['read'];

  async function decide(approve: boolean) {
    setBusy(true);
    setDecideError(null);
    try {
      const res = await api.decideOAuthRequest(requestKey, approve, approve ? chosen : undefined);
      // Zurück zum Programm (eine andere Seite): Der Knopf bleibt gesperrt, bis sie lädt.
      window.location.assign(res.redirect);
    } catch (err) {
      setDecideError((err as Error).message);
      setBusy(false);
    }
  }

  return (
    <section className="card form ai-consent stack">
      <h2>{t('KI-Assistent verbinden')}</h2>
      <p>
        {t('„{client}“ möchte als {name} auf {installation} zugreifen, mit deinen Rechten.', {
          client: data.client || data.client_host,
          name: config?.user?.name ?? '',
          installation: data.installation,
        })}
      </p>
      <p className="muted small">
        {!data.client_host
          ? t('Die Antwort geht an {redirect}.', { redirect: data.redirect_host })
          : data.client_host === data.redirect_host
            ? t('Programm von {host}.', { host: data.client_host })
            : t('Programm von {host}, die Antwort geht an {redirect}.', { host: data.client_host, redirect: data.redirect_host })}
      </p>
      {scopes.length > 1 ? (
        <div className="field" role="radiogroup" aria-label={t('Was darf der KI-Assistent?')}>
          <span>{t('Was darf der KI-Assistent?')}</span>
          {scopes.map((s) => (
            <label key={s} className="toggle">
              <input type="radio" name="ai-scope" checked={chosen === s} disabled={busy} onChange={() => setScope(s)} />
              <span>{SCOPE_LABELS[s]}</span>
            </label>
          ))}
        </div>
      ) : (
        <p>{t('Der KI-Assistent darf nur lesen.')}</p>
      )}
      <p className="muted small">
        {t(
          'Nur verbinden, wenn du die Verbindung gerade selbst im KI-Assistenten gestartet hast. Entfernen kannst du den Zugang jederzeit unter Meins → Konto.',
        )}
      </p>
      {decideError && (
        <p className="form-error" role="alert">
          {decideError}
        </p>
      )}
      <button type="button" className="btn btn-block" disabled={busy} onClick={() => void decide(true)}>
        {t('Erlauben')}
      </button>
      <button type="button" className="btn btn-secondary btn-block" disabled={busy} onClick={() => void decide(false)}>
        {t('Ablehnen')}
      </button>
    </section>
  );
}
