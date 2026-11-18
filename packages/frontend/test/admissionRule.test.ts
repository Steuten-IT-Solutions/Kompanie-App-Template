// Voraussetzung für die Aufnahme als Baum (src/admissionRule.ts): vorlesen, was fehlt, frühere Form, Zählen.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { countNodes, describeRule, fromDraft, legacyOf, missingText, ruleFromRole, type DraftGroup } from '../src/admissionRule.ts';
import type { AdmissionRuleResult } from '../src/api.ts';

const tree: DraftGroup = {
  type: 'group',
  op: 'and',
  items: [
    { type: 'duration', months: '12' },
    {
      type: 'group',
      op: 'or',
      items: [
        { type: 'attendance', kind: 'Versammlung', count: '6' },
        { type: 'attendance', kind: 'Arbeitsdienst', count: '1' },
      ],
    },
  ],
};

test('Voraussetzung in einem Satz, Gruppen in Klammern', () => {
  assert.equal(describeRule(tree), '12 Monate als Anwärter und (6 Teilnahmen an „Versammlung“ oder 1 Teilnahme an „Arbeitsdienst“)');
  assert.equal(describeRule({ type: 'group', op: 'or', items: [] }), '');
  assert.equal(
    describeRule({ type: 'group', op: 'and', items: [{ type: 'group', op: 'or', items: [{ type: 'duration', months: 1 }] }] }),
    '1 Monat als Anwärter',
  );
});

test('zum Speichern: Zahlen als Zahl', () => {
  assert.deepEqual(fromDraft(tree), {
    type: 'group',
    op: 'and',
    items: [
      { type: 'duration', months: 12 },
      {
        type: 'group',
        op: 'or',
        items: [
          { type: 'attendance', kind: 'Versammlung', count: 6 },
          { type: 'attendance', kind: 'Arbeitsdienst', count: 1 },
        ],
      },
    ],
  });
});

test('frühere Form nur, wenn der Baum genau sie ausdrückt', () => {
  assert.equal(legacyOf(tree), null);
  assert.deepEqual(
    legacyOf({
      type: 'group',
      op: 'and',
      items: [
        { type: 'attendance', kind: 'Versammlung', count: '6' },
        { type: 'duration', months: '3' },
      ],
    }),
    { admission_kind: 'Versammlung', admission_count: '6', admission_months: '3' },
  );
  assert.deepEqual(legacyOf({ type: 'group', op: 'or', items: [{ type: 'duration', months: '3' }] }), {
    admission_kind: '',
    admission_count: '0',
    admission_months: '3',
  });
  assert.equal(
    legacyOf({
      type: 'group',
      op: 'or',
      items: [
        { type: 'duration', months: '3' },
        { type: 'duration', months: '4' },
      ],
    }),
    null,
  );
});

test('ältere Server: Baum aus den früheren Feldern', () => {
  assert.deepEqual(ruleFromRole({ admission_kind: 'Versammlung', admission_count: 6, admission_months: 0 }), {
    type: 'group',
    op: 'and',
    items: [{ type: 'attendance', kind: 'Versammlung', count: '6' }],
  });
  assert.deepEqual(ruleFromRole(undefined), { type: 'group', op: 'and', items: [] });
});

test('Bedingungen und Gruppen zählen (ohne die oberste)', () => {
  assert.deepEqual(countNodes(tree), { conditions: 3, groups: 1 });
});

test('was fehlt: ODER mit „oder“, UND mit Punkt', () => {
  const result: AdmissionRuleResult = {
    type: 'group',
    op: 'and',
    status: 'open',
    on: null,
    items: [
      { type: 'duration', months: 12, due: '2027-03-01', status: 'open', on: null },
      {
        type: 'group',
        op: 'or',
        status: 'open',
        on: null,
        items: [
          { type: 'attendance', kind: 'Versammlung', count: 6, attended: 4, missing: 2, status: 'open', on: null },
          { type: 'duration', months: 24, due: null, status: 'unknown', on: null },
        ],
      },
    ],
  };
  assert.equal(missingText(result), 'Mindestdauer ab 01.03.2027 · (noch 2 Teilnahmen an „Versammlung“ oder „Anwärter seit“ fehlt)');
});
