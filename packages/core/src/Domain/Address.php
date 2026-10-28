<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\Http\ApiError;

/**
 * Postanschrift eines Kontos prüfen und einheitlich schreiben. Die App prüft vorher genauso (packages/frontend/src/
 * address.ts), dieselben Länder wie bei der Ländervorwahl (phone.ts). Land als ISO 3166-1 alpha-2; '' heißt anderes
 * Land, dann mit country_name und lockerer Prüfung der Postleitzahl.
 *
 * Formate der Postleitzahlen nach der Übersicht in der Wikipedia (List of postal codes, Weltpostverein): Deutschland
 * fünfstellig, die Leitregion 00 gibt es nicht; Spanien 01–52 (Provinzen), Türkei 01–81 (Provinzen), Tschechien 1–7 an
 * erster Stelle.
 *
 * @phpstan-type AddressData array{street: string, house_number: string, extra: string, postal_code: string, city: string, country: string, country_name: string}
 */
final class Address
{
    public const STREET_MAX = 100;
    public const HOUSE_NUMBER_MAX = 10;
    public const CITY_MAX = 60;
    public const COUNTRY_NAME_MAX = 60;
    /** Voreingestellt, wenn kein Land mitkommt. */
    public const DEFAULT_COUNTRY = 'DE';
    /** Anderes Land (nicht in der Auswahl) */
    public const OTHER = '';

    /** ISO-Code => Name, Muster der Postleitzahl (nach dem Vereinheitlichen), Beispiel */
    public const COUNTRIES = [
        'DE' => ['Deutschland', '/^(0[1-9]|[1-9]\d)\d{3}$/', '12345'],
        'AT' => ['Österreich', '/^[1-9]\d{3}$/', '1010'],
        'CH' => ['Schweiz', '/^[1-9]\d{3}$/', '8001'],
        'NL' => ['Niederlande', '/^[1-9]\d{3} [A-Z]{2}$/', '1234 AB'],
        'BE' => ['Belgien', '/^[1-9]\d{3}$/', '1000'],
        'LU' => ['Luxemburg', '/^\d{4}$/', '1234'],
        'FR' => ['Frankreich', '/^\d{5}$/', '75001'],
        'DK' => ['Dänemark', '/^\d{4}$/', '1050'],
        'PL' => ['Polen', '/^\d{2}-\d{3}$/', '00-950'],
        'CZ' => ['Tschechien', '/^[1-7]\d{2} \d{2}$/', '110 00'],
        'IT' => ['Italien', '/^\d{5}$/', '00118'],
        'ES' => ['Spanien', '/^(0[1-9]|[1-4]\d|5[0-2])\d{3}$/', '28001'],
        'GB' => ['Großbritannien', '/^[A-Z]{1,2}\d[A-Z\d]? \d[A-Z]{2}$/', 'SW1A 1AA'],
        'TR' => ['Türkei', '/^(0[1-9]|[1-7]\d|8[01])\d{3}$/', '06100'],
    ];

    /** Hausnummer: Ziffern, dahinter ein oder zwei Buchstaben, auch als Bereich (12-14, 3/1) */
    private const HOUSE_NUMBER = '\d{1,5} ?[A-Za-z]{0,2}(?: ?[-\/] ?\d{1,5} ?[A-Za-z]?)?';

