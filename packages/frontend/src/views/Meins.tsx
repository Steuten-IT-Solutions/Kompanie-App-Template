import { api, can, config, roleLabels, type Me } from '../api';
import brand from '../generated/brand';
import { formatEuro } from '../format';
import { navigate } from '../router';
import { DateRow, ErrorBox, Loading, StatCard, useAsync } from '../ui';
import Dienstgrad from './Dienstgrad';
import MeineAnwesenheit from './MeineAnwesenheit';
import Punkte from './Punkte';
import Strafen from './Strafen';
import { TitleBadges } from '../TitleBadges';
import { MenuNav } from '../adminNav';
import { ZoomableAvatar } from '../memberPhoto';
import { RankBadge } from '../RankBadge';
import { birthdaysVisible, formatBirthday } from '../birthdays';
import { joinText, profileChoice } from '../joinYear';
import { PersonOffices } from './Aemter';

const sec = brand.labels.sections;

/**
 * „Meins“: Übersicht (mit Titeln, Daten und Ämtern), Punkte, Strafen, Dienstgrad und Anwesenheit, umschaltbar. Die
 * Summen (/me) lädt Meins einmal für alle. Ältere Links auf meins/aemter landen in der Übersicht.
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

/**
 * „Meins“ → Übersicht: aufgebaut wie die Mitgliedsdetails der Verwaltung (Name, Abzeichen und Foto, Rolle, Kacheln,
 * Titel, Geburtstag und Eintritt, Ämter), nur zum Ansehen. Antippen führt dorthin, wo die Einzelheiten stehen: Kacheln
 * und Abzeichen zur passenden Seite von „Meins“, Geburtstag und Eintritt zu „Mein Konto“.
 */
function Uebersicht({ me }: { me: Me }) {
  const f = config?.features;
  // Foto: hochladen und ändern nur, wer Mitglieder anlegt (in der Verwaltung), wie unter „Mein Konto“.
  const photo = config?.user?.id === me.id ? config.user.photo_url : null;
  const member = can('member');
  const birthdayOn = birthdaysVisible();
  const birthday = useAsync(() => (birthdayOn ? api.myBirthday() : Promise.resolve(null)), [], 'me/birthday');
  const joinYear = useAsync(() => (member ? api.myJoinYear() : Promise.resolve(null)), [], 'me/join-year');
  // Ändern lassen sich beide unter „Mein Konto“; den Eintritt gibt es dort nur mit der Mitgliederliste.
  const toAccount = () => navigate('konto');
  const rank = f?.ranks ? me.rank : null;
  const offices = !!f?.offices && !!config?.user;
  return (
    <div className="my-overview">
      <h2 className={`member-name member-name-centered account-name${rank || photo ? ' with-rank' : ''}`}>{me.name}</h2>
      {(rank || photo) && (
        <div className="member-rank-line">
          {rank && (
            <button type="button" className="member-rank-badge member-rank-open" onClick={() => navigate('meins/dienstgrad')}>
              <RankBadge url={rank.badge_url} title={rank.title} size="large" />
              <span>{rank.title}</span>
            </button>
          )}
          {photo && <ZoomableAvatar url={photo} name={me.name} />}
        </div>
      )}
      <p className="muted small member-level">
        {(me.extra_roles ?? []).length > 0 ? 'Rollen' : 'Rolle'}: {roleLabels(me)}
      </p>
      {(f?.points || f?.penalties) && (
        <div className="stats member-stats">
          {f.points && (
            <StatCard label={sec.points} value={me.points.toLocaleString('de-DE')} tone="good" onClick={() => navigate('meins/punkte')} />
          )}
          {f.penalties && (
            <StatCard
              label="Offen"
              value={formatEuro(me.open_cents)}
              tone={me.open_cents > 0 ? 'warn' : 'good'}
              onClick={() => navigate('meins/strafen')}
            />
          )}
        </div>
      )}
      {f?.titles && me.titles !== undefined && (
        <div className="card member-tile member-titles-tile my-titles">
          <span className="member-tile-label">{sec.titles}</span>
          <span className="member-tile-value">
            {me.titles.length > 0 ? <TitleBadges titles={me.titles} /> : <span className="muted">Keine</span>}
          </span>
        </div>
      )}
      {(birthday.data || joinYear.data) && (
        <ul className="card member-dates">
          {birthday.data && (
            <DateRow label="Geburtstag" onOpen={toAccount}>
              {birthday.data.birthday ? formatBirthday(birthday.data.birthday) : null}
            </DateRow>
          )}
          {joinYear.data && (
            <DateRow label="Eintritt" onOpen={profileChoice() ? toAccount : undefined}>
              {joinYear.data.join_year ? joinText(joinYear.data.join_year) : null}
            </DateRow>
          )}
        </ul>
      )}
      {offices && (
        <section className="member-offices">
          <h3 className="section-title">{sec.offices}</h3>
          <PersonOffices userId={config!.user!.id} empty={`Du hast keine ${sec.offices} eingetragen.`} />
        </section>
      )}
    </div>
  );
}
