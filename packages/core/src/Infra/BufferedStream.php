<?php

declare(strict_types=1);

namespace KompanieApp\Core\Infra;

/** StreamingHttpClient::stream für eine schon ganz geladene Antwort (wenn nicht gestreamt werden kann). */
final class BufferedStream
{
    /**
     * @param array{status: int, headers: array<string, string>, body: string} $res
     * @param \Closure(int, array<string, string>): bool $onHead
     * @param \Closure(string): bool $onData
     */
    public static function emit(array $res, \Closure $onHead, \Closure $onData): bool
    {
        if (!$onHead($res['status'], $res['headers'])) {
            return false;
        }
        return $res['body'] === '' || $onData($res['body']);
    }
}
