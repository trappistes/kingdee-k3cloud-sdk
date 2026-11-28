<?php

declare(strict_types = 1);

namespace Trappistes\Kingdee\K3Cloud\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Trappistes\Kingdee\K3Cloud\Http\CurlTransport;

/**
 * 集成测试：cURL 传输层的会话保持
 *
 * 会话 Cookie 由 cURL 内置 Cookie 引擎管理，这是「会话失效自动重登」得以成立的
 * 前提，内存假传输无法覆盖，故以本机回环地址做一次真实往返。
 */
final class CurlSessionTest extends TestCase
{
    public function test_session_cookie_persists_across_requests(): void
    {
        $url = $this->startServer();

        if ($url === null) {
            $this->markTestSkipped('无法启动本地测试服务器');
        }

        $transport = new CurlTransport(5, 2);

        $first = json_decode($transport->post($url, [], [])->body, true);
        $second = json_decode($transport->post($url, [], [])->body, true);

        // 首次请求无 Cookie，服务器下发会话；后续请求必须自动携带
        $this->assertSame('(none)', $first['seen']);
        $this->assertSame('SESSION-1', $second['seen']);
    }

    /**
     * 启动本机 PHP 内置服务器，返回其地址；失败时返回 null
     */
    private function startServer(): ?string
    {
        $router = tempnam(sys_get_temp_dir(), 'k3srv');

        if ($router === false) {
            return null;
        }

        file_put_contents($router, '<?php
$seen = isset($_COOKIE["ksid"]) ? $_COOKIE["ksid"] : "(none)";
if (!isset($_COOKIE["ksid"])) { setcookie("ksid", "SESSION-1", ["path" => "/"]); }
header("Content-Type: application/json");
echo json_encode(["seen" => $seen]);
');

        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if (!is_resource($socket)) {
            @unlink($router);

            return null;
        }

        $port = (int) explode(':', stream_socket_get_name($socket, false))[1];
        fclose($socket);

        $process = @proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:'.$port, $router],
            [1 => ['file', $router.'.log', 'w'], 2 => ['file', $router.'.log', 'w']],
            $pipes
        );

        if (!is_resource($process)) {
            @unlink($router);

            return null;
        }

        // 等待端口就绪
        for ($i = 0; $i < 50; $i++) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);

            if (is_resource($conn)) {
                fclose($conn);

                register_shutdown_function(static function () use ($process, $router): void {
                    proc_terminate($process);
                    proc_close($process);
                    @unlink($router);
                    @unlink($router.'.log');
                });

                return 'http://127.0.0.1:'.$port.'/';
            }

            usleep(100000);
        }

        proc_terminate($process);
        proc_close($process);
        @unlink($router);

        return null;
    }
}
