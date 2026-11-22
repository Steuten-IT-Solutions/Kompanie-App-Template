// Schutz vor dem Verlust nicht gespeicherter Eingaben (src/unsaved.ts): Rückfrage vor dem Verlassen, „Zurück“ über einen
// eigenen Eintrag im Verlauf (wie offene Fenster, src/backClose.ts).
import assert from 'node:assert/strict';
import { beforeEach, test } from 'node:test';

/** Verlauf wie im Browser: Einträge mit Zustand und Adresse, go() meldet sich später mit popstate. */
class FakeHistory {
  entries: { state: unknown; url: string }[] = [{ state: null, url: '#/start' }];
  index = 0;
  private listeners: (() => void)[] = [];
  get state(): unknown {
    return this.entries[this.index].state;
  }
  get url(): string {
    return this.entries[this.index].url;
  }
  pushState(state: unknown): void {
    this.entries = this.entries.slice(0, this.index + 1);
    this.entries.push({ state: structuredClone(state), url: this.url });
    this.index++;
  }
  /** Neue Adresse wie location.hash = … (Eintrag ohne Zustand). */
  navigate(url: string): void {
    this.entries = this.entries.slice(0, this.index + 1);
    this.entries.push({ state: null, url });
    this.index++;
  }
  /** Wie location.replace. */
  replace(url: string): void {
    this.entries[this.index] = { state: null, url };
  }
  go(delta: number): void {
    setTimeout(() => {
      this.index = Math.max(0, Math.min(this.entries.length - 1, this.index + delta));
      for (const l of this.listeners) l();
    }, 1);
  }
  back(): void {
    this.go(-1);
  }
  addEventListener(_: string, l: () => void): void {
    this.listeners.push(l);
  }
  removeEventListener(_: string, l: () => void): void {
    this.listeners = this.listeners.filter((x) => x !== l);
  }
}

let history: FakeHistory;
const g = globalThis as unknown as { window: unknown };

const { afterBackSync, openBackLayer, resetBackCloseForTests } = await import('../src/backClose.ts');
const { beforeLeave, hasUnsaved, initUnsaved, markSaved, resetUnsavedForTests, setUnsaved } = await import('../src/unsaved.ts');

const settle = () => new Promise((r) => setTimeout(r, 20));
let asked: string[] = [];
let answer = true;

beforeEach(() => {
  resetBackCloseForTests();
  resetUnsavedForTests();
  history = new FakeHistory();
  g.window = {
    history,
    location: {
      get href() {
        return history.url;
      },
    },
    // Nur popstate spielt der Verlauf nach (beforeunload kommt hier nicht vor).
    addEventListener: (t: string, l: () => void) => t === 'popstate' && history.addEventListener(t, l),
    removeEventListener: (t: string, l: () => void) => history.removeEventListener(t, l),
  };
  asked = [];
  answer = true;
  (globalThis as unknown as { document: unknown }).document = { addEventListener() {}, removeEventListener() {} };
  initUnsaved(
    async (message) => {
      asked.push(message);
      return answer;
    },
    { openBackLayer, afterBackSync },
  );
});

test('ohne nicht gespeicherte Eingaben geht es sofort weiter, ohne Rückfrage', () => {
  let went = 0;
  beforeLeave(() => went++);
  assert.equal(went, 1);
  assert.equal(asked.length, 0);
  assert.equal(history.entries.length, 1, 'kein Eintrag im Verlauf');
});

test('mit nicht gespeicherten Eingaben: Rückfrage, Bleiben verhindert das Verlassen', async () => {
  const t = Symbol('liste');
  setUnsaved(t, true);
  await settle();
  assert.equal(history.entries.length, 2, 'Eintrag des Schutzes');
  answer = false;
  let went = 0;
  beforeLeave(() => went++);
  await settle();
  assert.equal(asked.length, 1);
  assert.equal(went, 0);
  assert.ok(hasUnsaved());
});

test('Verlassen nach der Rückfrage: erst verschwindet der Eintrag des Schutzes, dann die Navigation', async () => {
  setUnsaved(Symbol('liste'), true);
  await settle();
  let indexAtGo = -1;
  beforeLeave(() => (indexAtGo = history.index));
  await settle();
  assert.equal(asked.length, 1);
  assert.equal(indexAtGo, 0, 'Navigation erst nach dem Schritt zurück');
  assert.equal(hasUnsaved(), false, 'Eingaben verworfen');
});

test('nach dem Speichern kein Nachfragen mehr; der Eintrag verschwindet vor der Navigation', async () => {
  const t = Symbol('liste');
  setUnsaved(t, true);
  await settle();
  setUnsaved(t, false);
  markSaved();
  let indexAtGo = -1;
  beforeLeave(() => (indexAtGo = history.index));
  await settle();
  assert.equal(asked.length, 0);
  assert.equal(indexAtGo, 0);
});

test('„Zurück“ mit nicht gespeicherten Eingaben fragt; Bleiben legt den Eintrag wieder an', async () => {
  history.navigate('#/verwaltung/anwesenheit/3');
  setUnsaved(Symbol('liste'), true);
  await settle();
  assert.equal(history.index, 2);
  answer = false;
  history.back();
  await settle();
  assert.equal(asked.length, 1);
  assert.equal(history.url, '#/verwaltung/anwesenheit/3', 'Seite bleibt');
  assert.equal(history.index, 2, 'Schutz wieder da');
});

test('„Zurück“ und Verlassen führt auf die Seite davor', async () => {
  history.navigate('#/verwaltung/anwesenheit/3');
  setUnsaved(Symbol('liste'), true);
  await settle();
  history.back();
  await settle();
  await settle();
  assert.equal(asked.length, 1);
  assert.equal(history.url, '#/start');
});

test('„Zurück“, nachdem gespeichert wurde: ohne Rückfrage einen Schritt weiter, auf die Seite davor', async () => {
  history.navigate('#/verwaltung/anwesenheit/3');
  const t = Symbol('liste');
  setUnsaved(t, true);
  await settle();
  setUnsaved(t, false);
  history.back();
  await settle();
  await settle();
  assert.equal(asked.length, 0);
  assert.equal(history.url, '#/start');
});

test('Navigation an der App vorbei (neue Adresse) führt nicht zusätzlich zurück', async () => {
  history.navigate('#/verwaltung/aemter/3');
  const t = Symbol('liste');
  setUnsaved(t, true);
  await settle();
  setUnsaved(t, false);
  history.navigate('#/mitglieder/aemter');
  for (const l of (history as unknown as { listeners: (() => void)[] }).listeners) l();
  await settle();
  await settle();
  assert.equal(asked.length, 0);
  assert.equal(history.url, '#/mitglieder/aemter');
});
