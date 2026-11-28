# trappistes/kingdee-k3cloud-sdk

[![SDK Tests](https://github.com/trappistes/kingdee-k3cloud-sdk/actions/workflows/sdk-tests.yml/badge.svg)](https://github.com/trappistes/kingdee-k3cloud-sdk/actions/workflows/sdk-tests.yml)

金蝶**云星空**（Kingdee K3 Cloud）开放平台 WebAPI 客户端 SDK。

框架无关：不依赖 Laravel / Symfony / PSR 容器，配置由调用方显式注入，日志遵循 PSR-3。

**运行环境**：PHP ≥ 7.4（扩展：curl / json / mbstring）。最低版本由 CI 矩阵（7.4 / 8.1 / 8.3 / 8.4）持续验证。

---

> ### ⚠️ v1.0.0-beta.4（预览版）
>
> **方法签名与配置键已冻结**（类、方法签名、配置键、异常体系），beta 期间只做向后兼容的补充；
> 行为语义的修正会记在 CHANGELOG（beta 期间已发生若干行为变更，见下文兼容性说明）。
>
> 已知限制：
> - **16 个写类端点尚未在真实 ERP 上验证**（save / audit / submit / push / delete 等）—— 仅有报文契约测试；
> - **签名认证（`AUTH_SIGNATURE`）尚未实机验证**，需金蝶侧开通第三方授权应用；
> - 账套的环境隔离（如禁止在开发环境连生产账套）属**应用侧责任**，SDK 不感知此类策略。
>
> 实机已验证：`ValidateUser` / `BillQuery` / `QueryBusinessInfo` / `view` / `ExecuteBillQuery` /
> `GetDataCenterList` / `QueryGroupInfo` / `SendMsg`（只读探测，未发送真实消息）。

---

## 金蝶三大云产品与本包定位

金蝶的三大云服务各自接口体系不同，**不能共用同一套端点与认证**：

| 产品 | 英文/代号 | 接口特征 | 本包状态 |
|---|---|---|---|
| **云星空** | K3 Cloud | 端点 `Kingdee.BOS.WebApi.ServicesStub.*`，账套（acct_id）+ 组织（org_id）模型，认证支持「账套+用户名密码」与「第三方授权应用ID+密钥」 | ✅ 本包实现 |
| 云星辰 | K3 Star | 端点前缀、单据对象与认证流程均与云星空不同 | ⏳ 后续独立包 `trappistes/kingdee-star-sdk` |
| 云苍穹 | Cloud Cosmic | 走开放平台 REST（签名鉴权），与 BOS WebAPI 不兼容 | ⏳ 后续独立包 `trappistes/kingdee-cosmic-sdk` |

**因此本包命名空间锁定产品线**：`Trappistes\Kingdee\K3Cloud\*`。
后续产品各自独立成包，避免误用；公共件（传输、异常、策略）如需多产品共用，
建议上提为 `trappistes/kingdee-sdk-core`，本包的 `Http/`、`Exception/`、`Policy` 届时可整体迁出。

---

## 安装

```bash
composer require trappistes/kingdee-k3cloud-sdk
```

本地仓库（path repository）方式：

```json
{
    "repositories": [
        { "type": "path", "url": "packages/*", "options": { "symlink": true } }
    ],
    "require": {
        "trappistes/kingdee-k3cloud-sdk": "^1.0@beta"
    }
}
```

---

## 快速开始

### 纯 PHP

```php
use Trappistes\Kingdee\K3Cloud\Client;
use Trappistes\Kingdee\K3Cloud\Config;
use Trappistes\Kingdee\K3Cloud\Query;

$client = new Client(Config::fromArray([
    'auth_type' => 1,
    'host_url'  => 'https://k3cloud.example.com/k3cloud/',
    'acct_id'   => '账套ID',
    'username'  => '用户名',
    'password'  => '密码',
    'lcid'      => 2052,
]));

// 查询物料（Query 描述条件，SDK 负责组装金蝶查询参数）
$rows = $client->queryRows(
    Query::make('BD_MATERIAL')
        ->fields(['FNumber', 'FName', 'FModifyDate'])
        ->whereOrg('FUseOrgId', '101')
        ->perPage(100)
);
// [['FNumber' => '01000004', 'FName' => '液氨', ...], ...]

// 保存单据（返回金蝶原始报文）
$body = $client->save('UWAZ_OutStock', $payload);
```

### Laravel

配置放 `config/erp.php`（服务接入参数），业务参数放 `config/setting.php`：

```php
// app/Providers/AppServiceProvider.php
public function boot(): void
{
    $this->app->singleton(Client::class, fn () => new Client(
        Config::fromArray(config('erp')),
        logger: Log::channel('erp'),
    ));
}
```

```php
$client = app(Client::class);
$rows   = $client->queryRows(
    Query::make('BD_MATERIAL')->fields(['FNumber', 'FName'])->perPage(50)
);
```

### 注入自定义 HTTP 客户端

默认使用零依赖的 `CurlTransport`（内置 Cookie 引擎、句柄复用）。
需要接入其他 HTTP 客户端（Guzzle / Symfony HttpClient / Laravel Http 等）时，实现 `Transport` 接口即可：

```php
use Trappistes\Kingdee\K3Cloud\Http\HttpResponse;
use Trappistes\Kingdee\K3Cloud\Http\Transport;

final class GuzzleTransport implements Transport
{
    public function __construct(private \GuzzleHttp\Client $http) {}

    public function post(string $url, array $headers, array $payload, array $options = []): HttpResponse
    {
        $response = $this->http->post($url, ['headers' => $headers, 'json' => $payload, 'http_errors' => false]);

        return new HttpResponse($response->getStatusCode(), (string) $response->getBody());
    }

    public function resetSession(): void
    {
        // 丢弃会话（如需重新登录）
    }
}

$client = new Client($config, new GuzzleTransport(new \GuzzleHttp\Client()));
```

---

## 核心设计

### 1. 写操作不盲目重试

网络异常意味着「无法确定服务端是否已执行」。对保存类操作自动重试会在金蝶侧生成重复单据。
因此 SDK 按操作性质分级：

| 操作 | 策略 | 理由 |
|---|---|---|
| `query()` / `metadata()` | 可重试（默认 3 次，线性退避） | 幂等 |
| `save()` / `delete()` / `unAudit()` / `audit()` / `submit()` | **不重试** | 不幂等，重试=重复单据 |
| `uploadAttachment()` | **不重试**（超时放宽到 120s） | 重试会产生冗余附件 |

写操作超时后请由业务侧做**幂等对账**（例如按业务单号查询是否已存在），而不是依赖 SDK 重试。

> **端点级固化**：写端点清单维护在 `Endpoints::WRITE_ENDPOINTS`。
> `Client::request()` 对写端点拒绝可重试策略（传入 `Policy::read()` 会抛
> `InvalidArgumentException`），未显式指定策略时按端点性质推导（写=不重试，读=可重试）。
> 因此「写端点被误配为读策略」不再依赖调用方自觉。

### 2. 会话失效自动重登

登录态过期时，金蝶返回业务错误而非 HTTP 错误。SDK 识别会话失效特征后：

- **读操作**：自动清空 Cookie 并重新登录，再重试一次（并占用一次重试预算）；
- **写操作**：**不自动重发**，直接抛 `AuthenticationException`。
  SDK 无法确认「会话失效」发生时服务端是否已执行业务，重发可能产生重复单据，
  请由调用方确认后自行重试。

### 3. 超时与异常分类

| 异常 | 场景 | 是否可重试 |
|---|---|---|
| `TransportException` | 网络故障、超时、5xx、4xx（接口不可用） | 由 `isRetryable()` 标识 |
| `BusinessException` | 金蝶返回 `IsSuccess=false` | 否（带 `errors()` 明细） |
| `AuthenticationException` | 登录失败（凭据错误） | ❌ |
| `AuthenticationException`（`isRetryable() === true`） | 登录瞬时故障（如「没有指定数据中心」） | ✅ 自动退避重试 |
| `AuthenticationException` | 会话失效且为写操作 | ❌（不自动重发） |

### 4. 多组织建模（金蝶领域模型）

金蝶是典型的**多组织 ERP**：同一套单据体系带多个组织维度，不同单据使用不同字段：

| 维度 | 字段 |
|---|---|
| 使用组织 | `FUseOrgId`（物料、客户、供应商等基础资料） |
| 采购组织 | `FPurchaseOrgId`（采购订单、入库单） |
| 销售组织 | `FSaleOrgId`（销售订单、出库单） |
| 库存组织 | `FStockOrgId` |
| 成本组织 | `FCostOrgId` |

SDK 负责建模这些维度（协议事实），并提供组织条件原语：

```php
Query::make('PUR_PurchaseOrder')
    ->fields(['FBillNo'])
    ->whereOrg(OrgFields::PURCHASE, '101');   // → FPurchaseOrgId.FNumber in('101')
```

> **分层约定**：SDK 知道「金蝶有哪些组织维度、条件怎么写」；
> **同步哪些组织**是业务决策，由调用方持有配置并传入具体组织编码。
> SDK 不持有任何业务口径（同步范围、业务单据类型、业务字段映射）。

### 5. 查询能力

```php
// 单页查询（返回原始响应）
$raw = $client->query(Query::make('BD_MATERIAL')->fields(['FNumber']));

// 查询并直接取数据行（只解码一次）
$rows = $client->queryRows(Query::make('BD_MATERIAL')->fields(['FNumber']));

// 自动翻页拉取全部（逐行产出，不占内存）
foreach ($client->queryAll(Query::make('BD_MATERIAL')->fields(['FNumber']), pageSize: 100) as $row) {
    // ...
}

// 存在性判断（通用原语，供业务侧做幂等核对）
$exists = $client->exists(Query::make('BD_MATERIAL')->fields(['FNumber'])->whereEquals('FNumber', 'A'));
```

> **自动翻页的意义**：金蝶 BillQuery 是分页接口，单次最多返回 `Limit` 行。
> `queryAll()` 按协议推进页码，**避免调用方遗漏翻页导致数据静默截断**
> （实测物料主数据约 2.6 万行 / 259 页，只取第一页会丢掉 2.58 万行）。

### 6. 查询条件组合

除组织条件外，SDK 只提供**通用的条件原语**：

```php
Query::make('BD_MATERIAL')
    ->fields(['FNumber', 'FName'])          // 查询字段
    ->whereEquals('FForbidStatus', 'A')     // 等值条件
    ->whereIn('FUseOrgId.FNumber', ['101']) // 集合条件
    ->filter("FModifyDate >= '2026-01-01'") // 原始条件
    ->orderBy('FModifyDate DESC')
    ->page(1, 100)                          // 分页
    ->toArray();                            // 物化为金蝶查询参数
```

条件构造规则：

- 多值集合按金蝶写法逐值加引号：`whereIn('FNumber', ['101', '102'])` → `FNumber in('101','102')`；
- 值中的单引号按 SQL 惯例翻倍转义：`whereEquals('FName', "O'Brien")` → `FName='O''Brien'`；
- 需要完全自定义的条件，用 `filter()` 传入自行构造的表达式。

### 7. 查询构造器 `Query`

统一承载金蝶 `BillQuery` 参数，避免各处手工拼接：

```php
Query::make('BD_MATERIAL')
    ->fields(['FNumber', 'FName'])
    ->filter("FForbidStatus='A'")           // 追加过滤条件
    ->orderBy('FModifyDate DESC')
    ->page(1, 100)                          // 分页
    ->toArray();                            // 物化为金蝶查询参数
```

`copy()` 可派生不同条件的查询；`only()` 覆盖已有过滤条件；`fromArray()` 便于从旧式参数数组迁移。
`fromArray()` 把 `FilterString` 原样保留为单个条件（不按 ` and ` 拆开，避免切坏含该文本的值），
因此 `fromArray(toArray())` 可逆。

> `QueryResult` 仅实现 `IteratorAggregate` / `Countable`；取行请用 `$result->rows`，不提供数组式访问。

### 8. 响应形态收敛

金蝶响应有两种形态，SDK 的 `Response` 统一处理，避免业务层把「错误信封」误当成「一行数据」：

```php
Response::rows($raw);          // 查询类 → 行数组
Response::isSuccess($raw);     // 操作类 → 是否成功
Response::errors($raw);        // 错误明细
Response::successEntities($raw); // 保存后返回的单据ID
Response::loginResultType($raw); // 1=登录成功
```

> 只有 **JSON 数组**才被视为查询行；`{}` 这类空对象视为「响应结构异常」并抛
> `TransportException`，不会被读成「查到 0 行」。

### 9. 日志分级与脱敏

| 事件 | 级别 | 内容 |
|---|---|---|
| `kingdee.request` | `info` | 端点与状态码 |
| `kingdee.request.detail` | `debug` | 脱敏后的请求体与响应体 |

请求体中的 `SendByte`（附件 base64）、`password`、`appsecret` 自动脱敏，
响应文本超长自动截断，避免日志体积失控。
报文里出现的 **Base64 大段内容**（附件字节流、base64 图片等）会被统一替换为
`<base64 string>`，不写原文；日志中的文件路径、哈希等长字符串不会被误替换。
业务明细只在 `debug` 级别输出：生产环境保持 `info` 级别时不会把单据内容写进日志。

### 10. 协议级快速失败

金蝶 BillQuery 必填 `FormId` 与 `FieldKeys`。SDK 在**发请求前**本地校验，
缺失时直接抛 `ConfigurationException`，避免一次无意义的网络往返：

```
查询缺少查询字段集合（FieldKeys）
```

### 11. 传输层

- 默认 `CurlTransport`：**零第三方依赖**；Cookie 由 cURL 内置引擎管理（遵循 Domain/Path 作用域匹配）；
  同一 Client 生命周期内复用句柄，保留连接缓存，避免每次请求重复 TCP + TLS 握手；
- 可自行实现 `Transport` 接口接入其他 HTTP 客户端（见上）。

---

## 配置速查

`Config::fromArray()` 使用的键名如下（同时兼容 camelCase 别名）：

| 键名 | 类型 | 默认 | 说明 |
|---|---|---|---|
| `auth_type` | int | `1` | `1` 账套+密码；`2` 第三方授权；`3` 开放平台签名 |
| `host_url` | string | `''` | 金蝶接口地址，如 `https://k3cloud.example.com/k3cloud/` |
| `acct_id` | string | `''` | 账套 ID |
| `username` | string | `''` | 用户名（`auth_type=1` 必填；`auth_type=2` 可选，为空时登录载荷不下发该字段；`auth_type=3` 参与签名 AppData） |
| `password` | string | `''` | 密码（仅 `auth_type=1`） |
| `appid` | string | `''` | 应用 ID（`auth_type=2/3`）；签名认证格式必须为 `clientId_secret`，否则构造时报错 |
| `appsecret` | string | `''` | 应用密钥（`auth_type=2/3`） |
| `lcid` | int | `2052` | 账套语系，2052 = 中文 |
| `org_num` | int | `0` | 组织编号（签名认证的 AppData 组成项） |
| `timeout` | int | `30` | 单次请求超时（秒）；附件上传固定放宽到 120s；必须 > 0 |
| `connect_timeout` | int | `10` | 连接超时（秒）；必须 > 0 |
| `max_attempts` | int | `3` | **读操作**最大尝试次数（含会话失效后的重发）；写操作恒为 1（不重试） |

> 也可用 `Config::fromEnvironment()` 从环境变量读取，默认前缀 `KINGDEE_`。
>
> **两套名字不要混用**：上面的键名只用于 `Config::fromArray()`；
> 直接改 `$client->config()` 上的属性时用的是 **PHP 属性名（驼峰）**：
> `authType` `hostUrl` `acctId` `username` `password` `appId` `appSecret`
> `lcid` `orgNum` `timeout` `connectTimeout` `maxAttempts`。
> 写成不存在的属性名（如 `$config->appid`）会直接抛 `InvalidArgumentException`，
> 避免拼写错误被静默忽略（旧会话继续被当成新身份使用）。
>
> **Config 是活对象**：`$client->config()` 返回的就是内部那个配置对象，修改它会影响后续请求。
> 为避免改坏后静默发请求，SDK 在**每次请求前**重新校验配置（必填项、超时 > 0），
> 不合法直接抛 `InvalidArgumentException`。需要固定配置时请自行克隆一份再传入。
>
> **改配置分两类**：
>
> | 类型 | 字段（PHP 属性名） | 改动的后果 |
> |---|---|---|
> | 身份 | `authType` `hostUrl` `acctId` `username` `password` `appId` `appSecret` `orgNum` `lcid` | **旧会话立即作废**，SDK 会用新凭据重新登录后再发请求 |
> | 行为 | `timeout` `connectTimeout` `maxAttempts` | 立即生效，**不触发重新登录** |
>
> 身份变更后重新登录是刻意的：旧 Cookie 属于旧账号/旧账套，
> 常驻进程复用同一个客户端切账套时继续用它会写错账套。
>
> 身份切换失败（如新密码错误、新 `appId` 不合法）时：
> SDK **不会发送业务请求**，而是直接抛出异常；
> 身份指纹也不会被提交，修正配置后下一次请求会重新尝试切换。

---

## 完整使用示例

```php
use Trappistes\Kingdee\K3Cloud\Client;
use Trappistes\Kingdee\K3Cloud\Config;
use Trappistes\Kingdee\K3Cloud\Exception\BusinessException;
use Trappistes\Kingdee\K3Cloud\Exception\TransportException;
use Trappistes\Kingdee\K3Cloud\Query;
use Trappistes\Kingdee\K3Cloud\Response;

$client = new Client(Config::fromArray([
    'auth_type' => Config::AUTH_PASSWORD,
    'host_url'  => 'https://k3cloud.example.com/k3cloud/',
    'acct_id'   => '账套ID',
    'username'  => '用户名',
    'password'  => '密码',
]));

try {
    // ── 查询：按组织过滤 + 条件组合 ──
    $rows = $client->queryRows(
        Query::make('BD_MATERIAL')
            ->fields(['FNumber', 'FName', 'FSpecification'])
            ->whereOrg('FUseOrgId', '101')
            ->whereEquals('FForbidStatus', 'A')
            ->perPage(200),
    );

    // ── 分页：需要页码信息时 ──
    $page = $client->paginate(
        Query::make('BD_MATERIAL')->fields(['FNumber', 'FName'])->perPage(100),
        page: 2,
    );
    echo $page->pageCount . ' 行，第 ' . $page->page . ' 页，还有下一页：' . var_export($page->hasNextPage(), true);

    // ── 写入：返回原始报文，取成功实体 ID ──
    $raw = $client->save('UWAZ_OutStock', [
        'IsAutoSubmitAndAudit' => true,
        'IsVerifyBaseDataField' => true,
        'Model' => ['F_PAEZ_CarNo' => ['FNumber' => '粤B12345']],
    ]);
    $entities = Response::successEntities($raw);
    $billId   = $entities[0]['Id'] ?? null;

    // ── 错误判定 ──
    if (! Response::isSuccess($raw)) {
        throw new BusinessException(Response::errorMessage($raw), Response::errors($raw));
    }
} catch (BusinessException $e) {
    // 金蝶业务拒绝（编码重复、字段不合法等）：重试无意义
    error_log('业务失败：' . $e->getMessage());
} catch (TransportException $e) {
    // 网络故障 / 超时：可重试；写操作请改用幂等对账，而非重试
    error_log('传输失败（可重试：' . var_export($e->isRetryable(), true) . '）：' . $e->getMessage());
}
```

---

## 分页与大批量同步

金蝶 BillQuery 是**分页接口**，单次最多返回 `Limit` 行（上限 2000）。
漏掉翻页会造成**静默数据截断**（实测物料主数据约 2.6 万行 / 259 页）。

```php
// 自动翻页，逐行产出（不占内存，适合大批量同步）
foreach ($client->queryAll(
    Query::make('BD_MATERIAL')->fields(['FNumber', 'FModifyDate'])->whereOrg('FUseOrgId', '101'),
    pageSize: 200,
) as $row) {
    // $row 是对象行
}

// 手动翻页
$page = 1;
while (true) {
    $result = $client->paginate($query, $page, 200);
    if ($result->isEmpty()) {
        break;
    }
    foreach ($result->rows as $row) {
        // ...
    }
    if (! $result->hasNextPage()) {
        break;
    }
    $page++;
}
```

> **增量同步建议**：用 `FModifyDate` 作为游标，只同步「上次游标之后」的记录；
> 翻页只解决「取全」，不解决「取哪些」。

---

## 单据写入实践

所有单据写操作的报文结构一致：`{formid, data}`，`data` 即单据体。

```php
$payload = [
    'IsAutoSubmitAndAudit'   => true,   // 保存后自动提交并审核
    'IsVerifyBaseDataField'  => true,   // 校验基础资料字段
    'Model' => [
        'FNumber' => 'XSDD000001',
        // 明细体
        'FQuoteEntry' => [[ 'FMaterialId' => ['FNumber' => '01000004'] ]],
    ],
];

$client->save('SAL_SaleOrder', $payload);   // 保存
$client->submit('SAL_SaleOrder', $payload); // 提交
$client->audit('SAL_SaleOrder', $payload);  // 审核
$client->unAudit('SAL_SaleOrder', $payload);// 反审核
$client->delete('SAL_SaleOrder', $payload); // 删除
```

**自定义操作**（变更、关闭、重新审核等）：

```php
$client->operation('SAL_SaleOrder', 'ReAudit', ['Model' => ['FNumber' => 'XSDD000001']]);
```

> ⚠️ 写操作**不会自动重试**。若网络超时，服务端可能已建单 —— 请按业务单号做幂等对账
> （用 `exists()` 查询是否已存在），而不是重试。

---

## 附件上传实践

附件挂在**单据**上，`FormId` / `BillNo` / `InterId` / `EntryKey` / `EntryInterId` 共同定位归属：

```php
$raw = $client->uploadAttachment([
    'FormId'       => 'SAL_SaleOrder',           // 附件所属单据对象
    'BillNo'       => 'XSDD000001',              // 单据编号
    'InterId'      => 100025,                    // 单据内码
    'EntryKey'     => 'FSaleOrderEntry',         // 分录标识
    'EntryInterId' => 100028,                    // 分录内码
    'FileName'     => '磅单.pdf',
    'AliasFileName'=> '磅单.pdf',
    'SendByte'     => base64_encode(file_get_contents('/path/to/ticket.pdf')),
    'IsLast'       => 'true',
    'FileId'       => '',
]);

$fileId = Response::decode($raw)['Result']['FileId'] ?? null;
```

> 金蝶**没有删除附件的接口**（`BOS_Attachment` 是系统基础资料，不能作为业务单据删除）。
> 避免附件堆积的做法：上传前先按单据查询已有附件并跳过/替换；
> 或把附件挂到会随单据删除而级联清理的单据上。

---

## 日志接入

SDK 遵循 PSR-3，未注入日志器时不做任何日志动作：

```php
// Laravel
$client = new Client($config, logger: Log::channel('erp'));

// 纯 PHP：Monolog
$logger = new Monolog\Logger('k3cloud', [
    new Monolog\Handler\RotatingFileHandler('/var/log/k3cloud.log', 14, Monolog\Level::Info),
]);
$client = new Client($config, logger: $logger);
```

日志内容已脱敏：附件二进制（`SendByte`）、`password`、`appsecret` 会被替换为 `[omitted:N bytes]`；
报文中其它位置出现的 Base64 内容会被替换为 `<base64 string>`。

---

## 常见问题

**Q：返回 `金蝶登录失败：没有指定数据中心。`**
A：金蝶数据中心的瞬时故障，SDK 会自动退避重试。若持续出现，检查 `host_url` 与网络连通性。

**Q：查询结果字段错位 / 取不到值**
A：两个查询接口的行结构不同 —— `BillQuery` 返回对象行，`ExecuteBillQuery` 返回**数组行**。
后者请用 `executeBillQueryRows()`（自动映射为对象行）。

**Q：如何确认账号可用**
A：调用 `getDataCenterList()`，能返回即说明连接、认证正常。

**Q：如何排查一次请求**
A：注入带 `debug` 级别的 PSR-3 日志器，SDK 会记录端点、状态码、脱敏后的请求体与响应体。

**Q：能不能在开发环境连生产账套**
A：SDK 不做环境隔离（它只关心「怎么连」）。请在应用侧实现：在构造客户端之前校验账套是否被允许，
命中即拒绝，从而保证不发出任何网络请求。

---

## 发布与许可

- 许可证：[MIT](LICENSE)
- 发布流程与发布前检查清单：[RELEASING.md](RELEASING.md)
- 变更记录：[CHANGELOG.md](CHANGELOG.md)
- 签名认证实现派生自 MIT 许可的 [`kingdee-php/k3cloud`](https://github.com/kingdee-php/k3cloud)，致谢见文末

### 安装来源

```bash
# Packagist（推荐）
composer require trappistes/kingdee-k3cloud-sdk:^1.0@beta

# 直接引用 Git 仓库（未发布 tag 时）
# 在消费方 composer.json 中加入：
#   "repositories": [{"type": "vcs", "url": "git@github.com:trappistes/kingdee-k3cloud-sdk.git"}]
```

---

## 版本策略

- `1.0.0-beta.x`：**方法签名与配置键已冻结**，仅做向后兼容的补充（行为语义的修正会记在 CHANGELOG）；
- `1.0.0`：正式版，移除 beta 标记；
- 语义化版本：破坏性变更走主版本号，功能新增走次版本号，修复走修订号。

---

## 异常体系

| 异常 | 场景 | 可重试 |
|---|---|---|
| `TransportException` | 网络故障、超时、5xx、接口不可用 | 由 `isRetryable()` 标识 |
| `BusinessException` | 金蝶返回 `IsSuccess=false` | 否（附 `errors()` 明细） |
| `AuthenticationException` | 登录失败（凭据错误） | ❌ |
| `AuthenticationException`（`isRetryable() === true`） | 登录瞬时故障（如「没有指定数据中心」） | ✅ 自动退避重试 |
| `ConfigurationException` | 配置缺失、查询参数缺失 | 否（需修正配置） |
| `InvalidArgumentException` | API 误用（如传入非 `Query`） | 否（需修正调用） |

---

## API 一览

| 分类 | 方法 |
|---|---|
| 会话 | `login()` / `relogin()` / `isAuthenticated()` |
| 查询 | `query()`（BillQuery）/ `queryRows()` / `queryAll()` / `paginate()` / `exists()` / `executeBillQuery()` / `executeBillQueryRows()` / `metadata()` / `view()` |
| 单据写入 | `save()` / `batchSave()` / `delete()` / `unAudit()` / `audit()` / `submit()` / `draft()` / `push()` / `allocate()` / `flexSave()` / `groupSave()` / `groupDelete()` / `disassembly()` / `workflowAudit()` / `operation()` |
| 平台 | `sendMsg()` / `queryGroupInfo()` / `getDataCenterList()` |
| 附件 | `uploadAttachment()` |
| 进阶 | `request()`（任意端点）、`config()` / `transport()` |

> 单据操作已覆盖金蝶官方 WebAPI 的标准操作集（端点同构，报文统一为 `formid` + `data`）；
> 未列出的端点可直接调用 `request()`，无需改动 SDK。

---

## 测试

```bash
composer install          # 安装依赖（库不提交 composer.lock）
composer test            # PHPUnit
composer analyze         # PHPStan 静态分析（level 5，仅 src/）
composer check           # 静态分析 + 测试（与 CI 同一串命令）
```

单元测试使用内存假传输（`tests/Support/FakeTransport.php`），不发起真实网络请求。

CI 矩阵覆盖 PHP 7.4 / 8.1 / 8.3 / 8.4，另含 `--prefer-lowest` 依赖下限验证，
详见 [`.github/workflows/sdk-tests.yml`](.github/workflows/sdk-tests.yml)。

---

## 许可证

**MIT**（见 [LICENSE](LICENSE)）；第三方声明见 [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md)。

本包为独立开源项目，与任何商业实体无隶属或授权关系。

### 第三方致谢

签名认证（`src/Signer.php`）的实现派生自 MIT 许可的
[`kingdee-php/k3cloud`](https://github.com/kingdee-php/k3cloud)；
`src/Endpoints.php` 的端点路径遵循金蝶官方 WebAPI 规范。
