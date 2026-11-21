import { api, can, config, isMember, roleLabels, type Me } from '../api';
import { MyAdmission } from './Aufnahmen';
import brand from '../generated/brand';
import { formatEuro } from '../format';
import { navigate } from '../router';
import { DateRow, ErrorBox, lazyView, Loading, StatCard, useAsync } from '../ui';
import Dienstgrad from './Dienstgrad';
import MeineAnwesenheit from './MeineAnwesenheit';
import { MyPassives } from './Passive';
import Punkte from './Punkte';
import Strafen from './Strafen';
import { TitleBadges } from '../TitleBadges';
import { MenuNav } from '../adminNav';
import { ZoomableAvatar } from '../memberPhoto';
import { RankBadge } from '../RankBadge';
import { formatBirthday, ownBirthday } from '../birthdays';
import { joinText, profileChoice } from '../joinYear';
import { PersonOffices } from './Aemter';
import { sources, usePrefetchMenu } from '../viewData';
import { localeTag, t } from '../i18n';

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
  // Meine Passiven: Die Zahl aus der Konfiguration stammt vom Laden der Seite und kann veraltet sein (gespeicherte
  // App-Seite, gerade erst als Betreuer eingetragen). Deshalb mit dem Bereich auch die eigene Liste fragen.
  const passiveOn = member && !!config?.features.passive;
  const cared = useAsync(() => (passiveOn ? api.myPassives() : Promise.resolve([])), [], passiveOn ? 'my-passives' : undefined);
  const dataParts = (
    [
      ['uebersicht', t('Übersicht'), true],
      ['punkte', sec.points, config?.features.points && can('view_own_points')],
      ['strafen', sec.penalties, config?.features.penalties && can('view_own_penalties')],
      ['dienstgrad', brand.labels.rank, config?.features.ranks && can('view_own_rank')],
      ['anwesenheit', sec.attendance, config?.features.attendance && can('view_own_attendance')],
      // Passive, die die Person betreut (Bereich passive): nur, wenn es welche gibt
      ['passive', t('Meine Passiven'), passiveOn && ((config?.caredPassives ?? 0) > 0 || (cared.data?.length ?? 0) > 0)],
    ] as const
  )
    .filter(([, , on]) => member && on)
    .map(([id, label]) => [id, label] as const);
  const parts = [...dataParts, ['konto', t('Konto')] as const];
  const active = parts.find(([id]) => id === sub)?.[0] ?? parts[0]?.[0];
  // Die anderen Seiten von „Meins“ im Leerlauf vorab laden: Sie erscheinen dann schon beim ersten Öffnen ohne Warten.
  usePrefetchMenu(
    'meins',
    parts.map(([id]) => id).filter((id) => id !== active),
  );
  return (
    <div>
      {parts.length > 1 && (
        <MenuNav
          label={t('Meine Daten')}
          className="meins-menu"
          active={active}
          items={parts.map(([id, label]) => ({ id, label, onSelect: () => navigate(`meins/${id}`) }))}
        />
      )}
      {active === 'konto' ? (
        <Konto onToast={onToast} onLogout={onLogout} />
      ) : active === 'passive' ? (
        <MyPassives focus={focus} />
      ) : me.loading && !me.data ? (
        <Loading shape={active === 'uebersicht' ? 'overview' : active === 'anwesenheit' ? 'list' : 'stat'} />
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
  const birthdayOn = ownBirthday();
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
        {(me.extra_roles ?? []).length > 0
          ? t('Rollen: {roles}', { roles: roleLabels(me) })
          : t('Rolle: {roles}', { roles: roleLabels(me) })}
      </p>
      {/* Am Computer zwei Spalten wie beim Mitglied in der Verwaltung: links Kacheln, Titel und Daten, rechts Ämter */}
      <div className="member-columns">
        <div className="member-col">
          {((f?.points && can('view_own_points')) || (f?.penalties && can('view_own_penalties'))) && (
            <div className="stats member-stats">
              {f.points && can('view_own_points') && (
                <StatCard
                  label={sec.points}
                  value={me.points.toLocaleString(localeTag())}
                  tone="good"
                  onClick={() => navigate('meins/punkte')}
                />
              )}
              {f.penalties && can('view_own_penalties') && (
                <StatCard
                  label={t('Offen')}
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
                {me.titles.length > 0 ? <TitleBadges titles={me.titles} /> : <span className="muted">{t('Keine')}</span>}
              </span>
            </div>
          )}
          {(birthday.data || joinYear.data) && (
            <ul className="card member-dates">
              {birthday.data && (
                <DateRow label={t('Geburtstag')} onOpen={toAccount}>
                  {birthday.data.birthday ? formatBirthday(birthday.data.birthday) : null}
                </DateRow>
              )}
              {joinYear.data && (
                <DateRow label={t('Eintritt')} onOpen={profileChoice() ? toAccount : undefined}>
                  {joinYear.data.join_year ? joinText(joinYear.data.join_year) : null}
                </DateRow>
              )}
            </ul>
          )}
        </div>
        <div className="member-col">
          {offices && (
            <section className="member-offices">
              <h3 className="section-title">{sec.offices}</h3>
              <PersonOffices userId={config!.user!.id} empty={t('Du hast keine {offices} eingetragen.', { offices: sec.offices })} />
            </section>
          )}
          {config?.features.attendance && <MyAdmission />}
        </div>
      </div>
    </div>
  );
}
