// Texte des Änderungsprotokolls (src/changeText.ts): Titel, Felder, CSV.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { csvRows, entryTitle, fieldText, groupEntries, groupTitle } from '../src/changeText.ts';
import type { ChangeEntry } from '../src/api.ts';

const cap = (k: string) => ({ view_personal: 'Persönliches sehen', manage_points: 'Punkte vergeben' })[k] ?? k;

test('Titel mit Art, Bezeichnung und Aktion; Einstellungen ohne Anführungszeichen', () => {
  assert.equal(entryTitle({ type: 'Mitglied', label: 'Max Muster', action: 'update' }), 'Mitglied „Max Muster“ geändert');
  assert.equal(entryTitle({ type: 'Termin', label: 'Schützenfest', action: 'delete' }), 'Termin „Schützenfest“ gelöscht');
  assert.equal(entryTitle({ type: 'Einstellungen', label: 'E-Mail', action: 'update' }), 'E-Mail geändert');
  assert.equal(
    entryTitle({ type: 'Einstellungen', label: 'Update auf Version 1.2.0 eingespielt', action: 'event' }),
    'Update auf Version 1.2.0 eingespielt',
  );
});

test('Felder: alt → neu, nur neu, nur alt, geheim, ausgeblendet, entfernt', () => {
  assert.equal(fieldText({ field: 'Ort', old: 'Halle', new: 'Festplatz' }, cap), 'Halle → Festplatz');
  assert.equal(fieldText({ field: 'Ort', old: null, new: 'Festplatz' }, cap), 'leer → Festplatz');
  assert.equal(fieldText({ field: 'Ort', new: 'Festplatz' }, cap), 'Festplatz');
  assert.equal(fieldText({ field: 'Ort', old: 'Halle' }, cap), 'Halle');
  assert.equal(fieldText({ field: 'Passwort', secret: true }, cap), 'geändert');
  assert.equal(fieldText({ field: 'Handynummer', hidden: true, personal: true }, cap), 'geändert (Werte nur mit „Persönliches sehen“)');
  assert.equal(fieldText({ field: 'E-Mail', personal: true }, cap), 'geändert (Werte entfernt)');
  assert.equal(
    fieldText({ field: 'Rechte zusätzlich', old: null, new: 'view_personal, manage_points' }, cap),
    'leer → Persönliches sehen, Punkte vergeben',
  );
});

test('CSV: eine Zeile je Feld, geheime und ausgeblendete Werte nie', () => {
  const e: ChangeEntry = {
    id: 1,
    at: 0,
    actor_id: 1,
    actor_name: 'Admin',
    area: 'app',
    area_label: 'Verwaltung',
    type: 'Mitglied',
    item: '2',
    label: 'Max',
    action: 'update',
    subject_user_id: 2,
    batch: '',
    changes: [
      { field: 'Rolle', old: 'Mitglied', new: 'Kassierer' },
      { field: 'Handynummer', hidden: true, personal: true },
      { field: 'Passwort', secret: true },
    ],
  };
  const rows = csvRows([e, { ...e, id: 2, action: 'delete', changes: [] }], () => 'Zeit', cap);
  assert.deepEqual(rows[0].slice(5), ['geändert', 'Rolle', 'Mitglied', 'Kassierer']);
  assert.deepEqual(rows[1].slice(6), ['Handynummer', '', 'geändert']);
  assert.deepEqual(rows[2].slice(6), ['Passwort', '', 'geändert']);
  assert.deepEqual(rows[3].slice(5), ['gelöscht', '', '', '']);
});

test('Einträge einer Aktion als eine Gruppe, mit Titel', () => {
  const e = (id: number, batch: string, type = 'Punkte', action: ChangeEntry['action'] = 'create') => ({
    id,
    batch,
    type,
    label: `#${id}`,
    action,
  });
  const groups = groupEntries([e(1, 'a'), e(2, 'a'), e(3, ''), e(4, ''), e(5, 'b'), e(6, 'b', 'Mitglied', 'update')]);
  assert.deepEqual(
    groups.map((g) => g.map((x) => x.id)),
    [[1, 2], [3], [4], [5, 6]],
  );
  assert.equal(groupTitle(groups[0]), '2 × Punkte angelegt');
  assert.equal(groupTitle(groups[1]), 'Punkte „#3“ angelegt');
  assert.equal(groupTitle(groups[3]), '2 Änderungen auf einmal');
});