    /**
     * Eingabe prüfen und vereinheitlichen; alles leer heißt keine Adresse (null).
     *
     * @return AddressData|null
     */
    public static function parse(mixed $raw): ?array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }
        if (!is_array($raw)) {
            throw ApiError::invalid('Ungültige Adresse.');
        }
        $get = static fn (string $key): string => Text::line(is_string($raw[$key] ?? null) ? $raw[$key] : '');
        $country = array_key_exists('country', $raw) ? strtoupper($get('country')) : self::DEFAULT_COUNTRY;
        [$street, $number] = self::splitStreet($get('street'), self::houseNumber($get('house_number')));
        $a = [
            'street' => $street,
            'house_number' => $number,
            'extra' => $get('extra'),
            'postal_code' => self::postalCode($get('postal_code'), $country),
            'city' => $get('city'),
            'country' => $country,
            'country_name' => $country === self::OTHER ? $get('country_name') : '',
        ];
        if ($a['street'] === '' && $a['house_number'] === '' && $a['extra'] === '' && $a['postal_code'] === '' && $a['city'] === '') {
            return null;
        }
        if ($country !== self::OTHER && !isset(self::COUNTRIES[$country])) {
            throw ApiError::invalid('Unbekanntes Land in der Adresse.');
        }
        self::check($a);
        return $a;
    }

    /** Name des Landes: aus der Auswahl, sonst wie eingetragen. @param AddressData $a */
    public static function countryName(array $a): string
    {
        return self::COUNTRIES[$a['country']][0] ?? $a['country_name'];
    }

    /** Straße mit Hausnummer, z. B. „Musterstraße 1“. @param AddressData $a */
    public static function streetLine(array $a): string
    {
        return trim($a['street'] . ' ' . $a['house_number']);
    }

    /**
     * Die ganze Adresse in einer Zeile wie in address.ts (formatAddress): „Musterstraße 1, c/o …, 12345 Musterstadt“,
     * das Land nur außerhalb Deutschlands.
     *
     * @param AddressData $a
     */
    public static function oneLine(array $a): string
    {
        $parts = [self::streetLine($a), $a['extra'], trim($a['postal_code'] . ' ' . $a['city'])];
        if ($a['country'] !== self::DEFAULT_COUNTRY) {
            $parts[] = self::countryName($a);
        }
        return implode(', ', array_filter($parts, static fn (string $p): bool => $p !== ''));
    }

    /** @param AddressData $a */
    private static function check(array $a): void
    {
        $text = '/^[\p{L}\p{M}0-9 .,\-\'’\/()&]+$/u';
        if ($a['street'] === '') {
            throw ApiError::invalid('Bitte die Straße angeben.');
        }
        if (mb_strlen($a['street']) > self::STREET_MAX || preg_match($text, $a['street']) !== 1 || preg_match('/\p{L}/u', $a['street']) !== 1) {
            throw ApiError::invalid('Die Straße ist ungültig. Erlaubt sind Buchstaben, Ziffern, Leerzeichen und . , - \' / ( ) &.');
        }
        if ($a['house_number'] !== '' && (mb_strlen($a['house_number']) > self::HOUSE_NUMBER_MAX || preg_match('/^' . self::HOUSE_NUMBER . '$/', $a['house_number']) !== 1)) {
            throw ApiError::invalid('Die Hausnummer ist ungültig, z. B. 12, 12a oder 12-14.');
        }
        if ($a['extra'] !== '' && (mb_strlen($a['extra']) > self::STREET_MAX || preg_match($text, $a['extra']) !== 1)) {
            throw ApiError::invalid('Der Adresszusatz ist ungültig. Erlaubt sind Buchstaben, Ziffern, Leerzeichen und . , - \' / ( ) &.');
        }
        if ($a['postal_code'] === '') {
            throw ApiError::invalid('Bitte die Postleitzahl angeben.');
        }
        $c = self::COUNTRIES[$a['country']] ?? null;
        if ($c !== null && preg_match($c[1], $a['postal_code']) !== 1) {
            throw ApiError::invalid("Die Postleitzahl passt nicht zu {$c[0]}, z. B. {$c[2]}.");
        }
        if ($c === null && preg_match('/^[A-Z0-9][A-Z0-9 -]{0,10}[A-Z0-9]$/', $a['postal_code']) !== 1) {
            throw ApiError::invalid('Die Postleitzahl ist ungültig (2 bis 12 Zeichen aus Buchstaben, Ziffern, Leerzeichen und -).');
        }
        // Ziffern im Ort sind erlaubt (z. B. „Paris Cedex 08“), aber es braucht Buchstaben.
        if ($a['city'] === '') {
            throw ApiError::invalid('Bitte den Ort angeben.');
        }
        if (mb_strlen($a['city']) > self::CITY_MAX || preg_match('/^[\p{L}\p{M}0-9 .\-\'’()\/]+$/u', $a['city']) !== 1 || preg_match('/\p{L}.*\p{L}/u', $a['city']) !== 1) {
            throw ApiError::invalid('Der Ort ist ungültig. Erlaubt sind Buchstaben, Leerzeichen und . - \' ( ) /.');
        }
        if ($a['country'] === self::OTHER && (mb_strlen($a['country_name']) < 2 || mb_strlen($a['country_name']) > self::COUNTRY_NAME_MAX || preg_match('/^[\p{L}\p{M} .\-\'’()]+$/u', $a['country_name']) !== 1)) {
            throw ApiError::invalid('Bitte das Land angeben.');
        }
    }

    /** Hausnummer ohne doppelte Leerzeichen um - und / */
    private static function houseNumber(string $s): string
    {
        return (string) preg_replace('# ?([-/]) ?#', '$1', $s);
    }

    /**
     * Steht die Hausnummer mit in der Straße („Hauptstraße 5a“) und ist das Feld dafür leer, kommt sie in ihr Feld.
     *
     * @return array{0: string, 1: string}
     */
    private static function splitStreet(string $street, string $number): array
    {
        if ($number === '' && preg_match('#^(.*\p{L}.*?)\s+(' . self::HOUSE_NUMBER . ')$#u', $street, $m) === 1) {
            return [$m[1], self::houseNumber($m[2])];
        }
        return [$street, $number];
    }

    /**
     * Postleitzahl einheitlich: groß, Länderkennung davor weg (D-12345, L-1234, CH-8001), Leerzeichen wie im Land üblich
     * (1234 AB, 110 00, SW1A 1AA), in Polen mit Bindestrich (00-950).
     */
    public static function postalCode(string $s, string $country): string
    {
        $s = strtoupper(trim($s));
        if ($country === self::OTHER) {
            return (string) preg_replace('/\s+/', ' ', $s);
        }
        $s = (string) preg_replace('/^[A-Z]{1,3} ?- ?(?=\d)/', '', $s);
        $s = (string) preg_replace('/\s+/', '', $s);
        return match ($country) {
            'NL' => preg_match('/^\d{4}[A-Z]{2}$/', $s) === 1 ? substr($s, 0, 4) . ' ' . substr($s, 4) : $s,
            'CZ' => preg_match('/^\d{5}$/', $s) === 1 ? substr($s, 0, 3) . ' ' . substr($s, 3) : $s,
            'PL' => preg_match('/^\d{5}$/', $s) === 1 ? substr($s, 0, 2) . '-' . substr($s, 2) : $s,
            'GB' => strlen($s) >= 5 ? substr($s, 0, -3) . ' ' . substr($s, -3) : $s,
            default => $s,
        };
    }
}
