<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

/**
 * Verschlüsselt eine Sicherung beim Schreiben (Format: BackupCrypto). Als Filter an einem Ausgabestrom (attach/finish)
 * lässt sich die Sicherung direkt verschlüsselt schreiben: Der Klartext liegt nie als Datei vor und nie ganz im
 * Arbeitsspeicher (immer nur ein Block von BackupCrypto::CHUNK Bytes).
 */
final class BackupEncryptor
{
    private const FILTER = 'kompanie.backup-encrypt';

    private string $buffer = '';
    private string $ad;
    private bool $finished = false;
    private bool $aborted = false;
    /** Fehler im Filter (dort darf keine Ausnahme hinaus); finish() wirft ihn. */
    public ?\Throwable $error = null;
    /** Geschriebene Bytes (Kopf und Blöcke), um eine Datei auf Vollständigkeit zu prüfen */
    public int $bytes = 0;
    /** @var resource|null */
    private $filter = null;

    private function __construct(private string $state, private readonly string $head, string $header)
    {
        $this->ad = $header;
    }

    /**
     * @param array{public_key: string, salt?: string, ops?: int, mem?: int} $key Einstellung BackupCrypto::SETTING
     * @param array<string, scalar> $meta
     * @throws \RuntimeException
     */
    public static function start(array $key, array $meta = []): self
    {
        $public = base64_decode((string) ($key['public_key'] ?? ''), true);
        if ($public === false || strlen($public) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new \RuntimeException('Für Sicherungen ist kein gültiges Passwort eingerichtet.');
        }
        $fileKey = sodium_crypto_secretstream_xchacha20poly1305_keygen();
        try {
            [$state, $streamHeader] = sodium_crypto_secretstream_xchacha20poly1305_init_push($fileKey);
            $header = json_encode([
                'format' => Backup::FORMAT,
                'v' => BackupCrypto::FORMAT_VERSION,
                'kdf' => ['alg' => 'argon2id13', 'salt' => $key['salt'] ?? '', 'ops' => (int) ($key['ops'] ?? 0), 'mem' => (int) ($key['mem'] ?? 0)],
                'sealed_key' => base64_encode(sodium_crypto_box_seal($fileKey, $public)),
                'stream' => base64_encode($streamHeader),
                'chunk' => BackupCrypto::CHUNK,
                'fingerprint' => BackupCrypto::fingerprint((string) $key['public_key']),
            ] + $meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException | \SodiumException $e) {
            // Als RuntimeException wie alle Fehler beim Sichern (Aufrufer fangen nur diese, z. B. vor einem Update).
            throw new \RuntimeException('Die Sicherung ließ sich nicht verschlüsseln: ' . $e->getMessage(), 0, $e);
        } finally {
            sodium_memzero($fileKey);
        }
        return new self($state, BackupCrypto::MAGIC . pack('N', strlen($header)) . $header, $header);
    }

    /** Magic, Länge und Kopf: zuerst schreiben. */
    public function head(): string
    {
        return $this->head;
    }

    /**
     * Klartext aufnehmen; $emit bekommt jeden fertigen Block (je CHUNK Bytes Klartext) einzeln. Große Stücke werden
     * über einen Versatz abgearbeitet, ohne den Rest bei jedem Block neu zu kopieren.
     *
     * @param \Closure(string): void $emit
     */
    public function update(string $plain, \Closure $emit): void
    {
        if ($this->buffer !== '') {
            $need = BackupCrypto::CHUNK - strlen($this->buffer);
            if (strlen($plain) < $need) {
                $this->buffer .= $plain;
                return;
            }
            $emit($this->push($this->buffer . substr($plain, 0, $need), SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE));
            $this->buffer = '';
            $offset = $need;
        } else {
            $offset = 0;
        }
        $len = strlen($plain);
        for (; $len - $offset >= BackupCrypto::CHUNK; $offset += BackupCrypto::CHUNK) {
            $emit($this->push(substr($plain, $offset, BackupCrypto::CHUNK), SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE));
        }
        $this->buffer = (string) substr($plain, $offset);
    }

    /**
     * Abbrechen: kein Abschluss mehr. Eine abgebrochene Sicherung darf nie wie eine vollständige enden (sonst ließe sie
     * sich fehlerfrei, aber gekürzt entschlüsseln); ohne TAG_FINAL erkennt BackupCrypto::decrypt sie als abgeschnitten.
     */
    public function abort(): void
    {
        $this->aborted = true;
        if ($this->filter !== null) {
            @stream_filter_remove($this->filter);
            $this->filter = null;
        }
    }

    /** Rest und Abschluss (immer ein eigener, leerer Block mit TAG_FINAL). */
    public function final(): string
    {
        if ($this->finished || $this->aborted) {
            return '';
        }
        $out = $this->buffer !== '' ? $this->push($this->buffer, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE) : '';
        $this->buffer = '';
        $out .= $this->push('', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
        $this->finished = true;
        sodium_memzero($this->state);
        return $out;
    }

    private function push(string $plain, int $tag): string
    {
        $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($this->state, $plain, $this->ad, $tag);
        $this->ad = '';
        return pack('N', strlen($cipher)) . $cipher;
    }

    /**
     * Kopf nach $out schreiben und alles, was danach in $out geschrieben wird, verschlüsseln. Abschließen mit finish().
     *
     * @param resource $out
     */
    public function attach($out): void
    {
        if (!in_array(self::FILTER, stream_get_filters(), true)) {
            stream_filter_register(self::FILTER, BackupEncryptFilter::class);
        }
        if (fwrite($out, $this->head) !== strlen($this->head)) {
            throw new \RuntimeException('Die verschlüsselte Sicherung ließ sich nicht schreiben (Speicherplatz?).');
        }
        $this->bytes = strlen($this->head);
        $filter = stream_filter_append($out, self::FILTER, STREAM_FILTER_WRITE, $this);
        if ($filter === false) {
            throw new \RuntimeException('Die Sicherung ließ sich nicht verschlüsseln (Filter).');
        }
        $this->filter = $filter;
    }

    /** Abschluss schreiben und den Filter entfernen. @throws \RuntimeException bei einem Fehler im Filter */
    public function finish(): void
    {
        if ($this->filter !== null) {
            stream_filter_remove($this->filter); // ruft den Filter mit $closing auf
            $this->filter = null;
        }
        if ($this->error !== null) {
            throw new \RuntimeException('Die Sicherung ließ sich nicht verschlüsseln: ' . $this->error->getMessage(), 0, $this->error);
        }
        if (!$this->finished) {
            throw new \RuntimeException('Die Sicherung ließ sich nicht verschlüsseln (Abschluss fehlt).');
        }
    }
}
