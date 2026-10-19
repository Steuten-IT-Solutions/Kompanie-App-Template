import { api, type Me, type Penalty } from '../api';
import brand from '../generated/brand';
import { formatDate, formatEuro } from '../format';
import { Empty, ErrorBox, Loading, StatCard, useAsync } from '../ui';

export function PenaltyRow({ p, actions }: { p: Penalty; actions?: React.ReactNode }) {
  return (
    <li className="card row">
      <div className="row-main">
        <div className="row-title">{p.reason}</div>
        <div className="row-sub">
          {formatDate(p.date)}
          {' · '}
          <span className={`badge badge-${p.status}`}>{p.status === 'paid' ? 'bezahlt' : 'offen'}</span>
        </div>
        {actions && <div className="row-actions">{actions}</div>}
      </div>
      <div className={`row-value ${p.status === 'paid' ? 'muted' : 'neg'}`}>{formatEuro(p.amount_cents)}</div>
    </li>
  );
}

export default function Strafen({ me }: { me: Me }) {
  const { data, error, loading, reload } = useAsync(api.myPenalties, [], 'me/penalties');

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const penalties = data!;
  const open = penalties.filter((p) => p.status === 'open');
  const paid = penalties.filter((p) => p.status === 'paid');

  return (
    <div>
      <StatCard label={`Offen: ${brand.labels.sections.penalties}`} value={formatEuro(me.open_cents)} tone={me.open_cents > 0 ? 'warn' : 'good'} />
      {penalties.length === 0 && <Empty>Keine Einträge – vorbildlich!</Empty>}
      {open.length > 0 && (
        <>
          <h2 className="section-title">Offen</h2>
          <ul className="card-list">
            {open.map((p) => (
              <PenaltyRow key={p.id} p={p} />
            ))}
          </ul>
        </>
      )}
      {paid.length > 0 && (
        <>
          <h2 className="section-title">Bezahlt</h2>
          <ul className="card-list">
            {paid.map((p) => (
              <PenaltyRow key={p.id} p={p} />
            ))}
          </ul>
        </>
      )}
    </div>
  );
}
