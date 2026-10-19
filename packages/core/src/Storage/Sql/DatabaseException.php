<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage\Sql;

/** Fehler der Datenbank, die kein PDOException ist (z. B. aus wpdb); für den Fehlercode DB_ERROR. */
final class DatabaseException extends \RuntimeException
{
}
