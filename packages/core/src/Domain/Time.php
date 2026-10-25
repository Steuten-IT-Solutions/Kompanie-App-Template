<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\Port\Clock;

/** Zeitangaben für die Datenbank (UTC) und für die Kompanie (Ortszeit). */
final class Time
{
    public function __construct(private readonly Clock $clock)
    {
    }

    /** Jetzt in UTC als 'Y-m-d H:i:s' für die Datenbank. */
    public function nowUtc(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** Heute in der Zeitzone der Kompanie. */
    public function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone($this->clock->timezone())->setTime(0, 0);
    }

    public function timezone(): \DateTimeZone
    {
        return $this->clock->timezone();
    }

    /** Zeitpunkt in der Zeitzone der Kompanie formatieren. */
    public function format(int $timestamp, string $format): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($this->timezone())->format($format);
    }

    public function timestamp(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    /**
     * Zeitpunkt aus der App, in UTC für die Datenbank ('Y-m-d H:i:s'); null, wenn der Wert ungültig ist. Angenommen
     * werden ISO 8601 mit Zeitzone (Z oder ±hh:mm, auch mit Sekunden und Bruchteilen) und „JJJJ-MM-TTTHH:MM“ (auch mit
     * Sekunden oder Leerzeichen) ohne Zeitzone; dieser wird in der Zeitzone der Kompanie gelesen. Gemeinsam für Umfragen
     * (Ende) und das Schwarze Brett (Enddatum).
     */
    public function parseToUtc(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        $dt = null;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?$/', $value, $m) === 1) {
            if (checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && (int) $m[4] < 24 && (int) $m[5] < 60 && (int) ($m[6] ?? 0) < 60) {
                $dt = new \DateTimeImmutable(sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6] ?? '00'), $this->timezone());
            }
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/', $value, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            try {
                $dt = new \DateTimeImmutable($value);
            } catch (\Exception) {
                $dt = null;
            }
        }
        return $dt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** UTC-Datenbankzeit als ISO 8601. */
    public static function utcToIso(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format('c');
    }
}
