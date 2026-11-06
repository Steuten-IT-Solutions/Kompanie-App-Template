// Suche und Sortierung der Liste der Kompanien (src/companySort.ts): wie festgelegt, nach Name, Gründung oder Mitgliedern,
// auf- oder absteigend; Aufgelöste immer unten; die Wahl merkt sich das Gerät.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { companyDirLabel, companyOrderFor, loadCompanyOrder, matchesCompany, saveCompanyOrder, sortCompanies } from '../src/companySort.ts';

const c = (id: number, name: string, founded_year: number | null, member_count?: number, active = true) => ({
  id,
  name,
  short_name: name.slice(0, 2).toUpperCase(),
  active,
  founded_year,
  member_count,
});

// In der Reihenfolge der Verwaltung, eine aufgelöste dazwischen
const list = [
  c(1, 'Zollkompanie', 1950, 12),
  c(2, 'Ältere Kompanie', 1890, 30),
  c(3, 'Aufgelöst', 1800, 2, false),
  c(4, 'Bürger', null, 12),
];
const ids = (l: { id: number }[]) => l.map((x) => x.id);

test('wie festgelegt: Reihenfolge der Verwaltung, umgekehrt rückwärts; Aufgelöste unten', () => {
  assert.deepEqual(ids(sortCompanies(list, { sort: 'order', dir: 'asc' })), [1, 2, 4, 3]);
  assert.deepEqual(ids(sortCompanies(list, { sort: 'order', dir: 'desc' })), [4, 2, 1, 3]);
});

test('nach Name: Umlaute wie Grundbuchstaben', () => {
  assert.deepEqual(ids(sortCompanies(list, { sort: 'name', dir: 'asc' })), [2, 4, 1, 3]);
  assert.deepEqual(ids(sortCompanies(list, { sort: 'name', dir: 'desc' })), [1, 4, 2, 3]);
});

test('nach Gründung: ohne Jahr in beiden Richtungen am Ende', () => {
  assert.deepEqual(ids(sortCompanies(list, { sort: 'founded', dir: 'asc' })), [2, 1, 4, 3]);
  assert.deepEqual(ids(sortCompanies(list, { sort: 'founded', dir: 'desc' })), [1, 2, 4, 3]);
});

test('nach Mitgliedern: bei Gleichstand nach Name', () => {
  assert.deepEqual(ids(sortCompanies(list, { sort: 'members', dir: 'desc' })), [2, 4, 1, 3]);
  assert.deepEqual(ids(sortCompanies(list, { sort: 'members', dir: 'asc' })), [4, 1, 2, 3]);
});

test('Eingabe bleibt unverändert', () => {
  const copy = [...list];
  sortCompanies(list, { sort: 'name', dir: 'asc' });
  assert.deepEqual(list, copy);
});

test('Suche: Name oder Kurzname, ohne Groß-/Kleinschreibung und Akzente', () => {
  assert.equal(matchesCompany({ name: 'Ältere Kompanie', short_name: 'ÄK' }, 'altere'), true);
  assert.equal(matchesCompany({ name: 'Grenadiere', short_name: 'GZL' }, ' gzl '), true);
  assert.equal(matchesCompany({ name: 'Grenadiere', short_name: '' }, 'jäger'), false);
  assert.equal(matchesCompany({ name: 'Grenadiere', short_name: '' }, '  '), true);
});

test('Beschriftung der Richtung', () => {
  assert.equal(companyDirLabel('order', 'asc'), 'Wie festgelegt');
  assert.equal(companyDirLabel('order', 'desc'), 'Umgekehrt');
  assert.equal(companyDirLabel('name', 'desc'), 'Z → A');
  assert.equal(companyDirLabel('founded', 'asc'), 'Älteste zuerst');
  assert.equal(companyDirLabel('members', 'desc'), 'Meiste zuerst');
});

test('Wahl wird gemerkt; Unbekanntes und nicht Angebotenes fällt auf „wie festgelegt“ zurück', () => {
  const store = new Map<string, string>();
  Object.assign(globalThis, {
    localStorage: { getItem: (k: string) => store.get(k) ?? null, setItem: (k: string, v: string) => void store.set(k, v) },
  });
  assert.deepEqual(loadCompanyOrder(), { sort: 'order', dir: 'asc' });
  saveCompanyOrder({ sort: 'members', dir: 'asc' });
  assert.deepEqual(loadCompanyOrder(), { sort: 'members', dir: 'asc' });
  // Ohne Mitglieder (Mitgliederliste nicht sichtbar) wird die Wahl nicht angeboten.
  assert.deepEqual(companyOrderFor(loadCompanyOrder(), ['order', 'name', 'founded']), { sort: 'order', dir: 'asc' });
  store.delete('kompanie_company_sort_dir');
  assert.deepEqual(loadCompanyOrder(), { sort: 'members', dir: 'desc' });
  store.set('kompanie_company_sort', 'quatsch');
  assert.deepEqual(loadCompanyOrder(), { sort: 'order', dir: 'asc' });
});

test('ohne localStorage gilt der Standard', () => {
  const fail = () => {
    throw new Error('gesperrt');
  };
  Object.assign(globalThis, { localStorage: { getItem: fail, setItem: fail } });
  saveCompanyOrder({ sort: 'name', dir: 'desc' });
  assert.deepEqual(loadCompanyOrder(), { sort: 'order', dir: 'asc' });
});
