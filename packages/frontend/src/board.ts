// Schwarzes Brett (Issue #5): zuletzt geladene Einträge, Ablauf und „auf diesem Gerät ausgeblendet“.
//
// Die App lädt das Brett beim Öffnen, wenn sie wieder in den Vordergrund kommt und wenn eine Benachrichtigung
// angetippt wird (Ziel #/brett). Ohne Netz liefert der Service Worker die zuletzt geladene Antwort; abgelaufene
// Einträge blendet die App anhand von expires_at selbst aus.

import { useEffect, useState } from 'react';
import { api, can, config, type BoardItem } from './api';

/** Höchstlänge einer Nachricht (wie BoardService::TEXT_MAX im Kern). */
export const BOARD_TEXT_MAX = 500;

export const BOARD_CHANGED = 'kompanie-board-changed';
const DISMISSED_KEY = 'kompanie-board-dismissed';

let items: BoardItem[] | null = null;

/** Wer das Brett sieht: Bereich an, Mitglieder und Anwärter (wie BoardService::isRecipient im Kern). */
export function boardVisible(): boolean {
  return !!config?.features.board && (can('member') || can('calendar'));
}

/** Schreiben, ändern und löschen. */
export function canPostBoard(): boolean {
  return !!config?.features.board && can('post_board');
}

/**
 * Lädt das Brett neu; ohne Verbindung (und ohne gespeicherte Antwort) bleibt der letzte Stand. Gibt die Fehlermeldung
 * zurück oder null.
 */
export async function refreshBoard(): Promise<string | null> {
  if (!boardVisible() && !canPostBoard()) return null;
  try {
    items = (await api.board()).items;
  } catch (err) {
    return (err as Error).message;
  }
  window.dispatchEvent(new Event(BOARD_CHANGED));
  return null;
}

/** Wurde das Brett schon einmal geladen? */
export const boardLoaded = (): boolean => items !== null;

const isValid = (item: BoardItem, now: number): boolean => Date.parse(item.expires_at) > now;

/** Kennung für „ausgeblendet“: Nach einer Änderung (neues updated_at) erscheint der Eintrag wieder. */
const dismissKey = (item: BoardItem): string => `${item.kind}:${item.id}@${item.updated_at}`;

function readDismissed(): string[] {
  try {
    const raw = JSON.parse(localStorage.getItem(DISMISSED_KEY) ?? '[]');
    return Array.isArray(raw) ? raw.filter((k): k is string => typeof k === 'string') : [];
  } catch {
    return [];
  }
}

export function isDismissed(item: BoardItem): boolean {
  return readDismissed().includes(dismissKey(item));
}

/** Auf diesem Gerät ausblenden; vergisst dabei Einträge, die es nicht mehr gibt. */
export function dismiss(item: BoardItem): void {
  const current = new Set((items ?? []).map(dismissKey));
  const next = [...readDismissed().filter((k) => current.has(k)), dismissKey(item)];
  try {
    localStorage.setItem(DISMISSED_KEY, JSON.stringify(next));
  } catch {
    // privater Modus o. ä.: nur bis zum nächsten Laden ausgeblendet
    dismissedInMemory.add(dismissKey(item));
  }
  window.dispatchEvent(new Event(BOARD_CHANGED));
}

const dismissedInMemory = new Set<string>();

/**
 * Gültige Einträge, neueste zuerst; aktualisiert sich bei neuen Daten und sobald einer abläuft. Lädt beim ersten
 * Aufruf und wenn die App wieder sichtbar wird.
 */
export function useBoard(): { items: BoardItem[]; dismissed: (item: BoardItem) => boolean } {
  const [, setTick] = useState(0);
  useEffect(() => {
    const changed = () => setTick((t) => t + 1);
    const visible = () => {
      if (document.visibilityState === 'visible') void refreshBoard();
    };
    window.addEventListener(BOARD_CHANGED, changed);
    document.addEventListener('visibilitychange', visible);
    if (items === null) void refreshBoard();
    return () => {
      window.removeEventListener(BOARD_CHANGED, changed);
      document.removeEventListener('visibilitychange', visible);
    };
  }, []);

  const now = Date.now();
  const valid = (items ?? []).filter((i) => isValid(i, now));
  // Neu zeichnen, sobald der nächste Eintrag abläuft.
  const nextAt = valid.length > 0 ? Math.min(...valid.map((i) => Date.parse(i.expires_at))) : null;
  useEffect(() => {
    if (nextAt === null) return;
    const t = setTimeout(() => setTick((v) => v + 1), Math.min(Math.max(0, nextAt - Date.now()) + 50, 2 ** 31 - 1));
    return () => clearTimeout(t);
  }, [nextAt]);

  return { items: valid, dismissed: (item) => dismissedInMemory.has(dismissKey(item)) || isDismissed(item) };
}
