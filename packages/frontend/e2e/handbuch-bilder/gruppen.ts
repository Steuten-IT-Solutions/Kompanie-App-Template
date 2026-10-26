import type { Gruppe } from './fotograf';
import admin from './admin';
import allgemein from './allgemein';
import inhalte from './inhalte';
import organisation from './organisation';
import personen from './personen';
import punkte from './punkte';

// Teile des Handbuchs mit ihren Fotos (je eine Datei in diesem Ordner), in dieser Reihenfolge.
export const GRUPPEN: Record<string, Gruppe> = { allgemein, punkte, personen, inhalte, organisation, admin };
