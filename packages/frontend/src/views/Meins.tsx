import { api, config } from '../api';
import brand from '../generated/brand';
import { navigate } from '../router';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';
import Dienstgrad from './Dienstgrad';
import MeineAnwesenheit from './MeineAnwesenheit';
import Punkte from './Punkte';
import Strafen from './Strafen';
import { TitleBadges } from '../TitleBadges';
import { MenuNav } from '../adminNav';

/**
 * „Meins“: eigene Titel oben, darunter Punkte, Strafen und Dienstgrad, umschaltbar. Die Summen (/me) lädt Meins einmal
 * für alle.
 */
export default function Meins({ sub }: { sub?: string }) {
  const me = useAsync(api.me, [], 'me');
  const parts = (
    [
      ['punkte', brand.labels.sections.points, config?.features.points],
      ['strafen', brand.labels.sections.penalties, config?.features.penalties],
      ['dienstgrad', brand.labels.rank, config?.features.ranks],
      ['anwesenheit', brand.labels.sections.attendance, config?.features.attendance],
    ] as const
  ).filter(([, , on]) => on);
  const active = parts.find(([id]) => id === sub)?.[0] ?? parts[0]?.[0];
  const titles = me.data?.titles ?? [];
  return (
    <div>
      {titles.length > 0 && (
        <p className="my-titles" aria-label={brand.labels.sections.titles}>
          <TitleBadges titles={titles} />
        </p>
      )}
      {parts.length === 0 && me.data && titles.length === 0 && <Empty>Du hast zurzeit keine {brand.labels.sections.titles}.</Empty>}
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
