<?php

declare(strict_types=1);

namespace KompanieApp\Core\Port;

/**
 * Quelle der News, deren Beiträge sich in einem Editor der Umgebung schreiben lassen: im WordPress-Plugin die
 * Beiträge der eigenen Seite im Editor von WordPress (wp-admin). Die App öffnet nur die Adressen; wer schreiben darf,
 * entscheidet der Kern (NewsService::canEditSite, Recht manage_news), die Umgebung setzt das in eigene Rechte um.
 */
interface NewsEditor
{
    /** Adresse zum Schreiben eines neuen Beitrags. */
    public function newUrl(): string;

    /** Adresse zum Bearbeiten des Beitrags $id; null, wenn es ihn nicht gibt. */
    public function editUrl(string $id): ?string;
}
