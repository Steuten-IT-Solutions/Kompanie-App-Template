// „Zurück“ schließt zuerst offene Fenster (src/backClose.ts): Einträge im Verlauf je offener Ebene, mit einem Verlauf
// zum Nachspielen statt des Browsers.
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

const { afterBackSync, initBackClose, openBackLayer, resetBackCloseForTests } = await import('../src/backClose.ts');

const settle = () => new Promise((r) => setTimeout(r, 20));

beforeEach(() => {
  resetBackCloseForTests();
  history = new FakeHistory();
  g.window = {
    history,
    addEventListener: (t: string, l: () => void) => history.addEventListener(t, l),
    removeEventListener: (t: string, l: () => void) => history.removeEventListener(t, l),
  };
});

test('Fenster öffnen legt einen Eintrag mit derselben Adresse an, „Zurück“ schließt es', async () => {
  let closed = 0;
  openBackLayer(() => closed++);
  await settle();
  assert.equal(history.entries.length, 2);
  assert.equal(history.url, '#/start');
  history.back();
  await settle();
  assert.equal(closed, 1);
  assert.equal(history.index, 0);
  assert.equal(history.entries.length, 2, 'kein weiterer Schritt zurück');
});

test('Schließen mit ✕ nimmt den Eintrag wieder weg: das nächste „Zurück“ verlässt die Seite', async () => {
  let closed = 0;
  history.navigate('#/galerie/3');
  const leave = openBackLayer(() => closed++);
  await settle();
  assert.equal(history.index, 2);
  leave();
  await settle();
  assert.equal(history.index, 1);
  assert.equal(history.url, '#/galerie/3');
  assert.equal(closed, 0, 'close nur bei „Zurück“');
});

test('Rückfrage über einem Fenster über dem Auswählen: „Zurück“ schließt immer nur das oberste', async () => {
  const closed: string[] = [];
  const layers: Record<string, () => void> = {};
  for (const name of ['auswählen', 'fenster', 'rückfrage']) {
    layers[name] = openBackLayer(() => closed.push(name));
    await settle();
  }
  assert.equal(history.index, 3);
  history.back();
  await settle();
  assert.deepEqual(closed, ['rückfrage']);
  history.back();
  await settle();
  assert.deepEqual(closed, ['rückfrage', 'fenster']);
  assert.equal(history.index, 1);
});

test('Mehrere Ebenen schließen zugleich (nach dem Verschieben): ein Schritt zurück an den Anfang', async () => {
  const leaveSelect = openBackLayer(() => {});
  const leaveSheet = openBackLayer(() => {});
  const leaveBox = openBackLayer(() => {});
  await settle();
  assert.equal(history.index, 3);
  leaveSheet();
  leaveBox();
  leaveSelect();
  await settle();
  assert.equal(history.index, 0);
  assert.equal(history.entries.length, 4);
});

test('Neues Fenster, während der Eintrag des alten noch weggenommen wird, bleibt offen', async () => {
  let closed = 0;
  const leave = openBackLayer(() => {});
  await settle();
  leave();
  await new Promise((r) => setTimeout(r, 0)); // go(-1) unterwegs
  openBackLayer(() => closed++);
  await settle();
  assert.equal(closed, 0);
  assert.equal(history.index, 1, 'Eintrag für das neue Fenster');
  history.back();
  await settle();
  assert.equal(closed, 1);
});

test('Weiterleitung nach dem Schließen wartet, bis der Eintrag weg ist (afterBackSync)', async () => {
  history.navigate('#/galerie/3');
  const leave = openBackLayer(() => {});
  await settle();
  leave();
  afterBackSync(() => history.replace('#/galerie'));
  await settle();
  assert.deepEqual(
    history.entries.map((e) => e.url),
    ['#/start', '#/galerie', '#/galerie/3'],
    'das Album ist ersetzt, „Zurück“ führt nicht dorthin',
  );
  assert.equal(history.index, 1);
});

test('Übrig gebliebener Eintrag (Seite gewechselt bei offenem Fenster) wird beim Zurückgehen übersprungen', async () => {
  let closed = 0;
  const leave = openBackLayer(() => closed++);
  await settle();
  history.navigate('#/anders'); // Link im Fenster
  leave();
  await settle();
  assert.equal(history.url, '#/anders');
  history.back();
  await settle();
  assert.equal(closed, 0);
  assert.equal(history.index, 0, 'gleich auf der Seite davor, ohne zweites „Zurück“');
});

test('Nach dem Neuladen auf dem Eintrag eines Fensters: zurück auf den Eintrag ohne Fenster', async () => {
  history.pushState({ backLayers: 1 });
  initBackClose();
  await settle();
  assert.equal(history.index, 0);
});

test('Andere Angaben im Zustand des Eintrags bleiben erhalten', async () => {
  history.entries[0].state = { scroll: 12 };
  openBackLayer(() => {});
  await settle();
  assert.deepEqual(history.state, { scroll: 12, backLayers: 1 });
});
