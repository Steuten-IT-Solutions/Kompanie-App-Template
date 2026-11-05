<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\Infra\CurlHttpClient;
use PHPUnit\Framework\TestCase;

/** CurlHttpClient::stream gegen einen lokalen PHP-Server: Kopf zuerst, Daten in Stücken, Abbruch durch den Empfänger. */
final class CurlStreamTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static int $port = 0;
    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('curl_init')) {
            return;
        }
        self::$dir = sys_get_temp_dir() . '/kompanie-stream-' . bin2hex(random_bytes(4));
        mkdir(self::$dir);
        // 3 MB in Stücken von 64 KB, jedes sofort gesendet; Range wie bei Google (206 mit Content-Range).
        file_put_contents(self::$dir . '/router.php', <<<'PHP'
            <?php
            if ($_SERVER['REQUEST_URI'] === '/fehlt') {
                http_response_code(404);
                echo '{"error":"not found"}';
                return;
            }
            $total = 3 * 1024 * 1024;
            $start = 0;
            $end = $total - 1;
            if (preg_match('/^bytes=(\d+)-(\d*)$/', $_SERVER['HTTP_RANGE'] ?? '', $m)) {
                $start = (int) $m[1];
                $end = $m[2] === '' ? $end : min($end, (int) $m[2]);
                http_response_code(206);
                header('Content-Range: bytes ' . $start . '-' . $end . '/' . $total);
            }
            header('Content-Type: video/mp4');
            header('Content-Length: ' . ($end - $start + 1));
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            for ($pos = $start; $pos <= $end; $pos += 65536) {
                echo str_repeat(chr(65 + intdiv($pos, 65536) % 26), min(65536, $end - $pos + 1));
                flush();
            }
            PHP);
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        self::$port = (int) substr(strrchr((string) stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);
        self::$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . self::$port, self::$dir . '/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 50; $i++) {
            $c = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.1);
            if ($c !== false) {
                fclose($c);
                break;
            }
            usleep(100000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        if (self::$dir !== '') {
            exec('rm -rf ' . escapeshellarg(self::$dir));
        }
    }

    protected function setUp(): void
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('ohne cURL');
        }
    }

    private function url(string $path = '/video'): string
    {
        return 'http://127.0.0.1:' . self::$port . $path;
    }

    public function testHeadComesFirstThenDataInPieces(): void
    {
        $log = [];
        $complete = (new CurlHttpClient())->stream($this->url(), ['Range' => 'bytes=100-1048675'], static function (int $status, array $headers) use (&$log): bool {
            $log[] = [$status, $headers['content-range'] ?? ''];
            return true;
        }, static function (string $chunk) use (&$log): bool {
            $log[] = strlen($chunk);
            return true;
        });
        $this->assertTrue($complete);
        $this->assertSame([206, 'bytes 100-1048675/3145728'], $log[0], 'Kopf vor den Daten');
        $sizes = array_slice($log, 1);
        $this->assertSame(1048576, array_sum($sizes));
        $this->assertGreaterThan(1, count($sizes), 'in Stücken, nicht auf einmal');
    }

    public function testReceiverCanStopTheTransfer(): void
    {
        $received = 0;
        $complete = (new CurlHttpClient())->stream($this->url(), [], static fn (): bool => true, static function (string $chunk) use (&$received): bool {
            $received += strlen($chunk);
            return false; // der Browser hat aufgelegt
        });
        $this->assertFalse($complete);
        $this->assertGreaterThan(0, $received);
        $this->assertLessThan(3 * 1024 * 1024, $received, 'nicht bis zum Ende geladen');
    }

    public function testRejectedHeadPassesOnNoData(): void
    {
        $data = '';
        $status = 0;
        $complete = (new CurlHttpClient())->stream($this->url('/fehlt'), [], static function (int $code) use (&$status): bool {
            $status = $code;
            return false;
        }, static function (string $chunk) use (&$data): bool {
            $data .= $chunk;
            return true;
        });
        $this->assertSame([false, 404, ''], [$complete, $status, $data]);
    }

    public function testNetworkErrorBeforeTheHeadThrows(): void
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr((string) stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock); // niemand hört auf diesem Port
        $this->expectException(\RuntimeException::class);
        (new CurlHttpClient())->stream('http://127.0.0.1:' . $port . '/', [], static fn (): bool => true, static fn (): bool => true, 5);
    }
}
