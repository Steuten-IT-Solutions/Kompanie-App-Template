<?php

declare(strict_types=1);

namespace KompanieApp\Core\Port;

use KompanieApp\Core\Auth\User;

/** Wer stellt die aktuelle Anfrage? */
interface AuthContext
{
    /** null = Gast ohne Anmeldung. */
    public function currentUser(): ?User;
}
