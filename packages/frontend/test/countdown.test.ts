// Countdown-Block (src/blocks/countdown.ts): Phasen, immer zwei Angaben, Einzahl/Mehrzahl, nächster Zeitpunkt zum
// Neuzeichnen; Blöcke beim Bearbeiten (src/blocks/blockDrafts.ts).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { countdownParts, countdownPhase, nextChange, nextDay, nextTick, remaining, unitLabel, utcOffset } from '../src/blocks/countdown.ts';
import { blocksInput, draftsFrom, hasContent, moveBlock, newBlock } from '../src/blocks/blockDrafts.ts';

const S = 1000;
const M = 60 * S;
const H = 60 * M;
const D = 24 * H;

test('Phasen: vor dem Beginn, läuft bis zum Ende, danach; ohne Beginn wie danach', () => {
  const start = '2027-06-12T12:00:00Z';
  const end = '2027-06-13T00:00:00Z';
  const t = Date.parse(start);
  assert.equal(countdownPhase(t - 1, start, end), 'before');
  assert.equal(countdownPhase(t, start, end), 'running');
  assert.equal(countdownPhase(Date.parse(end) - 1, start, end), 'running');
  assert.equal(countdownPhase(Date.parse(end), start, end), 'after');
  assert.equal(countdownPhase(t, start, null), 'after', 'ohne Ende gleich danach');
  assert.equal(countdownPhase(t, null, null), 'after');
});

test('immer zwei Angaben: Tage und Stunden, Stunden und Minuten, Minuten und Sekunden', () => {
  assert.deepEqual(countdownParts(3 * D + 5 * H + 59 * M), [
    { value: 3, unit: 'day' },
    { value: 5, unit: 'hour' },
  ]);
  assert.deepEqual(countdownParts(D), [
    { value: 1, unit: 'day' },
    { value: 0, unit: 'hour' },
  ]);
  assert.deepEqual(
    countdownParts(D - 1),
    [
      { value: 1, unit: 'day' },
      { value: 0, unit: 'hour' },
    ],
    'angebrochene Sekunde zählt voll: noch genau 1 Tag',
  );
  assert.deepEqual(countdownParts(D - S), [
    { value: 23, unit: 'hour' },
    { value: 59, unit: 'minute' },
  ]);
  assert.deepEqual(countdownParts(H), [
    { value: 1, unit: 'hour' },
    { value: 0, unit: 'minute' },
  ]);
  assert.deepEqual(countdownParts(H - S), [
    { value: 59, unit: 'minute' },
    { value: 59, unit: 'second' },
  ]);
  assert.deepEqual(countdownParts(400), [
    { value: 0, unit: 'minute' },
    { value: 1, unit: 'second' },
  ]);
  assert.deepEqual(countdownParts(-5), [
    { value: 0, unit: 'minute' },
    { value: 0, unit: 'second' },
  ]);
});

test('Einzahl und Mehrzahl', () => {
  assert.equal(unitLabel(1, 'day'), 'Tag');
  assert.equal(unitLabel(0, 'day'), 'Tage');
  assert.equal(unitLabel(2, 'hour'), 'Stunden');
  assert.equal(unitLabel(1, 'minute'), 'Minute');
  assert.equal(unitLabel(1, 'second'), 'Sekunde');
  assert.equal(unitLabel(30, 'second'), 'Sekunden');
});

test('neu zeichnen: je Sekunde unter einer Stunde, sonst je Minute, nie über die Stundengrenze', () => {
  assert.equal(nextTick(10 * M + 1500), 500);
  assert.equal(nextTick(H), 1000);
  assert.equal(nextTick(2 * H + 30 * S), 30 * S);
  assert.equal(nextTick(H + 20 * S), 20 * S, 'bis genau eine Stunde, dann Sekunden');
  const now = Date.parse('2027-06-12T11:00:00Z');
  assert.equal(nextChange(now, '2027-06-12T12:00:00Z', null), 1000);
  assert.equal(nextChange(now, '2027-06-12T10:00:00Z', '2027-06-12T13:00:00Z'), 2 * H, 'läuft: bis zum Ende');
  assert.equal(nextChange(now, '2027-06-12T10:00:00Z', null), null, 'danach: nichts mehr');
});

