import { api, config, roleLabels, type Me } from '../api';
import brand from '../generated/brand';
import { formatEuro } from '../format';
import { navigate } from '../router';
import { ErrorBox, Loading, StatCard, useAsync } from '../ui';
import Dienstgrad from './Dienstgrad';
import MeineAnwesenheit from './MeineAnwesenheit';
import Punkte from './Punkte';
import Strafen from './Strafen';
import { TitleBadges } from '../TitleBadges';
import { MenuNav } from '../adminNav';
import { MemberAvatar } from '../memberPhoto';
import { RankLabel } from '../RankBadge';

const sec = brand.labels.sections;

/**
 * „Meins“: Übersicht (mit den eigenen Titeln), Punkte, Strafen, Dienstgrad und Anwesenheit, umschaltbar. Die Summen
 * (/me) lädt Meins einmal für alle.
 */
export default function Meins({ sub }: { sub?: string }) {
  const me = useAsync(api.me, [], 'me');
  const parts = (
    [
      ['uebersicht', 'Übersicht', true],
      ['punkte', sec.points, config?.features.points],
      ['strafen', sec.penalties, config?.features.penalties],
      ['dienstgrad', brand.labels.rank, config?.features.ranks],
      ['anwesenheit', sec.attendance, config?.features.attendance],
    ] as const
  ).filter(([, , on]) => on);
  const active = parts.find(([id]) => id === sub)?.[0] ?? parts[0]?.[0];
  return (
    <div>
      {parts.length > 1 && active && (
        <MenuNav
          label="Meine Daten"
          className="meins-menu"
          active={active}
          items={parts.map(([id, label]) => ({ id, label, onSelect: () => navigate(`meins/${id}`) }))}
        />
      )}
      {me.loading && !me.data ? (
        <Loading />
      ) : me.error && !me.data ? (
        <ErrorBox message={me.error} onRetry={me.reload} />
      ) : active === 'uebersicht' ? (
        <Uebersicht me={me.data!} />
      ) : active === 'punkte' ? (
        <Punkte me={me.data!} />
      ) : active === 'strafen' ? (
        <Strafen me={me.data!} />
      ) : active === 'dienstgrad' ? (
        <Dienstgrad />
      ) : active === 'anwesenheit' ? (
        <MeineAnwesenheit />
      ) : null}
    </div>
  );
}

/** „Meins“ → Übersicht: die eigenen Angaben wie in den Mitgliedsdetails der Verwaltung, nur zum Ansehen. */
function Uebersicht({ me }: { me: Me }) {
  const f = config?.features;
  // Foto: hochladen und ändern nur, wer Mitglieder anlegt (in der Verwaltung), wie unter „Mein Konto“.
  const photo = config?.user?.id === me.id ? config.user.photo_url : null;
  return (
    <div className="my-overview">
      <div className="card account-card">
        {photo && <MemberAvatar url={photo} name={me.name} size="large" />}
        <h2 className="account-name">{me.name}</h2>
        <p className="muted small">
          {(me.extra_roles ?? []).length > 0 ? 'Rollen' : 'Rolle'}: {roleLabels(me)}
        </p>
        {f?.ranks && me.rank !== undefined && (
          <p className="my-overview-rank">
            {me.rank ? (
              <>
                {brand.labels.rank}: <RankLabel url={me.rank.badge_url} title={me.rank.title} />
              </>
            ) : (
              `Noch kein ${brand.labels.rank}`
            )}
          </p>
        )}
      </div>
      {(f?.points || f?.penalties) && (
        <div className="stats">
          {f.points && <StatCard label={`Meine ${sec.points}`} value={me.points.toLocaleString('de-DE')} tone="good" />}
          {f.penalties && (
            <StatCard label={`Offen: ${sec.penalties}`} value={formatEuro(me.open_cents)} tone={me.open_cents > 0 ? 'warn' : 'good'} />
          )}
        </div>
      )}
      {f?.titles && me.titles !== undefined && (
        <div className="card account-card my-titles">
          <h3>{sec.titles}</h3>
          {me.titles.length > 0 ? <TitleBadges titles={me.titles} /> : <p className="muted small">Du hast zurzeit keine {sec.titles}.</p>}
        </div>
      )}
    </div>
  );
}
