<?php

declare(strict_types=1);

namespace KompanieApp\Core\Port;

/** Adressen und Eckdaten der Installation. */
interface Environment
{
    /** 'wordpress' oder 'standalone' */
    public function variant(): string;

    /** Version der Installation, z. B. 1.4.0.3 */
    public function version(): string;

    /** Basisadresse der API mit abschließendem /, z. B. https://example.org/wp-json/kompanie-app/v1/ */
    public function apiBase(): string;

    /** Adresse der App mit abschließendem / */
    public function appUrl(): string;

    /**
     * Adresse des Service Workers. Sein Bereich ist appUrl(); er muss deshalb unter dieser
     * Adresse liegen oder mit dem Header Service-Worker-Allowed ausgeliefert werden.
     */
    public function serviceWorkerUrl(): string;

    /** Startseite der Webseite, falls es eine gibt. */
    public function siteUrl(): ?string;

    /** Adresse, über die ein geschütztes oder noch nicht erzeugtes Galeriebild ausgeliefert wird. */
    public function photoUrl(int $id, string $size): string;

    /**
     * Adresse, über die ein Dokument nach Prüfung der Anmeldung ausgeliefert wird (Issue #2). Liegt im Bereich des
     * Service Workers; die Fassung (?v=…) gehört dazu, damit eine neue Fassung eine neue Adresse hat.
     */
    public function documentUrl(int $id, string $version): string;
}