test('Restzeit nach Kalender in der Zeitzone der Installation: Zeitumstellung zählt nicht', () => {
  const berlin = 'Europe/Berlin';
  assert.equal(utcOffset(Date.parse('2026-11-09T20:47:00Z'), berlin), H, 'Winterzeit');
  assert.equal(utcOffset(Date.parse('2027-06-24T22:00:00Z'), berlin), 2 * H, 'Sommerzeit');
  // 09.11.2026, 21:47 Uhr (Winterzeit) bis 25.06.2027, 0:00 Uhr (Sommerzeit): Wanduhr 227 Tage 2 Std 13 Min
  const now = Date.parse('2026-11-09T20:47:00Z');
  const start = '2027-06-24T22:00:00Z';
  assert.equal(Date.parse(start) - now, 227 * D + H + 13 * M, 'tatsächlich vergehen eine Stunde weniger');
  assert.equal(remaining(now, start, berlin), 227 * D + 2 * H + 13 * M);
  assert.deepEqual(countdownParts(remaining(now, start, berlin)), [
    { value: 227, unit: 'day' },
    { value: 2, unit: 'hour' },
  ]);
  // Ohne Zeitumstellung dazwischen: wie die tatsächliche Restzeit, zum Beginn genau 0
  const summer = Date.parse('2027-06-20T10:00:00Z');
  assert.equal(remaining(summer, start, berlin), Date.parse(start) - summer);
  assert.equal(remaining(Date.parse(start), start, berlin), 0);
  // Umstellung auf Winterzeit (31.10.2027, 3:00 → 2:00 Uhr): bis 4:00 Uhr Winterzeit sind es auf der Wanduhr 2 Stunden
  assert.equal(remaining(Date.parse('2027-10-30T23:59:00Z'), '2027-10-31T03:00:00Z', berlin), 2 * H + M);
  // Beginn in der doppelten Stunde, von der Wanduhr schon überholt: tatsächliche Restzeit statt 0
  assert.equal(remaining(Date.parse('2027-10-31T00:45:00Z'), '2027-10-31T01:15:00Z', berlin), 30 * M);
  // Unbekannte Zeitzone: Zeitzone des Geräts, kein Absturz
  assert.equal(typeof remaining(now, start, 'Nirgendwo/Unbekannt'), 'number');
  assert.equal(nextChange(now, start, null, berlin), M, 'neu zeichnen je Minute');
});

test('Folgetag auch über Monats- und Jahresgrenzen', () => {
  assert.equal(nextDay('2027-03-27'), '2027-03-28');
  assert.equal(nextDay('2027-02-28'), '2027-03-01');
  assert.equal(nextDay('2027-12-31'), '2028-01-01');
});

test('Blöcke bearbeiten: aus älteren Servern, zum Speichern, Verschieben', () => {
  assert.equal(draftsFrom(undefined, '').length, 0);
  const [only] = draftsFrom(undefined, '<p>Alt</p>');
  assert.equal(only.type, 'text');
  assert.equal(only.type === 'text' && only.html, '<p>Alt</p>');

  const c = newBlock('countdown');
  assert.match(c.id, /^[a-z0-9]{1,16}$/);
  const [input] = blocksInput([{ ...c, type: 'countdown', date: '2027-06-12' } as typeof c]);
  assert.deepEqual(
    { time: (input as { time: unknown }).time, end_date: (input as { end_date: unknown }).end_date },
    { time: null, end_date: null },
    'leere Uhrzeit und leeres Enddatum als null',
  );
  const [ev] = blocksInput([{ ...c, source: 'event', event_id: 7, date: '2027-06-12' } as typeof c]);
  assert.equal('date' in ev, false, 'beim Termin kein eigenes Datum');

  assert.deepEqual(moveBlock([1, 2, 3], 0, 1), [2, 1, 3]);
  assert.deepEqual(moveBlock([1, 2, 3], 0, -1), [1, 2, 3]);
  assert.deepEqual(moveBlock([1, 2, 3], 2, -1), [1, 3, 2]);
});

test('Inhalt vorhanden: nicht nur leere Textblöcke (Schwarzes Brett)', () => {
  const text = (html: string) => ({ ...newBlock('text'), html }) as ReturnType<typeof newBlock>;
  assert.equal(hasContent([]), false);
  assert.equal(hasContent([text(''), text('<p></p>'), text('<p>&nbsp; </p>')]), false);
  assert.equal(hasContent([text('<p>Hallo</p>')]), true);
  assert.equal(hasContent([text('<p><img data-image="ab"></p>')]), true);
  assert.equal(hasContent([text(''), newBlock('countdown')]), true);
});

test('Knopf und Nächste Termine: neue Blöcke, ohne die Termine vom Server zum Speichern', () => {
  const button = newBlock('button');
  assert.deepEqual({ ...button, id: '' }, { id: '', type: 'button', audience: 'all', label: '', url: 'https://', style: 'primary' });
  const events = newBlock('events', 'members');
  assert.deepEqual({ ...events, id: '' }, { id: '', type: 'events', audience: 'members', title: '', count: 3 });
  const drafts = draftsFrom([{ id: 'e1', type: 'events', audience: 'all', title: 'Demnächst', count: 2, events: [] }], '');
  assert.deepEqual(drafts, [{ id: 'e1', type: 'events', audience: 'all', title: 'Demnächst', count: 2 }]);
  assert.deepEqual(blocksInput(drafts), drafts);
  assert.equal(hasContent([button]), true);
});

