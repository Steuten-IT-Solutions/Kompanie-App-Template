<?php

declare(strict_types=1);

namespace KompanieApp\Standalone;

/**
 * Tabellen nur der eigenständigen Version: Benutzer, Sitzungen, Einstellungen,
 * Zwischenspeicher, Anmeldeversuche, geplante Aufgaben.
 * Gleiche Regel wie im Kern: innerhalb einer Hauptversion nur hinzufügen.
 */
final class Migrations
{
    public const SETTING = 'standalone_schema_version';

    /** @return array<int, array{mysql: string[], sqlite: string[]}> */
    public static function all(): array
    {
        return [
            1 => [
                'mysql' => [
                    'CREATE TABLE IF NOT EXISTS {users} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  login varchar(60) NOT NULL,
  email varchar(191) NOT NULL DEFAULT \'\',
  name varchar(100) NOT NULL,
  password_hash varchar(255) NOT NULL,
  level varchar(10) NOT NULL DEFAULT \'member\',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  last_login_at datetime DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY login (login),
  KEY email (email)
) {options}',
                    'CREATE TABLE IF NOT EXISTS {sessions} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  token_hash char(64) NOT NULL,
  remember tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  renewed_at datetime NOT NULL,
  expires_at datetime NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY token_hash (token_hash),
  KEY user_id (user_id)
) {options}',
                    'CREATE TABLE IF NOT EXISTS {settings} (
  name varchar(191) NOT NULL,
  value longtext NOT NULL,
  PRIMARY KEY (name)
) {options}',
                    'CREATE TABLE IF NOT EXISTS {cache} (
  name varchar(191) NOT NULL,
  value longtext NOT NULL,
  expires_at bigint(20) NOT NULL,
  PRIMARY KEY (name)
) {options}',
                    'CREATE TABLE IF NOT EXISTS {login_attempts} (
  name varchar(191) NOT NULL,
  failures int(11) NOT NULL DEFAULT 0,
  window_start bigint(20) NOT NULL,
  PRIMARY KEY (name)
) {options}',
                    'CREATE TABLE IF NOT EXISTS {tasks} (
  name varchar(64) NOT NULL,
  interval_seconds int(11) NOT NULL,
  next_run bigint(20) NOT NULL,
  PRIMARY KEY (name)
) {options}',
                ],
                'sqlite' => [
                    'CREATE TABLE IF NOT EXISTS {users} (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  login TEXT NOT NULL UNIQUE COLLATE NOCASE,
  email TEXT NOT NULL DEFAULT \'\',
  name TEXT NOT NULL,
  password_hash TEXT NOT NULL,
  level TEXT NOT NULL DEFAULT \'member\',
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  last_login_at TEXT DEFAULT NULL
)',
                    'CREATE INDEX IF NOT EXISTS {users}_email ON {users} (email)',
                    'CREATE TABLE IF NOT EXISTS {sessions} (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  token_hash TEXT NOT NULL UNIQUE,
  remember INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL,
  renewed_at TEXT NOT NULL,
  expires_at TEXT NOT NULL
)',
                    'CREATE INDEX IF NOT EXISTS {sessions}_user_id ON {sessions} (user_id)',
                    'CREATE TABLE IF NOT EXISTS {settings} (name TEXT PRIMARY KEY, value TEXT NOT NULL)',
                    'CREATE TABLE IF NOT EXISTS {cache} (name TEXT PRIMARY KEY, value TEXT NOT NULL, expires_at INTEGER NOT NULL)',
                    'CREATE TABLE IF NOT EXISTS {login_attempts} (name TEXT PRIMARY KEY, failures INTEGER NOT NULL DEFAULT 0, window_start INTEGER NOT NULL)',
                    'CREATE TABLE IF NOT EXISTS {tasks} (name TEXT PRIMARY KEY, interval_seconds INTEGER NOT NULL, next_run INTEGER NOT NULL)',
                ],
            ],
            // Vor- und Nachname getrennt; name bleibt der Anzeigename „Vorname Nachname“. Bestehende Konten haben beide
            // leer und werden beim Lesen geteilt (PdoUsers::toUser), bis jemand die Angaben ändert.
            2 => [
                'mysql' => [
                    "ALTER TABLE {users} ADD COLUMN first_name varchar(100) NOT NULL DEFAULT ''",
                    "ALTER TABLE {users} ADD COLUMN last_name varchar(100) NOT NULL DEFAULT ''",
                ],
                'sqlite' => [
                    "ALTER TABLE {users} ADD COLUMN first_name TEXT NOT NULL DEFAULT ''",
                    "ALTER TABLE {users} ADD COLUMN last_name TEXT NOT NULL DEFAULT ''",
                ],
            ],
            // Stillgelegte Konten (ehemalige Mitglieder, PdoUsers::deactivate): Zeitpunkt; solange gesetzt, ist die Anmeldung
            // gesperrt.
            3 => [
                'mysql' => ['ALTER TABLE {users} ADD COLUMN disabled_at datetime DEFAULT NULL'],
                'sqlite' => ['ALTER TABLE {users} ADD COLUMN disabled_at TEXT DEFAULT NULL'],
            ],
        ];
    }
}
