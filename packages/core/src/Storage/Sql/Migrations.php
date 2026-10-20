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
        ];
    }

    public static function latest(): int
    {
        return max(array_keys(self::all()));
    }
}
