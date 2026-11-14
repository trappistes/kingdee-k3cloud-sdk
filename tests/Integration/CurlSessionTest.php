<?php

declare(strict_types=1);

namespace Trappistes\Kingdee\K3Cloud\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Trappistes\Kingdee\K3Cloud\Http\CurlTransport;

/**
 * 集成测试：cURL 传输层的会话行为
 *
 * 会话 Cookie 完全由 cURL 内置 Cookie 引擎管理（内存模式），
 * 这是「会话失效自动重登」得以成立的前提，因此需要真实 HTTP 往返来验证，
 * 内存假传输无法覆盖。
 *
 * 使用本机回环地址与临时启动的 PHP 内置服务器，不访问外网。
 */
final class CurlSessionTest extends TestCase
{
    /**
     * @var resource|null
     */
    private $serverProcess;

    /**
     * @var int
     */
    private $serverPort = 0;

    /**
     * @var string|null
     */
    private $routerFile;

    protected function tearDown(): void
    {
        if (is_resource($this->serverProcess)) {
            proc_terminate($this->serverProcess);
            proc_close($this->serverProcess);
        }
        $this->serverProcess = null;

        // 内置服务器每次请求都会读取路由脚本，故必须在进程结束后才能删除
        if ($this->routerFile !== null) {
            @unlink($this->routerFile);
            $this->routerFile = null;
        }
    }

    /**
     * 会话 Cookie 必须在同一句柄的多次请求间保持（登录态的前提）
     */
    public function test_session_cookie_persists_across_requests(): void
    {
        $url = $this->startServer();

        if ($url === null) {
            $this->markTestSkipped('无法启动本地测试服务器（可能缺少可执行文件或端口受限）');
        }

        $transport = new CurlTransport(5, 2);

        $first = $transport->post($url, [], []);
        $second = $transport->post($url, [], []);

        $firstData = json_decode($first->body, true);
        $secondData = json_decode($second->body, true);

        // 首次请求无 Cookie，服务器下发会话
        $this->assertSame('(none)', $firstData['seen']);

        // 后续请求必须自动带上会话 Cookie —— 登录态的前提
        $this->assertSame('SESSION-1', $secondData['seen']);
    }

    /**
     * Cookie 引擎必须处于内存模式：不得在磁盘留下 cookie 文件
     */
    public function test_cookie_engine_does_not_write_files_to_disk(): void
    {
        $url = $this->startServer();

        if ($url === null) {
            $this->markTestSkipped('无法启动本地测试服务器（可能缺少可执行文件或端口受限）');
        }

        $before = $this->cookieFilesIn(getcwd());
        $transport = new CurlTransport(5, 2);
        $transport->post($url, [], []);
        $transport->post($url, [], []);

        $this->assertSame($before, $this->cookieFilesIn(getcwd()));
    }

    /**
     * 启动本地 PHP 内置服务器，返回其根地址；失败时返回 null
     */
    private function startServer(): ?string
    {
        if ($this->serverPort !== 0) {
            return 'http://127.0.0.1:'.$this->serverPort.'/';
        }

        $router = tempnam(sys_get_temp_dir(), 'k3srv');
        if ($router === false) {
            return null;
        }

        file_put_contents($router, <<<'PHP'
<?php
$seen = isset($_COOKIE['ksid']) ? $_COOKIE['ksid'] : '(none)';
if (!isset($_COOKIE['ksid'])) {
    setcookie('ksid', 'SESSION-1', ['path' => '/']);
}
header('Content-Type: application/json');
echo json_encode(['seen' => $seen]);
PHP);

        $port = $this->findFreePort();
        if ($port === null) {
            @unlink($router);

            return null;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];
        $process = @proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:'.$port, $router],
            $descriptors,
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );

        if (!is_resource($process)) {
            @unlink($router);

            return null;
        }

        $this->serverProcess = $process;
        $this->serverPort = $port;
        $this->routerFile = $router;

        // 等待服务器就绪
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if (is_resource($socket)) {
                fclose($socket);

                return 'http://127.0.0.1:'.$port.'/';
            }
            usleep(100000);
        }

        return null;
    }

    /**
     * 找一个空闲端口
     */
    private function findFreePort(): ?int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!is_resource($socket)) {
            return null;
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        if ($name === false) {
            return null;
        }

        $parts = explode(':', $name);

        return (int) end($parts);
    }

    /**
     * 列出当前目录下的 cookie 文件（用于断言未落盘）
     *
     * @return array<int, string>
     */
    private function cookieFilesIn(string $dir): array
    {
        $found = glob($dir.'/cookie*.txt') ?: [];
        sort($found);

        return $found;
    }
}
