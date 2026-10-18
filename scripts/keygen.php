<?php
/**
 * Erzeugt ein Ed25519-Schlüsselpaar zum Signieren der Pakete:
 *   php scripts/keygen.php
 * Den geheimen Schlüssel als Secret KOMPANIE_SIGNING_KEY der Environment „veroeffentlichen“ im Kern-Repo
 * speichern (nie ins Repo),
 * den öffentlichen in packages/core/src/Update/TrustedKeys.php eintragen. Siehe docs/RELEASE.md.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/packages/core/src/Update/SignedEnvelope.php';

$pair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($pair);
$public = sodium_crypto_sign_publickey($pair);
$id = KompanieApp\Core\Update\SignedEnvelope::keyId($public);
echo "Geheimer Schlüssel (Secret KOMPANIE_SIGNING_KEY, nirgends sonst speichern):\n" . base64_encode($secret) . "\n\n";
echo "Eintrag für TrustedKeys::KEYS:\n        '$id' => '" . base64_encode($public) . "',\n";
