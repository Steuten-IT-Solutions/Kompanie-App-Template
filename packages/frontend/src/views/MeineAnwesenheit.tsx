import { api, ATTENDANCE_LABELS, countsText } from '../api';
import { formatDate } from '../format';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';

/** Meins → Anwesenheit: eigene Einträge, die jüngsten Termine zuerst. */
export default function MeineAnwesenheit() {
  const { data, error, loading, reload } = useAsync(api.myAttendance, [], 'my-attendance');
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const { entries, counts } = data!;
  if (entries.length === 0) return <Empty>Für dich ist noch keine Anwesenheit eingetragen.</Empty>;
  return (
    <div className="my-attendance">
      <p className="attendance-counts">{countsText(counts)}</p>
      <ul className="card-list">
        {entries.map((e) => (
          <li key={e.list_id} className="card row">
            <div className="row-main">
              <div className="row-title">{e.title}</div>
              <div className="row-sub">
                {formatDate(e.date)}
                {e.updated_by_name && ` · eingetragen von ${e.updated_by_name}`}
              </div>
            </div>
            <span className={`badge badge-att badge-att-${e.status}`}>{ATTENDANCE_LABELS[e.status]}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}
