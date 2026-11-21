import { useState, type ReactNode } from 'react';
import { defaultSections } from '../generated/brand';
import { adminTabs, adminTileCatalog } from '../adminNav';
import { SOURCE_LOCALE, t } from '../i18n';
import { useEditLocale } from '../language';
import { setNameTranslations, translatedName } from '../names';
import { api, applyNavigation, config, levelLabel, type Capability, type Feature, type NavigationAdmin, type PageSummary } from '../api';
import { useDragSort } from '../dragSort';
import {
  draftError,
  draftOf,
  guestDraftOf,
  INTERN_TARGETS,
  internOrderOf,
  isPageTarget,
  mergeOrder,
  orderToSave,
  SETUP,
  setupOrder,
  startOrder,
  moveTo,
  NAV_ICONS,
  NAV_TARGETS,
  pageAllowedForGuests,
  pageTarget,
  placeOf,
  removeMenu,
  startChoices,
  targetAllowed,
  targetIcon,
  unplaced,
  type FixedTarget,
  type NavigationSetting,
  type NavMenuSetting,
  type NavNames,
  type NavPlace,
  type NavStart,
  type NavTarget,
  type PageTarget,
} from '../navigation';
import { ErrorBox, Loading, useAsync } from '../ui';
import { confirmAsk } from '../ask';

const ICON_LABELS: Record<string, string> = {
  ordner: t('Ordner'),
  termine: t('Kalender'),
  news: t('Zeitung'),
  galerie: t('Bild'),
  kompanien: t('Schild'),
  sponsoren: t('Herz'),
  mitglieder: t('Personen'),
  dokumente: t('Dokument'),
  brett: t('Brett'),
  dienstgrade: t('Abzeichen'),
  punktekatalog: t('Liste'),
  seiten: t('Seite'),
  stern: t('Stern'),
  info: t('Info'),
  karte: t('Karte'),
  musik: t('Musik'),
  pokal: t('Pokal'),
  glocke: t('Glocke'),
  fahne: t('Fahne'),
  kamera: t('Kamera'),
  haus: t('Haus'),
  buch: t('Buch'),
  uhr: t('Uhr'),
};

/** Ab so vielen Punkten unten (mit Verwaltung und Meins) wird es auf schmalen Handys eng (Issue #362). */
const CROWDED = 8;

/** Standardname eines Bereichs (kompanie.json bzw. Standard), ohne eigenen Namen der Installation. */
function defaultLabel(t: string, pages: PageSummary[]): string {
  const sec = defaultSections;
  const f = config?.features;
  const fixed: Record<string, string> = {
    termine: sec.events,
    news: sec.news,
    galerie: sec.gallery,
    kompanien: sec.companies,
    sponsoren: sec.sponsors,
    mitglieder: f?.directory || !f?.offices ? sec.directory : sec.offices,
    dokumente: sec.documents,
    brett: sec.board,
    dienstgrade: sec.ranks,
    punktekatalog: sec.catalog,
    intern: sec.intern,
  };
  return fixed[t] ?? pages.find((p) => pageTarget(p.id) === t)?.title ?? t;
}

const featureOn = (f: Feature): boolean => !!config?.features[f];

/** Ist der Bereich an (sieht ihn mit den nötigen Rechten überhaupt jemand)? Sonst steht er für niemanden in der Leiste. */
const isOn = (t: NavTarget): boolean => (isPageTarget(t) ? featureOn('pages') : targetAllowed(t, () => true, featureOn));

/** Rechte der Rolle Gast; sie gelten auch ohne Anmeldung. */
const guestCan = (cap: Capability): boolean => (config?.guestCapabilities ?? []).includes(cap);

type Labels = Record<string, string | undefined>;

/** Bezeichnung der Rolle Gast in dieser Installation. */
const GUEST_LABEL = (): string => levelLabel('guest');

/**
 * Einstellungen → Navigation (Issue #277): Hauptpunkte der Tab-Leiste ordnen, Bereiche in Untermenüs schieben oder
 * ausblenden. „Verwaltung“ und „Meins“ stehen immer am Ende. Nur Darstellung: Jeder sieht weiter nur, was er sehen darf.
 * Ohne gespeicherte Einstellung gilt die Standardnavigation; der Entwurf beginnt dann mit ihr.
 */
