<?php

declare(strict_types=1);

namespace KompanieApp\Core\Port;

use KompanieApp\Core\Auth\User;

/**
 * Anmeldung und Schutz vor Anfragen fremder Seiten (CSRF).
 * WordPress: Anmelde-Cookie und REST-Nonce. Standalone: eigene Sitzung.
 */
interface Authenticator
{
    /** 'form' (Anmeldeformular in der App) oder 'redirect' (Weiterleitung, z. B. wp-login.php). */
    public function mode(): string;

    public function loginUrl(): ?string;

    public function logoutUrl(): ?string;

    /** Name des Headers, in dem die App das CSRF-Token schickt. */
    public function csrfHeader(): string;

    /** Leer für Gäste ohne Anmeldung. */
    public function csrfToken(): string;

    /**
     * Kennung der aktuellen Anmeldung (Sitzung), die sich während der Sitzung nicht ändert; leer für Gäste.
     * Bindet z. B. die Anmeldung bei Google („Mit Google verbinden“) an die Sitzung, in der sie begonnen wurde.
     * Nur zum Vergleichen: Sie darf kein Geheimnis preisgeben (z. B. ein Hash des Sitzungsschlüssels).
     */
    public function sessionKey(): string;

    /**
     * Adresse, unter der die App ein neues CSRF-Token holt (GET, Antwort {token}).
     * Sie muss allein mit dem Sitzungs-Cookie funktionieren, auch wenn das alte Token abgelaufen ist.
     */
    public function csrfRefreshUrl(): string;

    /**
     * Meldet an und setzt die Sitzung.
     *
     * @throws \KompanieApp\Core\Http\ApiError bei falschen Daten oder zu vielen Versuchen
     */
    public function login(string $username, string $password, bool $remember): User;

    public function logout(): void;

    /** Nach einer Passwortänderung: andere Sitzungen beenden, diese Sitzung erneuern. */
    public function afterPasswordChange(int $userId): void;
}
