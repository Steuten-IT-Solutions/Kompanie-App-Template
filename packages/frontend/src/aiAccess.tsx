import { useState } from 'react';
import type { AiAccess, AiToken } from './api';
import { confirmAsk } from './ask';
import { dateFormat } from './format';
import { IconButton } from './icons';
import { t } from './i18n';

/** Zugang für KI-Assistenten (Plan „MCP-Server“): Adresse zum Eintragen und Liste der Zugänge, unter Meins → Konto und in den Einstellungen. */

const day = dateFormat({ day: 'numeric', month: 'numeric', year: 'numeric' });
const when = (unix: number) => day.format(new Date(unix * 1000));

export const SCOPE_LABELS: Record<AiToken['scope'], string> = {
  read: t('Nur lesen'),
  write: t('Lesen und ändern'),
};

/** Adresse des MCP-Endpunkts mit Knopf zum Kopieren; ohne Anmeldung per OAuth ein Hinweis, warum es nicht geht. */
export function McpUrl({ access, onToast }: { access: AiAccess; onToast: (msg: string) => void }) {
  const url = access.mcp_url;
  if (!url) return null;
  if (access.oauth === false) {
    return (
      <p className="small muted ai-access-unavailable">
        {t(
          'Die Anmeldung aus einem KI-Assistenten geht nur mit schönen Adressen (WordPress: Permalinks; eigenständige Version: Adressen ohne „index.php?r=“).',
        )}
      </p>
    );
  }
  return (
    <div className="field ai-access-url">
      <span>{t('Adresse für den KI-Assistenten (z. B. in Claude als eigenen Konnektor eintragen)')}</span>
      <code className="settings-link-code">{url}</code>
      <button
        type="button"
        className="btn btn-secondary"
        onClick={() =>
          void navigator.clipboard?.writeText(url).then(
            () => onToast(t('Adresse kopiert')),
            () => onToast(t('Kopieren hat nicht geklappt')),
          )
        }
      >
        {t('Adresse kopieren')}
      </button>
    </div>
  );
}

/** Liste der Zugänge mit Widerrufen; mit Namen der Person, wenn die Liste alle zeigt. */
export function AiTokenList({
  tokens,
  onRevoke,
  onToast,
}: {
  tokens: AiToken[];
  onRevoke: (id: number) => Promise<unknown>;
  onToast: (msg: string) => void;
}) {
  const [busy, setBusy] = useState<number | null>(null);
  if (tokens.length === 0) return <p className="muted small">{t('Noch kein KI-Assistent verbunden.')}</p>;

  async function revoke(token: AiToken) {
    const question = token.user_name
      ? t('Zugang von „{client}“ für {name} entfernen? Der KI-Assistent muss danach neu verbunden werden.', {
          client: token.client,
          name: token.user_name,
        })
      : t('Zugang von „{client}“ entfernen? Der KI-Assistent muss danach neu verbunden werden.', { client: token.client });
    if (!(await confirmAsk(question, { okLabel: t('Entfernen') }))) return;
    setBusy(token.id);
    try {
      await onRevoke(token.id);
      onToast(t('Zugang entfernt'));
    } catch (err) {
      onToast((err as Error).message);
    } finally {
      setBusy(null);
    }
  }

  return (
    <ul className="card-list ai-token-list">
      {tokens.map((tok) => (
        <li key={tok.id} className="card row ai-token">
          <div className="row-main">
            <div className="row-title">
              {tok.client || t('Unbekanntes Programm')}
              {tok.user_name && ` · ${tok.user_name}`}
            </div>
            <div className="row-sub">
              {SCOPE_LABELS[tok.scope]} ·{' '}
              {tok.expired
                ? t('abgelaufen')
                : tok.last_used_at
                  ? t('zuletzt benutzt am {date}', { date: when(tok.last_used_at) })
                  : t('verbunden am {date}', { date: when(tok.created_at) })}
            </div>
          </div>
          <IconButton icon="delete" label={t('Entfernen')} variant="danger" disabled={busy === tok.id} onClick={() => void revoke(tok)} />
        </li>
      ))}
    </ul>
  );
}
