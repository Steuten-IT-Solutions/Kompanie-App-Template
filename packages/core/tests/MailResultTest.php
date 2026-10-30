<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\Mail\MailResult;
use PHPUnit\Framework\TestCase;

/** Fehlertexte des Mailservers fürs Protokoll: ohne E-Mail-Adressen. */
final class MailResultTest extends TestCase
{
    public function testRedactsAddresses(): void
    {
        $this->assertSame(
            '550 5.1.1 <[Adresse]>: User unknown (an [Adresse])',
            MailResult::redactAddresses('550 5.1.1 <max.muster@example.org>: User unknown (an a-b+c@sub.example.de)'),
        );
        $this->assertSame('Verbindung abgelehnt', MailResult::redactAddresses('Verbindung abgelehnt'));
        $this->assertSame('550 <[Adresse]>: User unknown', MailResult::redactAddresses('550 <"max muster"@example.org>: User unknown'), 'lokaler Teil in Anführungszeichen');
    }
}
