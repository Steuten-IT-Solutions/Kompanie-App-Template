<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage\Sql;

/**
 * Datenbank-Migrationen der App für MySQL/MariaDB und SQLite.
 *
 * Regel: Innerhalb einer Hauptversion nur hinzufügen (Tabellen, Spalten, Indizes),
 * nie umbenennen oder löschen. So laufen ältere Versionen derselben Hauptversion
 * auch mit einem neueren Datenbestand, und Zurückrollen tauscht nur den Programmcode.
 *
 * Version 1 entspricht für MySQL genau dem Schema der ersten Umsetzung
 * (Datenbankversion 3); vorhandene Tabellen bleiben dank IF NOT EXISTS unberührt.
 */
final class Migrations
{
    /** @return array<int, array{mysql: string[], sqlite: string[]}> Version => Anweisungen */
    public static function all(): array
    {
        return [
            1 => [
                'mysql' => [
                    'CREATE TABLE IF NOT EXISTS {point_catalog} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(191) NOT NULL,
  points int(11) NOT NULL DEFAULT 0,
  active tinyint(1) NOT NULL DEFAULT 1,
  sort int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (id)
) {options}',
                    'CREATE TABLE IF NOT EXISTS {points} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  points int(11) NOT NULL,
  reason varchar(255) NOT NULL DEFAULT \'\',
  catalog_id bigint(20) unsigned DEFAULT NULL,
  entry_date date NOT NULL,
  created_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  deleted_at datetime DEFAULT NULL,
  deleted_by bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (id),
  KEY user_id (user_id)
) {options}',
                    'CREATE TABLE IF NOT EXISTS {penalties} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  amount_cents int(11) NOT NULL,
  reason varchar(255) NOT NULL DEFAULT \'\',
  entry_date date NOT NULL,
  status varchar(10) NOT NULL DEFAULT \'open\',
  paid_at datetime DEFAULT NULL,
  paid_marked_by bigint(20) unsigned DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  deleted_at datetime DEFAULT NULL,
  deleted_by bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (id),
  KEY user_id (user_id)
) {options}',
                    'CREATE TABLE IF NOT EXISTS {events} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(191) NOT NULL,
  start_date date NOT NULL,
  start_time time DEFAULT NULL,
  end_date date DEFAULT NULL,
  end_time time DEFAULT NULL,
  location varchar(191) NOT NULL DEFAULT \'\',
  description text NOT NULL,
  url varchar(255) NOT NULL DEFAULT \'\',
  created_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY (id),
  KEY start_date (start_date)
) {options}',
                    'CREATE TABLE IF NOT EXISTS {albums} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  drive_id varchar(64) NOT NULL,
  year varchar(20) NOT NULL DEFAULT \'\',
  title varchar(191) NOT NULL DEFAULT \'\',
  sort_date datetime NOT NULL,
  synced_at datetime NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY drive_id (drive_id)
) {options}',
                    'CREATE TABLE IF NOT EXISTS {photos} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  album_id bigint(20) unsigned NOT NULL,
  drive_file_id varchar(64) NOT NULL,
  name varchar(255) NOT NULL DEFAULT \'\',
  is_intern tinyint(1) NOT NULL DEFAULT 0,
  width int(11) NOT NULL DEFAULT 0,
  height int(11) NOT NULL DEFAULT 0,
  taken_at datetime DEFAULT NULL,
  md5 varchar(64) NOT NULL DEFAULT \'\',
  file_key char(32) NOT NULL,
  status varchar(10) NOT NULL DEFAULT \'pending\',
  error varchar(255) NOT NULL DEFAULT \'\',
  PRIMARY KEY (id),
  UNIQUE KEY drive_file_id (drive_file_id),
  KEY album_id (album_id),
  KEY status (status)
) {options}',
                ],
                'sqlite' => [
                    'CREATE TABLE IF NOT EXISTS {point_catalog} (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  points INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1,
  sort INTEGER NOT NULL DEFAULT 0
)',
                    'CREATE TABLE IF NOT EXISTS {points} (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  points INTEGER NOT NULL,
  reason TEXT NOT NULL DEFAULT \'\',
  catalog_id INTEGER DEFAULT NULL,
  entry_date TEXT NOT NULL,
  created_by INTEGER NOT NULL,
  created_at TEXT NOT NULL,
  deleted_at TEXT DEFAULT NULL,
  deleted_by INTEGER DEFAULT NULL
)',
                    'CREATE INDEX IF NOT EXISTS {points}_user_id ON {points} (user_id)',
                    'CREATE TABLE IF NOT EXISTS {penalties} (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  amount_cents INTEGER NOT NULL,
  reason TEXT NOT NULL DEFAULT \'\',
  entry_date TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT \'open\',
  paid_at TEXT DEFAULT NULL,
  paid_marked_by INTEGER DEFAULT NULL,
  created_by INTEGER NOT NULL,
  created_at TEXT NOT NULL,
  deleted_at TEXT DEFAULT NULL,
  deleted_by INTEGER DEFAULT NULL
)',
                    'CREATE INDEX IF NOT EXISTS {penalties}_user_id ON {penalties} (user_id)',
                    'CREATE TABLE IF NOT EXISTS {events} (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  start_date TEXT NOT NULL,
  start_time TEXT DEFAULT NULL,
  end_date TEXT DEFAULT NULL,
  end_time TEXT DEFAULT NULL,
  location TEXT NOT NULL DEFAULT \'\',
  description TEXT NOT NULL DEFAULT \'\',
  url TEXT NOT NULL DEFAULT \'\',
  created_by INTEGER NOT NULL,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
)',
                    'CREATE INDEX IF NOT EXISTS {events}_start_date ON {events} (start_date)',
                    'CREATE TABLE IF NOT EXISTS {albums} (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  drive_id TEXT NOT NULL UNIQUE,
  year TEXT NOT NULL DEFAULT \'\',
  title TEXT NOT NULL DEFAULT \'\',
  sort_date TEXT NOT NULL,
  synced_at TEXT NOT NULL
)',
                    'CREATE TABLE IF NOT EXISTS {photos} (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  album_id INTEGER NOT NULL,
  drive_file_id TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL DEFAULT \'\',
  is_intern INTEGER NOT NULL DEFAULT 0,
  width INTEGER NOT NULL DEFAULT 0,
  height INTEGER NOT NULL DEFAULT 0,
  taken_at TEXT DEFAULT NULL,
  md5 TEXT NOT NULL DEFAULT \'\',
  file_key TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT \'pending\',
  error TEXT NOT NULL DEFAULT \'\'
)',
                    'CREATE INDEX IF NOT EXISTS {photos}_album_id ON {photos} (album_id)',
                    'CREATE INDEX IF NOT EXISTS {photos}_status ON {photos} (status)',
                ],
            ],
            // Fehlversuche je Bild: fehlgeschlagene Bilder werden beim Abgleich erneut versucht, höchstens 5-mal.
            2 => [
                'mysql' => ['ALTER TABLE {photos} ADD COLUMN attempts int(11) NOT NULL DEFAULT 0'],
                'sqlite' => ['ALTER TABLE {photos} ADD COLUMN attempts INTEGER NOT NULL DEFAULT 0'],
            ],
            // Punktefaktor der Rolle beim Buchen (Issue #28): zählt points × factor.
            3 => [
                'mysql' => ['ALTER TABLE {points} ADD COLUMN factor decimal(4,2) NOT NULL DEFAULT 1.00'],
                'sqlite' => ['ALTER TABLE {points} ADD COLUMN factor REAL NOT NULL DEFAULT 1'],
            ],
            // Web Push (Issue #19): Abos der Geräte (user_id 0 = Gast) und Warteschlange für den Versand.
            // Nicht in Sicherungen: Abos gehören zu den Schlüsseln dieser Installation.
            4 => [
                'mysql' => [
                    'CREATE TABLE IF NOT EXISTS {push_subscriptions} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  endpoint text NOT NULL,
  endpoint_hash char(64) NOT NULL,
  p256dh varchar(100) NOT NULL,
  auth varchar(40) NOT NULL,
  types varchar(255) NOT NULL DEFAULT \'\',
  details tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  last_sent_at datetime DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY endpoint_hash (endpoint_hash),
  KEY user_id (user_id)
) {options}',
                    'CREATE TABLE IF NOT EXISTS {push_queue} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  subscription_id bigint(20) unsigned NOT NULL,
  payload text NOT NULL,
  urgency varchar(10) NOT NULL DEFAULT \'normal\',
  ttl int(11) NOT NULL DEFAULT 86400,
  attempts int(11) NOT NULL DEFAULT 0,
  next_try datetime NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY (id),
  KEY next_try (next_try),
  KEY subscription_id (subscription_id)
) {options}',
                ],
                'sqlite' => [
                    'CREATE TABLE IF NOT EXISTS {push_subscriptions} (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL DEFAULT 0,
  endpoint TEXT NOT NULL,
  endpoint_hash TEXT NOT NULL,
  p256dh TEXT NOT NULL,
  auth TEXT NOT NULL,
  types TEXT NOT NULL DEFAULT \'\',
  details INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  last_sent_at TEXT DEFAULT NULL
)',
                    'CREATE UNIQUE INDEX IF NOT EXISTS {push_subscriptions}_endpoint_hash ON {push_subscriptions} (endpoint_hash)',
                    'CREATE INDEX IF NOT EXISTS {push_subscriptions}_user_id ON {push_subscriptions} (user_id)',
                    'CREATE TABLE IF NOT EXISTS {push_queue} (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  subscription_id INTEGER NOT NULL,
  payload TEXT NOT NULL,
  urgency TEXT NOT NULL DEFAULT \'normal\',
  ttl INTEGER NOT NULL DEFAULT 86400,
  attempts INTEGER NOT NULL DEFAULT 0,
  next_try TEXT NOT NULL,
  created_at TEXT NOT NULL
)',
                    'CREATE INDEX IF NOT EXISTS {push_queue}_next_try ON {push_queue} (next_try)',
                    'CREATE INDEX IF NOT EXISTS {push_queue}_subscription_id ON {push_queue} (subscription_id)',
                ],
            ],
            // Dienstgrade (Issue #9): Liste mit Reihenfolge und optionaler Punkteschwelle, zugewiesener Dienstgrad je
            // Mitglied (der Kern hat keine Benutzer-Optionen) und Verlauf mit den Titeln als Momentaufnahme.
            5 => [
                'mysql' => [
                    'CREATE TABLE IF NOT EXISTS {ranks} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(60) NOT NULL,
  sort int(11) NOT NULL DEFAULT 0,
  min_points int(11) DEFAULT NULL,
  active tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id)
) {options}',
                    'CREATE TABLE IF NOT EXISTS {member_ranks} (
  user_id bigint(20) unsigned NOT NULL,
  rank_id bigint(20) unsigned NOT NULL,
  assigned_at datetime NOT NULL,
  PRIMARY KEY (user_id),
  KEY rank_id (rank_id)
) {options}',
                    'CREATE TABLE IF NOT EXISTS {rank_history} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  from_rank_id bigint(20) unsigned DEFAULT NULL,
  to_rank_id bigint(20) unsigned DEFAULT NULL,
  from_title varchar(60) NOT NULL DEFAULT \'\',
  to_title varchar(60) NOT NULL DEFAULT \'\',
  kind varchar(12) NOT NULL,
  points_at_change decimal(10,2) DEFAULT NULL,
  reason varchar(255) NOT NULL DEFAULT \'\',
  changed_by bigint(20) unsigned NOT NULL,
  changed_at datetime NOT NULL,
  PRIMARY KEY (id),
  KEY user_id (user_id)
) {options}',
                ],
                'sqlite' => [
                    'CREATE TABLE IF NOT EXISTS {ranks} (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  sort INTEGER NOT NULL DEFAULT 0,
  min_points INTEGER DEFAULT NULL,
  active INTEGER NOT NULL DEFAULT 1
)',
                    'CREATE TABLE IF NOT EXISTS {member_ranks} (
  user_id INTEGER PRIMARY KEY,
  rank_id INTEGER NOT NULL,
  assigned_at TEXT NOT NULL
)',
                    'CREATE INDEX IF NOT EXISTS {member_ranks}_rank_id ON {member_ranks} (rank_id)',
                    'CREATE TABLE IF NOT EXISTS {rank_history} (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  from_rank_id INTEGER DEFAULT NULL,
  to_rank_id INTEGER DEFAULT NULL,
  from_title TEXT NOT NULL DEFAULT \'\',
  to_title TEXT NOT NULL DEFAULT \'\',
  kind TEXT NOT NULL,
  points_at_change REAL DEFAULT NULL,
  reason TEXT NOT NULL DEFAULT \'\',
  changed_by INTEGER NOT NULL,
  changed_at TEXT NOT NULL
)',
                    'CREATE INDEX IF NOT EXISTS {rank_history}_user_id ON {rank_history} (user_id)',
                ],
            ],
            // Titel (Issue #22): Ehrentitel und Ämter, unabhängig von Dienstgrad und Punkten; mehrere je Mitglied.
            6 => [
                'mysql' => [
                    'CREATE TABLE IF NOT EXISTS {titles} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(60) NOT NULL,
  sort int(11) NOT NULL DEFAULT 0,
  active tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id)
) {options}',
                    'CREATE TABLE IF NOT EXISTS {member_titles} (
  user_id bigint(20) unsigned NOT NULL,
  title_id bigint(20) unsigned NOT NULL,
  assigned_at datetime NOT NULL,
  PRIMARY KEY (user_id, title_id),
  KEY title_id (title_id)
) {options}',
                ],
                'sqlite' => [
                    'CREATE TABLE IF NOT EXISTS {titles} (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  sort INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1
)',
                    'CREATE TABLE IF NOT EXISTS {member_titles} (
  user_id INTEGER NOT NULL,
  title_id INTEGER NOT NULL,
  assigned_at TEXT NOT NULL,
  PRIMARY KEY (user_id, title_id)
)',
                    'CREATE INDEX IF NOT EXISTS {member_titles}_title_id ON {member_titles} (title_id)',
                ],
            ],
            // Anwesenheit (Issue #21): Listen je Termin (aus dem Kalender oder frei) mit Titel und Datum als
            // Momentaufnahme, Status je Person, Punkte aus dem Vorschlag mit der ID der Liste.
            7 => [
                'mysql' => [
                    'CREATE TABLE IF NOT EXISTS {attendance_lists} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source varchar(10) NOT NULL,
  event_key varchar(64) NOT NULL,
  title varchar(191) NOT NULL,
  event_date date NOT NULL,
  created_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  updated_by bigint(20) unsigned NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY event (source, event_key),
  KEY event_date (event_date)
) {options}',
                    'CREATE TABLE IF NOT EXISTS {attendance_entries} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  list_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  status varchar(15) NOT NULL,
  updated_by bigint(20) unsigned NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY list_user (list_id, user_id),
  KEY user_id (user_id)
) {options}',
                    // Eine Anweisung: wird übersprungen, wenn die Spalte schon da ist (wiederholbar).
                    'ALTER TABLE {points} ADD COLUMN attendance_id bigint(20) unsigned DEFAULT NULL, ADD KEY attendance_id (attendance_id)',
                ],
                'sqlite' => [
                    'CREATE TABLE IF NOT EXISTS {attendance_lists} (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  source TEXT NOT NULL,
  event_key TEXT NOT NULL,
  title TEXT NOT NULL,
  event_date TEXT NOT NULL,
  created_by INTEGER NOT NULL,
  created_at TEXT NOT NULL,
  updated_by INTEGER NOT NULL,
  updated_at TEXT NOT NULL
)',
                    'CREATE UNIQUE INDEX IF NOT EXISTS {attendance_lists}_event ON {attendance_lists} (source, event_key)',
                    'CREATE INDEX IF NOT EXISTS {attendance_lists}_event_date ON {attendance_lists} (event_date)',
                    'CREATE TABLE IF NOT EXISTS {attendance_entries} (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  list_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  status TEXT NOT NULL,
  updated_by INTEGER NOT NULL,
  updated_at TEXT NOT NULL
)',
                    'CREATE UNIQUE INDEX IF NOT EXISTS {attendance_entries}_list_user ON {attendance_entries} (list_id, user_id)',
                    'CREATE INDEX IF NOT EXISTS {attendance_entries}_user_id ON {attendance_entries} (user_id)',
                    'ALTER TABLE {points} ADD COLUMN attendance_id INTEGER DEFAULT NULL',
                    'CREATE INDEX IF NOT EXISTS {points}_attendance_id ON {points} (attendance_id)',
                ],
            ],
            // Dokumente (Issue #2): Kategorien mit fester Reihenfolge und Dateien mit Stand und Sichtbarkeit. Die Dateien
            // liegen geschützt in der Dateiablage (zufälliger Name in file); version wechselt mit jeder neuen Fassung.
            8 => [
                'mysql' => [
                    'CREATE TABLE IF NOT EXISTS {doc_categories} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(191) NOT NULL,
  sort int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (id)
) {options}',
                    'CREATE TABLE IF NOT EXISTS {documents} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(191) NOT NULL,
  category_id bigint(20) unsigned DEFAULT NULL,
  doc_date date NOT NULL,
  visibility varchar(10) NOT NULL,
  file varchar(64) NOT NULL,
  ext varchar(5) NOT NULL,
  size bigint(20) unsigned NOT NULL,
  version varchar(16) NOT NULL,
  uploaded_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY (id),
  KEY category_id (category_id)
) {options}',
                ],
                'sqlite' => [
                    'CREATE TABLE IF NOT EXISTS {doc_categories} (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  sort INTEGER NOT NULL DEFAULT 0
)',
                    'CREATE TABLE IF NOT EXISTS {documents} (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  category_id INTEGER DEFAULT NULL,
  doc_date TEXT NOT NULL,
  visibility TEXT NOT NULL,
  file TEXT NOT NULL,
  ext TEXT NOT NULL,
  size INTEGER NOT NULL,
  version TEXT NOT NULL,
  uploaded_by INTEGER NOT NULL,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
)',
                    'CREATE INDEX IF NOT EXISTS {documents}_category_id ON {documents} (category_id)',
                ],
            ],
            // Abzeichen der Dienstgrade (Issue #17): Name der Dateien in der geschützten Ablage (abzeichen/), ursprüngliches
            // Format und Fassung (wechselt mit jedem Hochladen, damit die Adresse). Leer = kein Abzeichen.
            9 => [
                'mysql' => [
                    "ALTER TABLE {ranks} ADD COLUMN badge_file varchar(40) NOT NULL DEFAULT ''",
                    "ALTER TABLE {ranks} ADD COLUMN badge_ext varchar(5) NOT NULL DEFAULT ''",
                    "ALTER TABLE {ranks} ADD COLUMN badge_version varchar(16) NOT NULL DEFAULT ''",
                ],
                'sqlite' => [
                    "ALTER TABLE {ranks} ADD COLUMN badge_file TEXT NOT NULL DEFAULT ''",
                    "ALTER TABLE {ranks} ADD COLUMN badge_ext TEXT NOT NULL DEFAULT ''",
                    "ALTER TABLE {ranks} ADD COLUMN badge_version TEXT NOT NULL DEFAULT ''",
                ],
            ],
            // Schwarzes Brett (Issue #5): dringende Nachrichten, je 24 Stunden gültig (expires_at), danach gelöscht.
            10 => [
                'mysql' => [
                    'CREATE TABLE IF NOT EXISTS {board_messages} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  body text NOT NULL,
  author_id bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  expires_at datetime NOT NULL,
  PRIMARY KEY (id),
  KEY expires_at (expires_at)
) {options}',
                ],
                'sqlite' => [
                    'CREATE TABLE IF NOT EXISTS {board_messages} (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  body TEXT NOT NULL,
  author_id INTEGER NOT NULL,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  expires_at TEXT NOT NULL
)',
                    'CREATE INDEX IF NOT EXISTS {board_messages}_expires_at ON {board_messages} (expires_at)',
                ],
            ],
            // Umfragen (Issue #4): eine Frage je Umfrage. poll_votes hält fest, wer teilgenommen hat (für has_voted und die
            // Erinnerung); poll_answers die Antworten, je Teilnahme unter einem Pseudonym (vote_ref, HMAC der Benutzer-ID mit
            // dem Geheimnis der Installation). user_id nur bei namentlichen Umfragen, sonst NULL; keine Zeitstempel und keine
            // laufende Nummer, damit sich anonyme Antworten nicht über die Reihenfolge zuordnen lassen. option_id 0 = Freitext.
            11 => [
                'mysql' => [
                    'CREATE TABLE IF NOT EXISTS {polls} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(191) NOT NULL,
  description text NOT NULL,
  type varchar(10) NOT NULL,
  anonymous tinyint(1) NOT NULL DEFAULT 0,
  results varchar(12) NOT NULL,
  ends_at datetime DEFAULT NULL,
  closed_at datetime DEFAULT NULL,
  reminded_at datetime DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY (id),
  KEY ends_at (ends_at)
) {options}',
                    'CREATE TABLE IF NOT EXISTS {poll_options} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  poll_id bigint(20) unsigned NOT NULL,
  title varchar(191) NOT NULL,
  sort int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY poll_id (poll_id)
) {options}',
                    'CREATE TABLE IF NOT EXISTS {poll_votes} (
  poll_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  voted_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY (poll_id, user_id),
  KEY user_id (user_id)
) {options}',
                    'CREATE TABLE IF NOT EXISTS {poll_answers} (
  poll_id bigint(20) unsigned NOT NULL,
  vote_ref varchar(64) NOT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  option_id bigint(20) unsigned NOT NULL DEFAULT 0,
  answer_text text NOT NULL,
  PRIMARY KEY (poll_id, vote_ref, option_id),
  KEY user_id (user_id),
  KEY vote_ref (vote_ref)
) {options}',
                ],
                'sqlite' => [
                    'CREATE TABLE IF NOT EXISTS {polls} (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  description TEXT NOT NULL,
  type TEXT NOT NULL,
  anonymous INTEGER NOT NULL DEFAULT 0,
  results TEXT NOT NULL,
  ends_at TEXT DEFAULT NULL,
  closed_at TEXT DEFAULT NULL,
  reminded_at TEXT DEFAULT NULL,
  created_by INTEGER NOT NULL,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
)',
                    'CREATE INDEX IF NOT EXISTS {polls}_ends_at ON {polls} (ends_at)',
                    'CREATE TABLE IF NOT EXISTS {poll_options} (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  poll_id INTEGER NOT NULL,
  title TEXT NOT NULL,
  sort INTEGER NOT NULL DEFAULT 0
)',
                    'CREATE INDEX IF NOT EXISTS {poll_options}_poll_id ON {poll_options} (poll_id)',
                    'CREATE TABLE IF NOT EXISTS {poll_votes} (
  poll_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  voted_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  PRIMARY KEY (poll_id, user_id)
)',
                    'CREATE INDEX IF NOT EXISTS {poll_votes}_user_id ON {poll_votes} (user_id)',
                    'CREATE TABLE IF NOT EXISTS {poll_answers} (
  poll_id INTEGER NOT NULL,
  vote_ref TEXT NOT NULL,
  user_id INTEGER DEFAULT NULL,
  option_id INTEGER NOT NULL DEFAULT 0,
  answer_text TEXT NOT NULL,
  PRIMARY KEY (poll_id, vote_ref, option_id)
) WITHOUT ROWID',
                    'CREATE INDEX IF NOT EXISTS {poll_answers}_user_id ON {poll_answers} (user_id)',
                    'CREATE INDEX IF NOT EXISTS {poll_answers}_vote_ref ON {poll_answers} (vote_ref)',
                ],
            ],
            // Geburtstage (Issue #1): einer je Konto. year optional (NULL = ohne Jahr); visible = anderen Mitgliedern zeigen,
            // show_age = Alter anzeigen. last_notified: Tag (Zeitzone der Kompanie, Y-m-d), für den die Benachrichtigung
            // schon beansprucht wurde (gegen doppelten Versand bei wiederholten Läufen).
            12 => [
                'mysql' => [
                    'CREATE TABLE IF NOT EXISTS {birthdays} (
  user_id bigint(20) unsigned NOT NULL,
  month tinyint(2) unsigned NOT NULL,
  day tinyint(2) unsigned NOT NULL,
  year smallint(4) unsigned DEFAULT NULL,
  visible tinyint(1) NOT NULL DEFAULT 1,
  show_age tinyint(1) NOT NULL DEFAULT 0,
  last_notified date DEFAULT NULL,
  updated_by bigint(20) unsigned NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY (user_id),
  KEY month_day (month, day)
) {options}',
                ],
                'sqlite' => [
                    'CREATE TABLE IF NOT EXISTS {birthdays} (
  user_id INTEGER NOT NULL PRIMARY KEY,
  month INTEGER NOT NULL,
  day INTEGER NOT NULL,
  year INTEGER DEFAULT NULL,
  visible INTEGER NOT NULL DEFAULT 1,
  show_age INTEGER NOT NULL DEFAULT 0,
  last_notified TEXT DEFAULT NULL,
  updated_by INTEGER NOT NULL,
  updated_at TEXT NOT NULL
)',
                    'CREATE INDEX IF NOT EXISTS {birthdays}_month_day ON {birthdays} (month, day)',
                ],
            ],
            // Inventar (Issue #6): ein Ort je Eintrag (gleiches Material an zwei Orten = zwei Einträge). quantity mit drei
            // Nachkommastellen; optionale Texte leer statt NULL. Gelöschte Einträge bleiben mit deleted_at stehen, damit der
            // Verlauf lesbar bleibt. inventory_log: genau ein Eintrag je Änderung und betroffenem Eintrag; changes als JSON
            // (Feld => {old, new}, beim Umlagern zusätzlich move), title, unit und location als Momentaufnahme.
            13 => [
                'mysql' => [
                    'CREATE TABLE IF NOT EXISTS {inventory_items} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(100) NOT NULL,
  category varchar(100) NOT NULL DEFAULT \'\',
  quantity decimal(15,3) NOT NULL DEFAULT 0,
  unit varchar(20) NOT NULL DEFAULT \'\',
  location varchar(100) NOT NULL,
  note text NOT NULL,
  created_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  updated_by bigint(20) unsigned NOT NULL,
  updated_at datetime NOT NULL,
  deleted_at datetime DEFAULT NULL,
  deleted_by bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (id),
  KEY deleted_at (deleted_at)
) {options}',
                    'CREATE TABLE IF NOT EXISTS {inventory_log} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  item_id bigint(20) unsigned NOT NULL,
  action varchar(10) NOT NULL,
  changes text NOT NULL,
  reason varchar(255) NOT NULL DEFAULT \'\',
  title varchar(100) NOT NULL,
  unit varchar(20) NOT NULL DEFAULT \'\',
  location varchar(100) NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY (id),
  KEY item_id (item_id)
) {options}',
                ],
                'sqlite' => [
                    'CREATE TABLE IF NOT EXISTS {inventory_items} (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  category TEXT NOT NULL DEFAULT \'\',
  quantity NUMERIC NOT NULL DEFAULT 0,
  unit TEXT NOT NULL DEFAULT \'\',
  location TEXT NOT NULL,
  note TEXT NOT NULL DEFAULT \'\',
  created_by INTEGER NOT NULL,
  created_at TEXT NOT NULL,
  updated_by INTEGER NOT NULL,
  updated_at TEXT NOT NULL,
  deleted_at TEXT DEFAULT NULL,
  deleted_by INTEGER DEFAULT NULL
)',
                    'CREATE INDEX IF NOT EXISTS {inventory_items}_deleted_at ON {inventory_items} (deleted_at)',
                    'CREATE TABLE IF NOT EXISTS {inventory_log} (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  item_id INTEGER NOT NULL,
  action TEXT NOT NULL,
  changes TEXT NOT NULL,
  reason TEXT NOT NULL DEFAULT \'\',
  title TEXT NOT NULL,
  unit TEXT NOT NULL DEFAULT \'\',
  location TEXT NOT NULL,
  user_id INTEGER NOT NULL,
  created_at TEXT NOT NULL
)',
                    'CREATE INDEX IF NOT EXISTS {inventory_log}_item_id ON {inventory_log} (item_id)',
                ],
            ],
            // Zusatzrollen (Issue #66): Die Grundrolle bleibt, wo sie war (Standalone users.level, WordPress Benutzer-Option);
            // hier stehen nur die zusätzlichen Rollen, beliebig viele je Person, in beiden Varianten im Kern. role: Schlüssel
            // aus Domain\RoleService (höchstens Auth\Roles::KEY_MAX Zeichen).
            14 => [
                'mysql' => [
                    'CREATE TABLE IF NOT EXISTS {member_roles} (
  user_id bigint(20) unsigned NOT NULL,
  role varchar(10) NOT NULL,
  assigned_at datetime NOT NULL,
  PRIMARY KEY (user_id, role),
  KEY role (role)
) {options}',
                ],
                'sqlite' => [
                    'CREATE TABLE IF NOT EXISTS {member_roles} (
  user_id INTEGER NOT NULL,
  role TEXT NOT NULL,
  assigned_at TEXT NOT NULL,
  PRIMARY KEY (user_id, role)
)',
                    'CREATE INDEX IF NOT EXISTS {member_roles}_role ON {member_roles} (role)',
                ],
            ],
            // Fehlermeldungen (Issue #64): Warteschlange bis zum Versand an die Betreuer (project-cockpit). report: die
            // Angaben als JSON (Beschreibung, Erwartung, Seite, Version, Gerät, Rolle, ggf. Name, letzte Fehlermeldung).
            // Das Bildschirmfoto als Base64 in derselben Zeile (höchstens 2 MB, ohne Dateien neben der Datenbank: Löschen
            // nach dem Versand, nach 7 Tagen oder mit dem Konto entfernt alles auf einmal). user_id nur, wenn der Name
            // mitgeschickt wird (sonst 0), damit die Meldung mit dem Konto gelöscht wird. Nicht in Sicherungen.
            15 => [
                'mysql' => [
                    'CREATE TABLE IF NOT EXISTS {bug_reports} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid char(32) NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  report text NOT NULL,
  screenshot mediumtext DEFAULT NULL,
  screenshot_type varchar(20) NOT NULL DEFAULT \'\',
  attempts int(11) NOT NULL DEFAULT 0,
  last_error varchar(255) NOT NULL DEFAULT \'\',
  created_at int(11) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uuid (uuid),
  KEY user_id (user_id),
  KEY created_at (created_at)
) {options}',
                ],
                'sqlite' => [
                    'CREATE TABLE IF NOT EXISTS {bug_reports} (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  uuid TEXT NOT NULL,
  user_id INTEGER NOT NULL DEFAULT 0,
  report TEXT NOT NULL,
  screenshot TEXT DEFAULT NULL,
  screenshot_type TEXT NOT NULL DEFAULT \'\',
  attempts INTEGER NOT NULL DEFAULT 0,
  last_error TEXT NOT NULL DEFAULT \'\',
  created_at INTEGER NOT NULL
)',
                    'CREATE UNIQUE INDEX IF NOT EXISTS {bug_reports}_uuid ON {bug_reports} (uuid)',
                    'CREATE INDEX IF NOT EXISTS {bug_reports}_user_id ON {bug_reports} (user_id)',
                    'CREATE INDEX IF NOT EXISTS {bug_reports}_created_at ON {bug_reports} (created_at)',
                ],
            ],
            // Fotos der Mitglieder: eines je Konto, als JPEG (Base64) in 128 (small) und 480 Pixeln (large). In der Datenbank
            // statt in der Dateiablage, damit das Löschen eines Kontos (UserData::purge, auch im WordPress-Netzwerk) sie
            // sicher mitnimmt und die Sicherung sie enthält. version wechselt mit jedem Hochladen (Adresse des Fotos).
            16 => [
                'mysql' => [
                    'CREATE TABLE IF NOT EXISTS {member_photos} (
  user_id bigint(20) unsigned NOT NULL,
  version varchar(32) NOT NULL,
  small mediumtext NOT NULL,
  large mediumtext NOT NULL,
  updated_by bigint(20) unsigned NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY (user_id)
) {options}',
                ],
                'sqlite' => [
                    'CREATE TABLE IF NOT EXISTS {member_photos} (
  user_id INTEGER NOT NULL PRIMARY KEY,
  version TEXT NOT NULL,
  small TEXT NOT NULL,
  large TEXT NOT NULL,
  updated_by INTEGER NOT NULL,
  updated_at TEXT NOT NULL
)',
                ],
            ],
        ];
    }

    public static function latest(): int
    {
        return max(array_keys(self::all()));
    }
}