export default function NavigationEinstellen({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(api.navigation, [], 'navigation');
  // Eigene Seiten (Issue #287): jede ist ein eigener Punkt; Titel aktuell vom Server (auch gerade angelegte).
  const pagesOn = !!config?.features.pages;
  const pages = useAsync(pagesOn ? api.pages : () => Promise.resolve([] as PageSummary[]), [pagesOn]);
  if ((loading && !data) || (pages.loading && !pages.data)) return <Loading shape="form" />;
  if (error || !data) return <ErrorBox message={error ?? t('Die Navigation konnte nicht geladen werden.')} onRetry={reload} />;
  if (pages.error || !pages.data)
    return <ErrorBox message={pages.error ?? t('Die Seiten konnten nicht geladen werden.')} onRetry={pages.reload} />;
  return (
    <Editor
      key={JSON.stringify([
        data.navigation,
        data.guest_navigation,
        data.names,
        data.start,
        data.show_labels,
        data.intern_order,
        data.admin_order,
      ])}
      data={data}
      pages={pages.data}
      onSaved={setData}
      onToast={onToast}
    />
  );
}

function Editor({
  data,
  pages,
  onSaved,
  onToast,
}: {
  data: NavigationAdmin;
  pages: PageSummary[];
  onSaved: (d: NavigationAdmin) => void;
  onToast: (msg: string) => void;
}) {
  const pageTargets: PageTarget[] = pages.map((p) => pageTarget(p.id));
  const known = (t: string): t is NavTarget => NAV_TARGETS.includes(t as FixedTarget) || pageTargets.includes(t as PageTarget);
  // Eigene Namen und Symbole, Startseiten, Namen in der Leiste (Issue #362); leer kommt vom Server ggf. als [].
  const savedNames: NavNames = Array.isArray(data.names) ? {} : data.names;
  const savedStart: NavStart = Array.isArray(data.start) ? {} : data.start;
  const [names, setNames] = useState<NavNames>(savedNames);
  // Einrichten auf einer weiteren Sprache: Namen dieser Sprache (names.ts), der deutsche bleibt, wie er ist
  const editLang = useEditLocale();
  const foreign = editLang !== SOURCE_LOCALE;
  const savedForeign = (key: string): string => translatedName('nav', key, editLang);
  const [foreignNames, setForeignNames] = useState<Record<string, string>>({});
  const foreignValue = (key: string): string => foreignNames[key] ?? savedForeign(key);
  const [start, setStart] = useState<NavStart>(savedStart);
  const [showLabels, setShowLabels] = useState(data.show_labels);
  const internLabel = savedNames.intern?.label || defaultLabel('intern', pages);
  const savedInternOrder = data.intern_order ?? [];
  const [initial] = useState<NavigationSetting>(() => draftOf(data.navigation, isOn, pageTargets, internLabel, savedInternOrder));
  // Reihenfolge der Verwaltung (für alle gleich), je Gruppe; die Kacheln bleiben in ihrer Gruppe.
  const [adminStart, setAdminStart] = useState<string[]>(() => startOrder(data.admin_order));
  const [adminSetup, setAdminSetup] = useState<string[]>(() => setupOrder(data.admin_order));
  const [draft, setDraft] = useState<NavigationSetting>(initial);
  // Leiste für Gäste (ohne Anmeldung und mit der Grundrolle Gast): eigene oder dieselbe wie für Angemeldete.
  const savedGuest = data.guest_navigation ?? null;
  const [who, setWho] = useState<'member' | 'guest'>('member');
  const [guestOwn, setGuestOwn] = useState(savedGuest !== null);
  const [guestDraft, setGuestDraft] = useState<NavigationSetting>(() =>
    savedGuest !== null ? draftOf(savedGuest) : guestDraftOf(initial),
  );
  const editingGuest = who === 'guest';
  // Sehen Gäste den Punkt (Bereich an, Recht der Rolle Gast, bei eigenen Seiten deren Zielgruppe)?
  const audience = new Map<string, string>(pages.map((p) => [pageTarget(p.id), p.audience]));
  const forGuests = (t: NavTarget): boolean =>
    isPageTarget(t) ? featureOn('pages') && pageAllowedForGuests(audience.get(t), guestCan) : targetAllowed(t, guestCan, featureOn);
  // Zur Auswahl steht nur, was in der gerade bearbeiteten Leiste erscheinen kann. Der Rest behält im Entwurf seinen Platz
  // (auch gespeicherte Punkte), erscheint aber nicht: Wird der Bereich eingeschaltet bzw. bekommt Gast das Recht, steht er
  // wieder hier.
  const avail = (t: string): t is NavTarget => known(t) && (editingGuest ? forGuests(t) : isOn(t));
  // Entwurf, den die Liste gerade zeigt und ändert
  const cur = editingGuest ? guestDraft : draft;
  const [busy, setBusy] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const custom = data.navigation !== null;
  // Unverändert aus der Standardnavigation, höchstens „Intern“ anders geordnet: Sie bleibt (auch mit eigenen Namen), statt
  // zur eigenen Navigation zu werden; die Reihenfolge in „Intern“ wird dann eigens gespeichert.
  const internOrder = custom ? null : internOrderOf(initial, draft);
  const keepDefault = internOrder !== null;
  const labels: Labels = Object.fromEntries(
    [...NAV_TARGETS, ...pageTargets, 'intern'].map((t) => [t, names[t]?.label?.trim() || defaultLabel(t, pages)]),
  );
  const guestProblem = guestOwn ? draftError(guestDraft, data.label_max) : null;
  const problem =
    draftError(draft, data.label_max) ??
    (guestProblem ? t('Leiste für Gäste: {problem}', { problem: guestProblem }) : null) ??
    namesError(names, data.label_max);
  const menus = cur.items.filter((i): i is NavMenuSetting => typeof i !== 'string');
  const newOnes = unplaced(cur, pageTargets).filter(avail);
  // Hauptpunkte in der Liste: Bereiche, die erscheinen können, und alle Untermenüs (ein leeres meldet draftError)
  const shownItems = cur.items.filter((i) => typeof i !== 'string' || avail(i));
  const shownCount = shownItems.filter((i) => typeof i === 'string' || i.items.length === 0 || i.items.some(avail)).length;
  // Nicht aufgeführt, weil sie in dieser Leiste nicht erscheinen können
  const unavailable = [...NAV_TARGETS, ...pageTargets].filter((t) => !avail(t));
  // Punkte unten: Angemeldete haben dazu Verwaltung und Meins, Gäste ohne Anmeldung keins von beiden.
  const mainCount = editingGuest ? shownCount + newOnes.length : (keepDefault ? 5 : shownCount + newOnes.length) + 2;
  const duplicates = duplicateNames(
    Object.fromEntries(Object.entries(labels).filter(([k]) => k !== 'intern' && avail(k) && !cur.hidden.includes(k))),
    keepDefault && !editingGuest ? [labels.intern ?? ''] : menus.map((m) => m.label.trim()),
  );

  const change = (next: NavigationSetting) => {
    (editingGuest ? setGuestDraft : setDraft)(next);
    setSaveError(null);
  };
  const rename = (key: string, patch: { label?: string; icon?: string }) => {
    setNames((n) => ({ ...n, [key]: { ...n[key], ...patch } }));
    setSaveError(null);
  };

  async function save(reset: boolean) {
    setBusy(true);
    setSaveError(null);
    const clean: NavNames = Object.fromEntries(
      Object.entries(names).flatMap(([k, v]) => {
        const one = { ...(v?.label?.trim() ? { label: v.label.trim() } : {}), ...(v?.icon ? { icon: v.icon } : {}) };
        return Object.keys(one).length > 0 ? [[k, one]] : [];
      }),
    );
    // Reihenfolge in „Intern“: nur speichern, wenn sie von der Standardreihenfolge abweicht.
    const std = draftOf(null, isOn, pageTargets, internLabel).items.at(-1);
    const standardIntern = typeof std === 'object' ? std.items : [];
    const ownIntern = keepDefault && internOrder.join() !== standardIntern.join() ? internOrder : null;
    const body = reset
      ? { navigation: null, guest_navigation: null, names: {}, start: {}, show_labels: true, intern_order: null }
      : {
          navigation: keepDefault ? null : draft,
          guest_navigation: guestOwn ? guestDraft : null,
          names: clean,
          start,
          show_labels: showLabels,
          ...(keepDefault ? { intern_order: ownIntern } : {}),
          admin_order: orderToSave(adminStart, adminSetup),
        };
    // Namen der weiteren Sprache, die sich geändert haben (beim Zurücksetzen alle löschen)
    const changedForeign = Object.keys(
      reset ? Object.fromEntries([...NAV_TARGETS, ...pageTargets, 'intern'].map((k) => [k, ''])) : foreignNames,
    )
      .map((ref) => ({ kind: 'nav' as const, ref, text: reset ? '' : (foreignNames[ref] ?? '').trim() }))
      .filter((i) => i.text !== savedForeign(i.ref));
    // Neue Namen stehen überall in der App (auch in Überschriften): dann einmal neu laden.
    const reload =
      JSON.stringify(body.names) !== JSON.stringify(savedNames) || body.show_labels !== data.show_labels || changedForeign.length > 0;
    try {
      const next = await api.saveNavigation(body);
      if (changedForeign.length > 0 && foreign) setNameTranslations((await api.saveTranslations(editLang, changedForeign)).translations);
      applyNavigation(next);
      onSaved(next);
      onToast(reset ? t('Standardnavigation wiederhergestellt') : t('Navigation gespeichert'));
      if (reload) setTimeout(() => window.location.reload(), 800);
    } catch (err) {
      setSaveError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  /** Startseite: Auswahl aus dem, was in der Leiste steht (für Angemeldete auch „Meins“). */
  const startLabel = (target: string): string => {
    if (target === 'meins') return t('Meins');
    if (target === 'intern') return labels.intern ?? defaultLabel('intern', pages);
    return labels[target] ?? target;
  };
  const startSelect = (who: 'guest' | 'member', title: string) => {
    // Gäste: ihre eigene Leiste, sonst die der Angemeldeten
    const own = who === 'guest' && guestOwn;
    const nav = own ? guestDraft : draft;
    const navMenus = nav.items.filter((i): i is NavMenuSetting => typeof i !== 'string');
    // Nur, was diese Personen sehen können: Gäste mit den Rechten der Rolle Gast, Angemeldete alles, was an ist.
    const ok = (t: string): boolean => known(t) && (who === 'guest' ? forGuests(t) : isOn(t));
    const standard = !own && keepDefault;
    const choices = startChoices(own ? guestDraft : keepDefault ? null : draft, who === 'member', pageTargets).filter((t) => {
      if (known(t)) return ok(t) && !nav.hidden.includes(t);
      const menu = /^menue\/(\d+)$/.exec(t);
      if (menu) return navMenus[Number(menu[1]) - 1]?.items.some(ok) ?? false;
      // „Intern“ der Standardnavigation: nur mit dem Recht „Intern“ (Gäste: das der Rolle Gast) und etwas darin
      if (t === 'intern') return standard && (who === 'member' || guestCan('view_intern')) && INTERN_TARGETS.some(ok);
      return true;
    });
    const menuLabel = (n: number): string => {
      const m = navMenus[n - 1];
      return t('Untermenü „{name}“', { name: m?.label.trim() || t('Menü {n}', { n }) });
    };
    return (
      <label className="field">
        <span>{title}</span>
        <select
          value={start[who] ?? ''}
          disabled={busy}
          onChange={(e) => {
            setStart({ ...start, [who]: e.target.value || undefined });
            setSaveError(null);
          }}
        >
          <option value="">{t('Erster Punkt der Leiste')}</option>
          {choices.map((t) => (
            <option key={t} value={t}>
              {/^menue\/\d+$/.test(t) ? menuLabel(Number(t.slice(6))) : startLabel(t)}
            </option>
          ))}
        </select>
      </label>
    );
  };

  function updateMenu(index: number, patch: Partial<NavMenuSetting>) {
    let m = -1;
    change({
      ...cur,
      items: cur.items.map((i) => {
        if (typeof i === 'string') return i;
        m++;
        return m === index ? { ...i, ...patch } : i;
      }),
    });
  }

  function addMenu() {
    change({ ...cur, items: [...cur.items, { label: `Menü ${menus.length + 1}`, icon: 'ordner', items: [] }] });
  }

  /** Auswahl „Platz“ eines Bereichs: unten, in einem Untermenü oder nicht in der Navigation. */
  const placeSelect = (target: NavTarget) => (
    <select
      aria-label={t('Platz von {name}', { name: labels[target] ?? target })}
      value={String(placeOf(cur, target))}
      disabled={busy}
      onChange={(e) => {
        const v = e.target.value;
        change(moveTo(cur, target, (v === 'main' || v === 'hidden' ? v : Number(v)) as NavPlace));
      }}
    >
      <option value="main">{t('Unten in der Leiste')}</option>
      {menus.map((m, i) => (
        <option key={i} value={i}>
          {t('Im Untermenü „{name}“', { name: m.label.trim() || t('Menü {n}', { n: i + 1 }) })}
        </option>
      ))}
      <option value="hidden">{t('Nicht in der Navigation')}</option>
    </select>
  );

  /** Eigener Name und eigenes Symbol (Issue #362); Platzhalter ist der Standardname bzw. der Titel der Seite. */
  const renameFields = (key: string, fallbackIcon: string) => {
    const standard = defaultLabel(key, pages);
    return (
      <details className="nav-rename">
        <summary>{names[key]?.label?.trim() ? t('Umbenennen (Standard: {name})', { name: standard }) : t('Umbenennen')}</summary>
        <label className="field">
          <span>{isPageTarget(key) ? t('Name im Tab (leer = Titel der Seite)') : t('Eigener Name (leer = Standard)')}</span>
          {foreign ? (
            <input
              type="text"
              lang={editLang}
              value={foreignValue(key)}
              placeholder={names[key]?.label?.trim() || standard}
              maxLength={data.label_max}
              disabled={busy}
              onChange={(e) => {
                setForeignNames((n) => ({ ...n, [key]: e.target.value }));
                setSaveError(null);
              }}
            />
          ) : (
            <input
              type="text"
              value={names[key]?.label ?? ''}
              placeholder={standard}
              maxLength={data.label_max}
              disabled={busy}
              onChange={(e) => rename(key, { label: e.target.value })}
            />
          )}
        </label>
        <label className="field">
          <span>{t('Symbol')}</span>
          <select value={names[key]?.icon ?? ''} disabled={busy} onChange={(e) => rename(key, { icon: e.target.value || undefined })}>
            <option value="">{t('Standard ({icon})', { icon: ICON_LABELS[fallbackIcon] ?? fallbackIcon })}</option>
            {NAV_ICONS.map((i) => (
              <option key={i} value={i}>
                {ICON_LABELS[i] ?? i}
              </option>
            ))}
          </select>
        </label>
      </details>
    );
  };

  const targetRow = (target: NavTarget) => (
    <div className="row-main">
      <span className="row-title">{labels[target]}</span>
      {isPageTarget(target) && <span className="row-sub row-kind">{t('Eigene Seite')}</span>}
      {placeSelect(target)}
      {renameFields(target, targetIcon(target))}
    </div>
  );

  return (
    <section className="card settings-section" id="einstellungen-navigation">
      <h3>{t('Navigation')}</h3>
      <div className="segmented" role="group" aria-label={t('Leiste für')}>
        {(
          [
            ['member', t('Für Angemeldete')],
            ['guest', t('Für Gäste')],
          ] as const
        ).map(([k, text]) => (
          <button
            key={k}
            type="button"
            className={who === k ? 'active' : ''}
            aria-pressed={who === k}
            disabled={busy}
            onClick={() => setWho(k)}
          >
            {text}
          </button>
        ))}
      </div>
      {editingGuest ? (
        <>
          <p className="small">
            {t('Gäste sind alle ohne Anmeldung und Angemeldete mit der Rolle {guest} (z. B. ehemalige Mitglieder).', {
              guest: GUEST_LABEL(),
            })}
          </p>
          <label className="toggle">
            <input
              type="checkbox"
              checked={guestOwn}
              disabled={busy}
              onChange={(e) => {
                setGuestOwn(e.target.checked);
                // Neu eingeschaltet: Kopie der Leiste der Angemeldeten, Mitglieder und Dokumente unten
                if (e.target.checked && savedGuest === null) setGuestDraft(guestDraftOf(draft));
                setSaveError(null);
              }}
            />
            <span>{t('Eigene Leiste für Gäste')}</span>
          </label>
          {!guestOwn && (
            <p className="small muted">{t('Gäste sehen dieselbe Leiste wie Angemeldete, jeder nur die Bereiche, die er sehen darf.')}</p>
          )}
        </>
      ) : (
        <p className="small">
          {custom
            ? t('Zurzeit gilt eure eigene Navigation.')
            : t(
                'Zurzeit gilt die Standardnavigation: Wer das Recht „Intern“ hat, findet die Bereiche unter „{intern}“ gesammelt, alle anderen sehen {members} und {documents} als eigene Punkte.',
                { intern: labels.intern ?? '', members: labels.mitglieder ?? '', documents: labels.dokumente ?? '' },
              )}
        </p>
      )}

      {(!editingGuest || guestOwn) && (
        <>
          <h4>{t('Unten in der Leiste')}</h4>
          <p className="small muted">
            {t('Zum Ändern der Reihenfolge am Griff ziehen. Je mehr Punkte unten stehen, desto kleiner werden Schrift und Symbole.')}
          </p>
          {mainCount >= CROWDED && (
            <p className="small muted" role="note">
              {editingGuest
                ? t('Mit {count} Punkten wird es auf schmalen Handys eng.', { count: mainCount })
                : t('Mit {count} Punkten (mit Verwaltung und Meins) wird es auf schmalen Handys eng.', { count: mainCount })}
            </p>
          )}
          <MainList
            items={shownItems}
            onMove={(items) => change({ ...cur, items: mergeOrder(cur.items, items) })}
            disabled={busy}
            labels={labels}
            render={(item, index) => {
              if (typeof item === 'string') return known(item) ? targetRow(item) : null;
              return (
                <div className="row-main nav-menu">
                  <label className="field">
                    <span>{t('Name des Untermenüs')}</span>
                    <input
                      type="text"
                      value={item.label}
                      maxLength={data.label_max}
                      disabled={busy}
                      onChange={(e) => updateMenu(index, { label: e.target.value })}
                    />
                  </label>
                  <label className="field">
                    <span>{t('Symbol')}</span>
                    <select value={item.icon} disabled={busy} onChange={(e) => updateMenu(index, { icon: e.target.value })}>
                      {NAV_ICONS.map((i) => (
                        <option key={i} value={i}>
                          {ICON_LABELS[i] ?? i}
                        </option>
                      ))}
                    </select>
                  </label>
                  <SubList
                    items={item.items.filter(avail)}
                    disabled={busy}
                    onMove={(next) => updateMenu(index, { items: mergeOrder(item.items, next) })}
                    render={targetRow}
                    labels={labels}
                  />
                  {item.items.length === 0 ? (
                    <p className="small muted">{t('Noch leer: Wähle bei einem Bereich „Im Untermenü …“.')}</p>
                  ) : (
                    !item.items.some(avail) && (
                      <p className="small muted">
                        {editingGuest
                          ? t('Darin ist zurzeit nichts, was Gäste sehen dürfen: Das Untermenü erscheint nicht.')
                          : t('Die Bereiche darin sind ausgeschaltet: Das Untermenü erscheint nicht.')}
                      </p>
                    )
                  )}
                  <button
                    type="button"
                    className="btn btn-small btn-secondary"
                    disabled={busy}
                    onClick={() => change(removeMenu(cur, index))}
                  >
                    {t('Untermenü entfernen')}
                  </button>
                </div>
              );
            }}
          />
          <button type="button" className="btn btn-secondary btn-block" disabled={busy} onClick={addMenu}>
            {t('Untermenü hinzufügen')}
          </button>

          {(cur.hidden.some(avail) || newOnes.length > 0) && (
            <>
              <h4>{t('Nicht in der Navigation')}</h4>
              <ul className="card-list">
                {cur.hidden.filter(avail).map((t) => (
                  <li key={t} className="card row">
                    {targetRow(t)}
                  </li>
                ))}
                {newOnes.map((target) => (
                  <li key={target} className="card row">
                    <div className="row-main">
                      <span className="row-title">{labels[target]}</span>
                      <div className="row-sub">{t('Neu: erscheint hinten in der Leiste, bis du ihm einen Platz gibst.')}</div>
                      {placeSelect(target)}
                    </div>
                  </li>
                ))}
              </ul>
            </>
          )}
          {unavailable.length > 0 && (
            <p className="small muted" role="note">
              {editingGuest
                ? t(
                    'Nicht zur Auswahl, weil Gäste sie nicht sehen dürfen oder der Bereich aus ist: {list}. Was Gäste sehen, stellst du bei der Rolle {guest} unter Einstellungen → Rollen ein.',
                    { list: unavailable.map((x) => labels[x]).join(', '), guest: GUEST_LABEL() },
                  )
                : t('Nicht zur Auswahl, weil der Bereich aus ist: {list}. Schaltest du einen ein, steht er wieder hier.', {
                    list: unavailable.map((x) => labels[x]).join(', '),
                  })}
            </p>
          )}
        </>
      )}

      {!custom && !editingGuest && (
        <>
          <h4>{t('Untermenü der Standardnavigation')}</h4>
          <ul className="card-list">
            <li className="card row">
              <div className="row-main">
                <span className="row-title">{labels.intern ?? defaultLabel('intern', pages)}</span>
                <div className="row-sub">
                  {t(
                    'Sammelt in der Standardnavigation die internen Bereiche. Ihre Reihenfolge änderst du oben im Untermenü am Griff; die Standardnavigation bleibt dabei erhalten.',
                  )}
                </div>
                {renameFields('intern', 'intern')}
              </div>
            </li>
          </ul>
        </>
      )}

      <h4>{t('Startseite')}</h4>
      <p className="small muted">
        {t(
          'Was beim Öffnen der App erscheint. Wer die Startseite nicht sehen darf, landet auf dem ersten Punkt, den er sehen darf. Geteilte Links und Benachrichtigungen öffnen weiter ihre eigene Seite.',
        )}
      </p>
      {startSelect('guest', t('Für Gäste'))}
      {startSelect('member', t('Angemeldet'))}

      <h4>{t('Darstellung')}</h4>
      <label className="toggle">
        <input
          type="checkbox"
          checked={showLabels}
          disabled={busy}
          onChange={(e) => {
            setShowLabels(e.target.checked);
            setSaveError(null);
          }}
        />
        <span>{t('Namen unter den Symbolen zeigen')}</span>
      </label>
      {!showLabels && <p className="small muted">{t('Unten stehen nur die Symbole; die Namen stehen weiter oben auf jeder Seite.')}</p>}

      <h4>{t('Verwaltung')}</h4>
      <p className="small muted">
        {t(
          'Reihenfolge der Knöpfe in der Verwaltung, für alle gleich. Zum Ändern am Griff ziehen. Jede Person sieht weiter nur die Knöpfe, die sie nutzen darf.',
        )}
      </p>
      <AdminOrderList
        title={t('Startseite der Verwaltung')}
        ids={adminStart}
        disabled={busy}
        onChange={(next) => {
          setAdminStart(next);
          setSaveError(null);
        }}
      />
      <AdminOrderList
        title={t('Einrichtungen')}
        ids={adminSetup}
        disabled={busy}
        onChange={(next) => {
          setAdminSetup(next);
          setSaveError(null);
        }}
      />
      {Object.keys(orderToSave(adminStart, adminSetup)).length > 0 && (
        <button
          type="button"
          className="btn btn-small btn-secondary"
          disabled={busy}
          onClick={() => {
            setAdminStart(startOrder(null));
            setAdminSetup(setupOrder(null));
            setSaveError(null);
          }}
        >
          {t('Standardreihenfolge der Verwaltung')}
        </button>
      )}

      {duplicates.length > 0 && (
        <p className="small muted" role="note">
          {t('Mehrere Punkte heißen gleich: {list}.', { list: duplicates.map((d) => t('„{name}“', { name: d })).join(', ') })}
        </p>
      )}
      {(problem || saveError) && (
        <p className="form-error" role="alert">
          {problem ?? saveError}
        </p>
      )}
      <button type="button" className="btn btn-block" disabled={busy || !!problem} onClick={() => void save(false)}>
        {busy ? t('Speichert …') : t('Navigation speichern')}
      </button>
      {(custom ||
        savedGuest !== null ||
        Object.keys(savedNames).length > 0 ||
        Object.keys(savedStart).length > 0 ||
        !data.show_labels ||
        savedInternOrder.length > 0) && (
        <button
          type="button"
          className="btn btn-block btn-secondary"
          disabled={busy}
          onClick={async () => {
            if (
              await confirmAsk(
                t(
                  'Alles in der Navigation wird zurückgesetzt: Reihenfolge und Untermenüs (auch die Leiste für Gäste), eigene Namen und Symbole, Startseiten und die Namen unter den Symbolen. Danach gilt wieder die Standardnavigation.',
                ),
              )
            )
              void save(true);
          }}
        >
          {t('Standardnavigation wiederherstellen')}
        </button>
      )}
    </section>
  );
}

type Item = string | NavMenuSetting;

/** Prüfung der eigenen Namen vor dem Speichern (wie der Server); null = in Ordnung. */
function namesError(names: NavNames, labelMax: number): string | null {
  for (const v of Object.values(names)) {
    const label = v?.label?.trim() ?? '';
    if (label.length > labelMax) return t('Ein Name darf höchstens {max} Zeichen lang sein („{name}“).', { max: labelMax, name: label });
  }
  return null;
}

/** Namen, die mehr als ein Punkt trägt (nur ein Hinweis, Speichern geht trotzdem). */
function duplicateNames(labels: Labels, menuLabels: string[]): string[] {
  const seen = new Map<string, number>();
  for (const l of [...Object.values(labels), ...menuLabels]) {
    const k = (l ?? '').trim();
    if (k) seen.set(k, (seen.get(k) ?? 0) + 1);
  }
  return [...seen].filter(([, n]) => n > 1).map(([k]) => k);
}

/** Hauptpunkte: per Ziehen am Griff ordnen. */
function MainList({
  items,
  onMove,
  disabled,
  render,
  labels,
}: {
  /** Die gezeigten Hauptpunkte (ohne die, die in dieser Leiste nicht erscheinen können) */
  items: Item[];
  labels: Labels;
  onMove: (items: Item[]) => void;
  disabled: boolean;
  /** index: Nummer des Untermenüs (ab 0), bei Bereichen -1 */
  render: (item: Item, index: number) => ReactNode;
}) {
  const sort = useDragSort(items, onMove, disabled);
  let menu = -1;
  return (
    <ul className="card-list">
      {items.map((item, i) => {
        if (typeof item !== 'string') menu++;
        return (
          <li key={typeof item === 'string' ? item : `menu-${i}`} {...sort.itemProps(i, 'card row')}>
            {items.length > 1 && sort.handle(i, typeof item === 'string' ? (labels[item] ?? item) : item.label)}
            {render(item, typeof item === 'string' ? -1 : menu)}
          </li>
        );
      })}
    </ul>
  );
}

/** Bereiche eines Untermenüs: per Ziehen am Griff ordnen. */
function SubList({
  items,
  onMove,
  disabled,
  render,
  labels,
}: {
  items: NavTarget[];
  onMove: (next: NavTarget[]) => void;
  disabled: boolean;
  render: (t: NavTarget) => ReactNode;
  labels: Labels;
}) {
  const sort = useDragSort(items, onMove, disabled);
  if (items.length === 0) return null;
  return (
    <ul className="card-list nav-submenu">
      {items.map((t, i) => (
        <li key={t} {...sort.itemProps(i, 'row')}>
          {items.length > 1 && sort.handle(i, labels[t] ?? t)}
          {render(t)}
        </li>
      ))}
    </ul>
  );
}

/**
 * Kacheln einer Gruppe der Verwaltung per Ziehen ordnen. Gezeigt werden die, deren Bereich an ist; die übrigen behalten
 * ihren Platz in der gespeicherten Folge (mergeOrder).
 */
function AdminOrderList({
  title,
  ids,
  disabled,
  onChange,
}: {
  title: string;
  ids: string[];
  disabled: boolean;
  onChange: (next: string[]) => void;
}) {
  const catalog = adminTileCatalog();
  const shown = ids.filter((id) => catalog[id]?.on);
  const sort = useDragSort(shown, (next) => onChange(mergeOrder(ids, next)), disabled);
  const own = new Set(adminTabs().map((tab) => tab.id));
  if (shown.length === 0) return null;
  return (
    <>
      <p className="small nav-order-title">
        <strong>{title}</strong>
      </p>
      <ul className="card-list">
        {shown.map((id, i) => (
          <li key={id} {...sort.itemProps(i, 'card row')}>
            {shown.length > 1 && sort.handle(i, catalog[id]?.label ?? id)}
            <div className="row-main">
              <span className="row-title">{catalog[id]?.label ?? id}</span>
              {id !== SETUP && !own.has(id) && <div className="row-sub">{t('Siehst du selbst nicht (fehlendes Recht).')}</div>}
            </div>
          </li>
        ))}
      </ul>
    </>
  );
}
