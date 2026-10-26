import { config, isMember, roleLabels, type Me } from '../api';
import brand from '../generated/brand';
import { formatEuro } from '../format';
import { navigate } from '../router';
import { DateRow, ErrorBox, lazyView, Loading, StatCard, useAsync } from '../ui';
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
import { sources } from '../viewData';

const sec = brand.labels.sections;

const Konto = lazyView(() => import('./Konto'));

/**
 * „Meins“: Übersicht (mit Titeln, Daten und Ämtern), Punkte, Strafen, Dienstgrad, Anwesenheit und das Konto,
 * umschaltbar. Die Summen (/me) lädt Meins einmal für alle, nur für Mitglieder; das Konto haben alle Angemeldeten.
 * Ältere Links auf meins/aemter landen in der Übersicht. meins/punkte/<id> und meins/strafen/<id> springen zu einem
 * Eintrag (Ziel einer Benachrichtigung, Issue #190).
 */
export default function Meins({
  sub,
  focus,
  onToast,
  onLogout,
}: {
  sub?: string;
  focus?: string;
  onToast: (msg: string) => void;
  onLogout: () => void;
}) {
  const member = isMember();
  const me = useAsync(() => (member ? sources.me.load() : Promise.resolve(null)), [], sources.me.key);
  const dataParts = (
    [
      ['uebersicht', 'Übersicht', true],
      ['punkte', sec.points, config?.features.points],
      ['strafen', sec.penalties, config?.features.penalties],
      ['dienstgrad', brand.labels.rank, config?.features.ranks],
      ['anwesenheit', sec.attendance, config?.features.attendance],
    ] as const
  )
    .filter(([, , on]) => member && on)
    .map(([id, label]) => [id, label] as const);
  const parts = [...dataParts, ['konto', 'Konto'] as const];
  const active = parts.find(([id]) => id === sub)?.[0] ?? parts[0]?.[0];
  return (
    <div>
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
      ) : active === 'uebersicht' ? (
        <Uebersicht me={me.data!} />
      ) : active === 'punkte' ? (
        // Neu aufbauen, wenn eine Benachrichtigung bei offener Seite auf einen Eintrag zeigt: lädt dann frisch.
        <Punkte key={focus} me={me.data!} focus={focus} />
      ) : active === 'strafen' ? (
        <Strafen key={focus} me={me.data!} focus={focus} />
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
 * und Abzeichen zur passenden Seite von „Meins“, Geburtstag und Eintritt zu „Konto“.
 */
function Uebersicht({ me }: { me: Me }) {
  const f = config?.features;
  // Foto: hochladen und ändern nur, wer Mitglieder anlegt (in der Verwaltung), wie unter „Konto“.
  const photo = config?.user?.id === me.id ? config.user.photo_url : null;
  const member = isMember();
  const birthdayOn = birthdaysVisible();
  const birthday = useAsync(() => (birthdayOn ? sources.myBirthday.load() : Promise.resolve(null)), [], sources.myBirthday.key);
  const joinYear = useAsync(() => (member ? sources.myJoinYear.load() : Promise.resolve(null)), [], sources.myJoinYear.key);
  // Ändern lassen sich beide unter „Konto“; den Eintritt gibt es dort nur mit der Mitgliederliste.
  const toAccount = () => navigate('meins/konto');
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
