<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

/** Bereinigt Eingaben, ähnlich sanitize_text_field / sanitize_textarea_field in WordPress. */
final class Text
{
    /** Einzeilig: ohne HTML, ohne Steuerzeichen, Leerraum zusammengefasst. */
    public static function line(mixed $value, int $maxLength = 0): string
    {
        $s = self::clean($value);
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        return $maxLength > 0 ? mb_substr($s, 0, $maxLength) : $s;
    }

    /** Mehrzeilig: ohne HTML, Zeilenumbrüche bleiben erhalten. */
    public static function multiline(mixed $value, int $maxLength = 0): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", self::clean($value));
        $s = (string) preg_replace('/[ \t]+/u', ' ', $s);
        $s = trim(implode("\n", array_map('trim', explode("\n", $s))));
        return $maxLength > 0 ? mb_substr($s, 0, $maxLength) : $s;
    }

    /** HTML zu reinem Text (z. B. Beschreibungen aus Google Kalender). */
    public static function fromHtml(string $html): string
    {
        $text = (string) preg_replace('#<br\s*/?>|</p>|</li>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $text)));
    }

    private static function clean(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $s = (string) $value;
        if (!mb_check_encoding($s, 'UTF-8')) {
            return '';
        }
        // Steuerzeichen außer Zeilenumbruch und Tabulator zuerst entfernen: sonst würde aus
        // „<\x01script>“ nach dem Entfernen der Tags wieder „<script>“.
        $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
        // Nur echte Tags und Kommentare entfernen; ein einzelnes „<“ (z. B. „<10 min“) bleibt.
        // Wiederholen, bis nichts mehr passt: sonst wird aus „<<b>script>“ wieder „<script>“.
        // Höchstens 10 Durchläufe (gegen absichtlich tief verschachtelte Eingaben); bleibt es
        // danach unruhig, werden alle „<“ entfernt.
        $stable = false;
        for ($i = 0; $i < 10 && !$stable; $i++) {
            $before = $s;
            $s = (string) preg_replace(['/<!--.*?-->/su', '#</?[a-zA-Z!/][^>]*>#u'], '', $s);
            $stable = $s === $before;
        }
        return $stable ? $s : str_replace('<', '', $s);
    }
}
