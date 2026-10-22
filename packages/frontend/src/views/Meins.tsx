import { api, config, isMember } from '../api';
import brand from '../generated/brand';
import { navigate } from '../router';
import { Empty, ErrorBox, lazyView, Loading, useAsync } from '../ui';
import Dienstgrad from './Dienstgrad';
import MeineAnwesenheit from './MeineAnwesenheit';
import Punkte from './Punkte';
import Strafen from './Strafen';
import { TitleBadges } from '../TitleBadges';
import { MenuNav } from '../adminNav';

const Konto = lazyView(() => import('./Konto'));

/**
 * „Meins“: eigene Titel oben, darunter Punkte, Strafen, Dienstgrad, Anwesenheit und das Konto, umschaltbar. Die Summen
 * (/me) lädt Meins einmal für alle, nur für Mitglieder (das Konto haben alle Angemeldeten).
 */
export default function Meins({ sub, onToast, onLogout }: { sub?: string; onToast: (msg: string) => void; onLogout: () => void }) {
  const member = isMember();
  const me = useAsync(() => (member ? api.me() : Promise.resolve(null)), [], 'me');
  const dataParts = (
    [
      ['punkte', brand.labels.sections.points, config?.features.points],
      ['strafen', brand.labels.sections.penalties, config?.features.penalties],
      ['dienstgrad', brand.labels.rank, config?.features.ranks],
      ['anwesenheit', brand.labels.sections.attendance, config?.features.attendance],
    ] as const
  )
    .filter(([, , on]) => member && on)
    .map(([id, label]) => [id, label] as const);
  const parts = [...dataParts, ['konto', 'Konto'] as const];
  const active = parts.find(([id]) => id === sub)?.[0] ?? parts[0]?.[0];
  const titles = me.data?.titles ?? [];
  return (
    <div>
      {titles.length > 0 && (
        <p className="my-titles" aria-label={brand.labels.sections.titles}>
          <TitleBadges titles={titles} />
        </p>
      )}
      {dataParts.length === 0 && config?.features.titles && me.data && titles.length === 0 && (
        <Empty>Du hast zurzeit keine {brand.labels.sections.titles}.</Empty>
      )}
      {parts.length > 1 && (
        <MenuNav
          label="Meine Daten"
          className="meins-menu"
          active={active}
          items={parts.map(([id, label]) => ({ id, label, onSelect: () => navigate(`meins/${id}`) }))}
        />
      )}
      {active === 'konto' ? (
        <Konto onToast={onToast} onLogout={onLogout} />
      ) : me.loading && !me.data ? (
        <Loading />
      ) : me.error && !me.data ? (
        <ErrorBox message={me.error} onRetry={me.reload} />
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
