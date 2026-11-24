/**
 * CSRF-Token der App (unter WordPress der Nonce „wp_rest“) und wann es ein neues braucht.
 *
 * Der Service Worker zeigt beim Start die gespeicherte App-Seite (public/sw.js, appPage). Ihr Token kann abgelaufen
 * sein, wenn die App länger nicht offen war (WordPress: nach 12 bis 24 Stunden). Früher schickte die App die ersten
 * Anfragen trotzdem damit los; der Server lehnte jede ab (403), jede holte sich ein eigenes neues Token und fragte noch
 * einmal: aus drei Aufrufen von PHP wurden neun. Jetzt:
 * - Steht in der Konfiguration, bis wann das Token gilt (csrf.expiresAt), und ist es so gut wie abgelaufen, warten die
 *   Anfragen kurz auf ein neues ({@link CsrfToken.ready}). Zuerst auf das aus der aktuellen App-Seite: Die holt der
 *   Service Worker ohnehin gerade im Hintergrund und meldet ihre Tokens (pageUpdate.ts → take). Kommt sie nicht
 *   binnen pageWaitMs, holt die App selbst eines. Insgesamt höchstens maxWaitMs, ohne Netz gar nicht: Dann geht es mit
 *   dem alten Token weiter wie bisher (gespeicherte Daten, bei Ablehnung ein neues Token und noch einmal).
 * - Lehnt der Server trotzdem ab, holen alle gleichzeitig abgelehnten Anfragen gemeinsam ein Token ({@link
 *   CsrfToken.refresh}); wer sein Token schon ersetzt sieht, fragt gleich noch einmal ({@link CsrfToken.token}).
 * Geht die Uhr des Geräts falsch, kostet das höchstens eine zusätzliche Anfrage je renewAgainMs.
 */

export interface TokenState {
  token: string;
  /** Unix-Zeit in Sekunden, bis zu der das Token sicher gilt; null: gilt mit der Sitzung (eigenständige Version) */
  expiresAt: number | null;
}

export type FetchResult = { ok: true; state: TokenState } | { ok: false; offline: boolean };

export interface CsrfOptions {
  initial: TokenState;
  /** Holt ein neues Token beim Server (csrf.refreshUrl). */
  fetchToken: () => Promise<FetchResult>;
  /** Kommt gleich eine aktuelle App-Seite vom Service Worker (Seite wird von ihm gesteuert)? */
  pageExpected?: () => boolean;
  online?: () => boolean;
  now?: () => number;
  /** So viel vor dem Ablauf schon erneuern. */
  marginMs?: number;
  pageWaitMs?: number;
  maxWaitMs?: number;
  renewAgainMs?: number;
}

export const MARGIN_MS = 5 * 60_000;
export const PAGE_WAIT_MS = 2500;
export const MAX_WAIT_MS = 5000;
export const RENEW_AGAIN_MS = 10 * 60_000;

const sleep = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms));

export class CsrfToken {
  private state: TokenState;
  private fetching: Promise<FetchResult> | null = null;
  private renewing: Promise<void> | null = null;
  private lastRenewal = -Infinity;
  private pageWaiters: (() => void)[] = [];
  private readonly o: CsrfOptions;

  constructor(options: CsrfOptions) {
    this.o = options;
    this.state = { ...options.initial };
  }

  token(): string {
    return this.state.token;
  }

  private now(): number {
    return (this.o.now ?? Date.now)();
  }

  /** Neue Tokens übernehmen (aus der aktuellen App-Seite oder nach dem Holen). */
  take(state: TokenState): void {
    if (!state.token) return;
    this.state = { token: state.token, expiresAt: state.expiresAt ?? null };
    const waiters = this.pageWaiters;
    this.pageWaiters = [];
    for (const fn of waiters) fn();
  }

  /** Ist das Token (so gut wie) abgelaufen und wurde nicht eben erst erneuert? */
  stale(): boolean {
    const { token, expiresAt } = this.state;
    if (token === '' || expiresAt === null) return false;
    const now = this.now();
    return now >= expiresAt * 1000 - (this.o.marginMs ?? MARGIN_MS) && now - this.lastRenewal >= (this.o.renewAgainMs ?? RENEW_AGAIN_MS);
  }

  /** Vor einer Anfrage: wartet, solange ein neues Token unterwegs ist bzw. eines nötig ist (höchstens maxWaitMs). */
  ready(): Promise<void> {
    if (!this.renewing) {
      if (!this.stale() || (this.o.online && !this.o.online())) return Promise.resolve();
      this.lastRenewal = this.now();
      this.renewing = this.renew().finally(() => {
        this.renewing = null;
      });
    }
    return Promise.race([this.renewing, sleep(this.o.maxWaitMs ?? MAX_WAIT_MS)]);
  }

  private async renew(): Promise<void> {
    const before = this.state.token;
    if (this.o.pageExpected?.()) {
      const fromPage = new Promise<void>((resolve) => this.pageWaiters.push(resolve));
      await Promise.race([fromPage, sleep(this.o.pageWaitMs ?? PAGE_WAIT_MS)]);
      if (this.state.token !== before) return;
    }
    await this.refresh();
  }

  /** Neues Token holen; gleichzeitige Aufrufe teilen sich eine Anfrage. */
  refresh(): Promise<FetchResult> {
    this.fetching ??= this.o
      .fetchToken()
      .catch((): FetchResult => ({ ok: false, offline: true }))
      .then((res) => {
        if (res.ok) this.take(res.state);
        return res;
      })
      .finally(() => {
        this.fetching = null;
      });
    return this.fetching;
  }
}
