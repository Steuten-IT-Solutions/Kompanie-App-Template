<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

/** Schreibfilter für BackupEncryptor::attach() (params: der BackupEncryptor). */
final class BackupEncryptFilter extends \php_user_filter
{
    /**
     * @param resource $in
     * @param resource $out
     * @param int $consumed
     */
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        $enc = $this->params;
        if (!$enc instanceof BackupEncryptor) {
            return PSFS_ERR_FATAL;
        }
        try {
            // Jeden Block als eigenes Stück weitergeben: im Speicher nie mehr als das eingehende Stück und ein Block.
            $emit = function (string $data) use ($enc, $out): void {
                $enc->bytes += strlen($data);
                stream_bucket_append($out, stream_bucket_new($this->stream, $data));
            };
            while ($bucket = stream_bucket_make_writeable($in)) {
                $consumed += $bucket->datalen;
                $enc->update($bucket->data, $emit);
            }
            if ($closing) {
                $final = $enc->final();
                if ($final !== '') {
                    $emit($final);
                }
            }
            return PSFS_PASS_ON;
        } catch (\Throwable $e) {
            $enc->error = $e;
            return PSFS_ERR_FATAL;
        }
    }
}
