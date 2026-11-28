<?php

declare(strict_types = 1);

namespace Trappistes\Kingdee\K3Cloud\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Trappistes\Kingdee\K3Cloud\Client;
use Trappistes\Kingdee\K3Cloud\Config;
use Trappistes\Kingdee\K3Cloud\Exception\AuthenticationException;
use Trappistes\Kingdee\K3Cloud\Exception\ConfigurationException;
use Trappistes\Kingdee\K3Cloud\Http\HttpResponse;
use Trappistes\Kingdee\K3Cloud\Query;
use Trappistes\Kingdee\K3Cloud\Tests\Support\FakeTransport;

/**
 * 身份变更测试
 *
 * 身份（凭据/账套/认证方式）变了 → 旧会话作废并用新凭据重新登录；
 * 传输行为参数（超时等）变了 → 立即生效，不触发重新认证。
 */
final class IdentityChangeTest extends TestCase
{
    private const CONFIG = [
        'auth_type' => 1,
        'host_url' => 'https://k3cloud.example.com/k3cloud/',
        'acct_id' => 'acct',
        'username' => 'user',
        'password' => 'secret',
        'max_attempts' => 3,
    ];

    private const LOGIN_OK = '{"LoginResultType":1,"Context":{"SessionId":"s"}}';

    public function test_changing_credentials_triggers_relogin_with_new_credentials(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, self::LOGIN_OK),
            new HttpResponse(200, '[{"FNumber":"A"}]'),
            new HttpResponse(200, self::LOGIN_OK),
            new HttpResponse(200, '[{"FNumber":"B"}]'),
        );

        $config = Config::fromArray(self::CONFIG);
        $client = new Client($config, $transport);

        $client->query(Query::make('BD_MATERIAL')->fields('FNumber'));

        // 换密码：旧会话属于旧身份，必须重新登录
        $config->password = 'new-secret';

        $client->query(Query::make('BD_MATERIAL')->fields('FNumber'));

        // 2 次登录 + 2 次查询
        self::assertSame(4, $transport->requestCount());
        self::assertTrue($transport->sessionResets() >= 1);

        // 第二次登录使用新密码
        $logins = array_values(array_filter(
            $transport->requests,
            static function (array $request): bool {
                return strpos($request['url'], 'AuthService') !== false;
            }
        ));

        self::assertCount(2, $logins);
        self::assertSame('secret', $logins[0]['payload']['password']);
        self::assertSame('new-secret', $logins[1]['payload']['password']);
    }

    public function test_changing_account_triggers_relogin(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, self::LOGIN_OK),
            new HttpResponse(200, '[{"FNumber":"A"}]'),
            new HttpResponse(200, self::LOGIN_OK),
            new HttpResponse(200, '[{"FNumber":"B"}]'),
        );

        $config = Config::fromArray(self::CONFIG);
        $client = new Client($config, $transport);

        $client->query(Query::make('BD_MATERIAL')->fields('FNumber'));

        // 换账套：旧会话仍然有效，但已不是目标账套
        $config->acctId = 'other-acct';

        $client->query(Query::make('BD_MATERIAL')->fields('FNumber'));

        self::assertSame(4, $transport->requestCount());

        $logins = array_values(array_filter(
            $transport->requests,
            static function (array $request): bool {
                return strpos($request['url'], 'AuthService') !== false;
            }
        ));

        self::assertSame('acct', $logins[0]['payload']['acctid']);
        self::assertSame('other-acct', $logins[1]['payload']['acctid']);
    }

    public function test_changing_transport_behaviour_does_not_trigger_relogin(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, self::LOGIN_OK),
            new HttpResponse(200, '[{"FNumber":"A"}]'),
            new HttpResponse(200, '[{"FNumber":"B"}]'),
        );

        $config = Config::fromArray(self::CONFIG);
        $client = new Client($config, $transport);

        $client->query(Query::make('BD_MATERIAL')->fields('FNumber'));

        // 超时/重试次数属于传输行为，改了立即生效，不需要重新认证
        $config->timeout = 60;
        $config->maxAttempts = 5;

        $client->query(Query::make('BD_MATERIAL')->fields('FNumber'));

        // 1 次登录 + 2 次查询
        self::assertSame(3, $transport->requestCount());
        self::assertSame(0, $transport->sessionResets());
    }

    public function test_config_changed_before_first_request_logs_in_once_with_new_credentials(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, self::LOGIN_OK),
            new HttpResponse(200, '[{"FNumber":"A"}]'),
        );

        $config = Config::fromArray(self::CONFIG);
        $client = new Client($config, $transport);

        // 首次请求前就改了凭据：应直接用新凭据登录一次，不做多余的重新登录
        $config->password = 'new-secret';

        $client->query(Query::make('BD_MATERIAL')->fields('FNumber'));

        self::assertSame(2, $transport->requestCount());
        self::assertSame('new-secret', $transport->requests[0]['payload']['password']);
    }

    public function test_switching_auth_type_reauthenticates_with_new_scheme(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, self::LOGIN_OK),
            new HttpResponse(200, '[{"FNumber":"A"}]'),
            new HttpResponse(200, self::LOGIN_OK),
            new HttpResponse(200, '[{"FNumber":"B"}]'),
        );

        $config = Config::fromArray(self::CONFIG + ['appid' => 'app-id', 'appsecret' => 'app-secret']);
        $client = new Client($config, $transport);

        $client->query(Query::make('BD_MATERIAL')->fields('FNumber'));

        // 认证方式从密码改为应用密钥：旧会话作废，按新方式重新登录
        $config->authType = Config::AUTH_APP_SECRET;

        $client->query(Query::make('BD_MATERIAL')->fields('FNumber'));

        self::assertSame(4, $transport->requestCount());
        self::assertStringContainsString('LoginByAppSecret', $transport->requests[2]['url']);
        self::assertSame('app-secret', $transport->requests[2]['payload']['appsecret']);
    }

    public function test_signature_mode_rebuilds_signer_after_identity_change(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '[{"FNumber":"A"}]'),
            new HttpResponse(200, '[{"FNumber":"B"}]'),
        );

        $config = Config::fromArray([
            'auth_type' => 3,
            'host_url' => 'https://k3cloud.example.com/k3cloud/',
            'acct_id' => 'acct',
            'username' => 'user',
            'appid' => 'client_secret',
            'appsecret' => 'secret',
            'org_num' => 5,
        ]);

        $client = new Client($config, $transport);

        $client->query(Query::make('BD_MATERIAL')->fields('FNumber'));

        $firstSignature = $transport->requests[0]['headers']['X-Kd-Signature'];

        // 换密钥：签名必须跟着变（否则会用旧密钥签名）
        $config->appSecret = 'new-secret';

        $client->query(Query::make('BD_MATERIAL')->fields('FNumber'));

        $secondSignature = $transport->requests[1]['headers']['X-Kd-Signature'];

        self::assertNotSame('', $firstSignature);
        self::assertNotSame($firstSignature, $secondSignature);
        // 签名认证不建立会话，但旧 Cookie 也要丢弃
        self::assertSame(1, $transport->sessionResets());
    }

    public function test_identity_change_with_wrong_credentials_fails_before_business_request(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, self::LOGIN_OK),
            new HttpResponse(200, '[{"FNumber":"A"}]'),
            new HttpResponse(200, '{"LoginResultType":0,"Message":"密码错误"}'),
        );

        $config = Config::fromArray(self::CONFIG);
        $client = new Client($config, $transport);

        $client->query(Query::make('BD_MATERIAL')->fields('FNumber'));

        $config->password = 'wrong-secret';

        try {
            $client->query(Query::make('BD_MATERIAL')->fields('FNumber'));
            self::fail('新凭据错误时应抛 AuthenticationException');
        } catch (AuthenticationException $e) {
            self::assertStringContainsString('密码错误', $e->getMessage());
        }

        // 登录失败后不得再发业务请求（只多了一次登录尝试）
        self::assertSame(3, $transport->requestCount());
        self::assertFalse($client->isAuthenticated());
    }

    public function test_manual_login_after_identity_change_does_not_trigger_second_login(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, self::LOGIN_OK),
            new HttpResponse(200, '[{"FNumber":"A"}]'),
            new HttpResponse(200, self::LOGIN_OK),
            new HttpResponse(200, '[{"FNumber":"B"}]'),
        );

        $config = Config::fromArray(self::CONFIG);
        $client = new Client($config, $transport);

        $client->query(Query::make('BD_MATERIAL')->fields('FNumber'));

        // 换密码后手动登录：登录应丢弃旧会话，并用新凭据
        $config->password = 'new-secret';

        $client->login();

        // 指纹已随登录同步：下一次请求不应再触发登录
        $client->query(Query::make('BD_MATERIAL')->fields('FNumber'));

        self::assertSame(4, $transport->requestCount());

        $logins = array_values(array_filter(
            $transport->requests,
            static function (array $request): bool {
                return strpos($request['url'], 'AuthService') !== false;
            }
        ));

        self::assertCount(2, $logins);
        self::assertSame('new-secret', $logins[1]['payload']['password']);
    }

    public function test_signature_identity_change_with_invalid_appid_fails_before_request(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '[{"FNumber":"A"}]'),
        );

        $config = Config::fromArray([
            'auth_type' => 3,
            'host_url' => 'https://k3cloud.example.com/k3cloud/',
            'acct_id' => 'acct',
            'username' => 'user',
            'appid' => 'client_secret',
            'appsecret' => 'secret',
        ]);

        $client = new Client($config, $transport);

        // 改成非法 appid（缺少 clientId_secret 形式）
        $config->appId = 'invalid-appid';

        try {
            $client->query(Query::make('BD_MATERIAL')->fields('FNumber'));
            self::fail('非法签名配置应抛 ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('appid', $e->getMessage());
        }

        // 不得发出任何业务请求
        self::assertSame(0, $transport->requestCount());

        // 指纹未提交：修好配置后应能正常继续
        $config->appId = 'client_newsecret';

        $client->query(Query::make('BD_MATERIAL')->fields('FNumber'));

        self::assertSame(1, $transport->requestCount());
    }

    public function test_write_operation_after_identity_change_uses_new_session(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, self::LOGIN_OK),
            new HttpResponse(200, '{"Result":{"ResponseStatus":{"IsSuccess":true}}}'),
            new HttpResponse(200, self::LOGIN_OK),
            new HttpResponse(200, '{"Result":{"ResponseStatus":{"IsSuccess":true}}}'),
        );

        $config = Config::fromArray(self::CONFIG);
        $client = new Client($config, $transport);

        $client->save('UWAZ_OutStock', ['FMaterialId' => 1]);

        $config->username = 'other-user';

        $client->save('UWAZ_OutStock', ['FMaterialId' => 2]);

        self::assertSame(4, $transport->requestCount());

        $logins = array_values(array_filter(
            $transport->requests,
            static function (array $request): bool {
                return strpos($request['url'], 'AuthService') !== false;
            }
        ));

        self::assertSame('user', $logins[0]['payload']['username']);
        self::assertSame('other-user', $logins[1]['payload']['username']);
    }
}
