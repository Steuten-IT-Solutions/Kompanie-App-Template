// Gemeinsame Amtszeiten, die anfangs aufgeklappt sind (src/jointTerms.ts).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { defaultOpenJoint } from '../src/jointTerms.ts';

const term = (id: number, start_year: number, end_year: number | null, current = false) => ({ id, start_year, end_year, current });

test('die laufende ist offen, alle früheren zu', () => {
  assert.deepEqual(defaultOpenJoint([term(3, 2023, null, true), term(2, 2020, 2022), term(1, 2017, 2019)]), [3]);
});

test('läuft keine, ist die neueste offen, unabhängig von der Reihenfolge', () => {
  assert.deepEqual(defaultOpenJoint([term(1, 2017, 2019), term(2, 2020, 2022), term(4, 2014, 2016)]), [2]);
});

test('bei gleichem Beginn die mit dem späteren Ende', () => {
  assert.deepEqual(defaultOpenJoint([term(1, 2020, 2021), term(2, 2020, 2022)]), [2]);
});

test('ohne Amtszeiten ist nichts offen', () => {
  assert.deepEqual(defaultOpenJoint([]), []);
});