test('Ansprechpartner, Hinweis und Trennlinie: neue Blöcke, ohne die Ämter vom Server zum Speichern', () => {
  assert.deepEqual({ ...newBlock('contacts'), id: '' }, { id: '', type: 'contacts', audience: 'all', title: '', office_ids: [] });
  assert.deepEqual({ ...newBlock('notice'), id: '' }, { id: '', type: 'notice', audience: 'all', title: '', text: '', tone: 'info' });
  assert.deepEqual({ ...newBlock('divider'), id: '' }, { id: '', type: 'divider', audience: 'all', style: 'line' });
  const drafts = draftsFrom([{ id: 'c1', type: 'contacts', audience: 'all', title: '', office_ids: [2, 1], offices: [] }], '');
  assert.deepEqual(blocksInput(drafts), [{ id: 'c1', type: 'contacts', audience: 'all', title: '', office_ids: [2, 1] }]);
});

test('Ablauf und Zahlen: neue Blöcke mit einer leeren Zeile', () => {
  assert.deepEqual(
    { ...newBlock('schedule'), id: '' },
    { id: '', type: 'schedule', audience: 'all', title: '', items: [{ time: '', text: '' }] },
  );
  assert.deepEqual(
    { ...newBlock('facts'), id: '' },
    { id: '', type: 'facts', audience: 'all', title: '', items: [{ value: '', label: '' }] },
  );
});

test('Umfrage und Album: Titel vom Server nur für die Auswahl, nicht zum Speichern', () => {
  assert.deepEqual({ ...newBlock('poll'), id: '' }, { id: '', type: 'poll', audience: 'all', poll_id: 0 });
  assert.deepEqual({ ...newBlock('album'), id: '' }, { id: '', type: 'album', audience: 'all', album_id: 0, count: 6 });
  const drafts = draftsFrom(
    [
      {
        id: 'p1',
        type: 'poll',
        audience: 'members',
        poll_id: 3,
        poll: { id: 3, title: 'Grillfest?', status: 'open', ends_at: null, has_voted: false },
      },
      { id: 'a1', type: 'album', audience: 'all', album_id: 5, count: 4, album: null },
    ],
    '',
  );
  assert.equal((drafts[0] as { poll_title?: string }).poll_title, 'Grillfest?');
  assert.deepEqual(blocksInput(drafts), [
    { id: 'p1', type: 'poll', audience: 'members', poll_id: 3 },
    { id: 'a1', type: 'album', audience: 'all', album_id: 5, count: 4 },
  ]);
});

test('Dokument und Sponsoren: ohne die Angaben vom Server zum Speichern', () => {
  assert.deepEqual({ ...newBlock('document'), id: '' }, { id: '', type: 'document', audience: 'all', document_id: 0 });
  assert.deepEqual({ ...newBlock('sponsors'), id: '' }, { id: '', type: 'sponsors', audience: 'all', title: '' });
  const drafts = draftsFrom(
    [
      {
        id: 'd1',
        type: 'document',
        audience: 'members',
        document_id: 4,
        document: { id: 4, title: 'Satzung', ext: 'pdf', kind: 'pdf', size: 10, date: '2026-10-01', url: '/d/4' },
      },
      { id: 's1', type: 'sponsors', audience: 'all', title: 'Danke', sponsors: [] },
    ],
    '',
  );
  assert.equal((drafts[0] as { document_title?: string }).document_title, 'Satzung');
  assert.deepEqual(blocksInput(drafts), [
    { id: 'd1', type: 'document', audience: 'members', document_id: 4 },
    { id: 's1', type: 'sponsors', audience: 'all', title: 'Danke' },
  ]);
});

test('Karte zur Anfahrt: neuer Block mit leerem Ort, unverändert zum Speichern', () => {
  assert.deepEqual({ ...newBlock('map'), id: '' }, { id: '', type: 'map', audience: 'all', title: '', place: '' });
  const drafts = draftsFrom([{ id: 'm1', type: 'map', audience: 'all', title: 'Anfahrt', place: 'Am Markt 1' }], '');
  assert.deepEqual(blocksInput(drafts), [{ id: 'm1', type: 'map', audience: 'all', title: 'Anfahrt', place: 'Am Markt 1' }]);
});
