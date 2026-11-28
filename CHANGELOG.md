# Changelog

本文件记录 `trappistes/kingdee-k3cloud-sdk` 的变更。

格式参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
版本号遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

> 版本号由 git tag 定义，`composer.json` 不含 `version` 字段。
> Packagist 会为每个版本固定其首次收录时的 tag 引用，故**本文档记录的是 git 层面的内容**，
> 与 Packagist 上实际分发的产物可能存在差异（参见下文 `1.0.0-beta.2` 条目）。

---

## [未发布]

## [1.0.0-beta.4] - 2026-11-28

### 修正

- **报文中的 Base64 内容不再写入日志**：`Redactor` 新增 Base64 识别，
  请求体与响应体中的大段 Base64（附件字节流、base64 图片等）统一替换为 `<base64 string>`。
  原实现只按固定键名屏蔽 `SendByte` 等字段，其它位置出现的 Base64 会原样写进日志：
  一是日志体积被附件撑爆，二是文件内容随日志外泄。
  识别规则要求长度不小于 64 且为 4 的倍数、严格解码成功、同时含大小写字母，
  并限制 `/` 密度；文件路径、哈希等长字符串不会被误替换。
  JSON 转义产生的 `\/` 也会被还原后再判断，避免正文里的 Base64 漏网。
  替换发生在截断之前，不会留下半截原文。
  扫描不依赖正则回溯，超大响应体（几十 MB 附件）同样会被正确替换。

## [1.0.0-beta.3] - 2026-11-27

### 修正

- **接口地址拼接不再依赖末尾斜杠**：`host_url` 末尾有没有 `/` 都能正常拼接，
  原实现手改 `hostUrl` 时忘了补 `/` 会直接拼出错误地址。
- **配置属性名拼写错误不再被静默忽略**：`Config` 新增 `__set()` 拦截不存在的属性名，
  写入 `$config->appid`（真实属性为 `appId`）这类拼写错误会直接抛 `InvalidArgumentException`。
  原实现会创建动态属性，SDK 读取的仍是旧属性，导致「已切换账套」的假象、旧会话继续被使用。
- **身份切换失败不再留下半更新状态**：身份指纹改为**切换成功后才提交**。
  原实现先提交指纹再切换，新凭据登录失败或签名配置非法时，客户端会永久停留在
  「新指纹 + 旧签名器」的状态，后续请求不再尝试切换。
  现在切换失败时直接抛异常且**不发送业务请求**，修正配置后下一次请求会重新尝试切换。
- **手动登录与身份切换不再互相打架**：`login()` 在凭据已变时会先丢弃旧会话，
  登录成功后同步身份指纹；原实现手动登录后，下一次请求会再次判定「身份已变」而重复登录，
  把刚建好的会话又作废掉。
- **实例指纹消除分隔符碰撞**：`Config::fingerprint()` 改用长度前缀拼接，
  原实现用 `|` 拼接，`acct_id='x|y'` + `username='z'` 与 `acct_id='x'` + `username='y|z'`
  会得到相同指纹；指纹值本身因此变化（该值仅作不透明标识使用）。
  非标量的密钥字段（如 `password` 被赋成数组）现在抛 `InvalidArgumentException`，
  原实现会静默算出错误指纹。
- **配置变更区分身份与行为**：身份字段（`auth_type` / `host_url` / `acct_id` / `username` /
  `password` / `appid` / `appsecret` / `org_num` / `lcid`）变更时，SDK 会丢弃旧会话（Cookie）
  并用新凭据重新登录后再发请求；行为字段（`timeout` / `connect_timeout` / `max_attempts`）
  变更立即生效，不触发重新登录。
  原实现改了凭据后旧会话仍被沿用，常驻进程复用同一客户端切账套时可能写错账套。
- **写端点与策略双向校验**：写端点传入可重试策略报错（已有）；反之，写策略用于未登记的端点也会报错，
  新增写端点时若忘记登记到 `Endpoints::WRITE_ENDPOINTS`，会立即失败而不会被当成读端点重试。
- **策略超时不得为非正数**：`Policy::read/write/attachment()` 拒绝 `timeout < 1`，
  原实现允许 `Policy::read(0)`，而 cURL 的 `CURLOPT_TIMEOUT=0` 表示永不超时。
- **实例指纹纳入组织维度**：`Config::fingerprint()` 现在包含 `org_num`，
  原实现把不同组织的签名认证客户端视为同一实例。
- **写端点的重试策略在 SDK 内固化**：新增 `Endpoints::WRITE_ENDPOINTS` 写端点清单，
  `Client::request()` 对写端点拒绝可重试策略（传入 `Policy::read()` 抛 `InvalidArgumentException`），
  未显式指定策略时按端点性质推导（写=不重试，读=可重试）。
  原实现完全依赖调用方自律，写端点可被误配为读策略而静默重试。
- **签名认证的应用 ID 格式在本地校验**：`Signer` 构造时要求 `appid` 为 `clientId_secret` 形式、
  `appsecret` 非空，否则抛 `ConfigurationException`。
  原实现 appid 缺 `_` 时会静默用空密钥签名，只表现为服务端拒绝，难以定位。
- **应用密钥登录的用户名可选**：`username` 为空时登录载荷不再下发该字段，
  避免服务端把空串当作非法用户名；填写时行为不变。
- **`Query::fromArray()` 不再拆解过滤表达式**：`FilterString` 原样保留为单个条件，
  原实现按 ` and ` 拆分会切坏值中含该文本的条件；现在 `fromArray(toArray())` 可逆。
- **每次请求前重新校验配置**：`Config` 是活对象，修改后可能被改坏；
  SDK 现在在每次请求前调用 `assertValid()`，不合法直接抛 `InvalidArgumentException`。
- **多值集合条件的写法**：`whereIn()` 改为逐值加引号（`FNumber in('101','102')`），
  原写法把多个值塞进一个字面量（`in('101,102')`），在金蝶按字面量解析时会静默返回空集。
  `whereOrg()` 随之修正。
- **过滤条件值的单引号按 SQL 惯例翻倍转义**：`whereEquals('FName', "O'Brien")` → `FName='O''Brien'`，
  原实现直接拼接会截断金蝶过滤表达式（可能被解析成另一个条件）。
  值中的逗号不做处理，多值集合逐值加引号后逗号不再产生歧义。
  > 转义规则按 SQL 惯例实现，**未在真实金蝶环境验证**；若服务端不接受双写单引号，
  > 请改用 `filter()` 传入自行构造的条件。
- **空对象不再被读成「查到 0 行」**：`json_decode('{}', true)` 与 `[]` 一样是空数组，
  原实现会把 `{}` 当作空行列表。现在只有 **JSON 数组** 才被视为查询行，
  `Response::normalize('{}')` 抛 `TransportException`（结构异常），
  `Response::isList('{}')` 与 `Response::isSuccess('{}')` 均返回 `false`。
- **成功判定不再自相矛盾**：信封中缺少 `IsSuccess` 时，`Response::normalize()` 与
  `Response::isSuccess()` 现在一致地判定为失败（`normalize()` 抛 `TransportException`），
  原实现一个当成功、一个当失败。
- **存在性判断回到第一页**：`exists()` 现在先把 `StartRow` 归零，
  避免复用带 `offset()` 的查询时检查到第 N 页而误判存在性。
- **写操作不再自动重发**：会话失效时，写操作直接抛 `AuthenticationException`
  （“写操作不自动重发，请由调用方确认后重试”），读操作仍可重登后重试。
  SDK 无法确认服务端返回会话失效前业务是否已执行，重发可能产生重复单据。
- **重试预算不再被绕过**：会话失效后的重发占用一次尝试额度，
  总请求数不再超过 `max_attempts`（原实现会多发一次）。
- **登录超时跟随本次请求的策略**：`request(..., Policy::read(5))` 时登录阶段同样只等 5 秒，
  原实现登录仍按全局超时（默认 30 秒）阻塞。
- **拒绝非正数超时**：`Config::assertValid()` 要求 `timeout` 与 `connect_timeout` 均大于 0，
  避免 `CURLOPT_TIMEOUT=0`（cURL 语义：永不超时）造成无限等待。
- **4xx 响应归类**：`4xx` 不再把报文原样返回，而是抛 `TransportException`（不可重试），
  避免上层拿到一段 HTML 后报「响应不是合法 JSON」。
- **不再重复发送同名请求头**：`CurlTransport` 只在调用方未提供时补充 `Content-Type` / `Accept`，
  原实现会发出 `Content-Type: application/json, application/json`。
- **日志分级**：`info` 事件只记端点与状态码，请求体与响应体移到 `debug` 事件，
  生产环境保持 `info` 级别时不会把单据内容写进日志。

### 兼容性

- **属于行为变更**（当前仍是 `1.0.0-beta` 阶段，未进入稳定版）：
  - 身份字段（凭据/账套/认证方式）变更后，下次请求会先重新登录（旧会话作废）；
  - 写端点传入可重试策略现在抛 `InvalidArgumentException`（原实现静默接受并重试）；
  - 写策略用于未登记端点同样抛 `InvalidArgumentException`；
  - `Policy::read/write/attachment()` 拒绝非正数超时；
  - `Config::fingerprint()` 现在包含 `org_num`；
  - `request()` 不传策略时，写端点默认不重试（原默认为读策略、会重试）；
  - 签名认证的 `appid` 格式不合法时构造即报错（原为运行期才失败）；
  - 应用密钥登录在 `username` 为空时不再下发该字段；
  - `Query::fromArray()` 的 `FilterString` 不再按 ` and ` 拆分；
  - 配置在每次请求前被重新校验，非法配置会提前抛异常；
  - `whereIn()` / `whereOrg()` 生成的 `FilterString` 文本格式变化（逐值加引号）；
  - 值中的单引号由「直接拼接」改为「翻倍转义」；
  - `{}` 响应从「空结果」变为 `TransportException`；
  - 缺少 `IsSuccess` 的信封从「按成功处理」变为失败；
  - 会话失效时写操作不再自动重发（抛 `AuthenticationException`）；
  - `4xx` 响应由「返回报文」变为 `TransportException`；
  - `timeout` / `connect_timeout` 必须大于 0；
  - 请求体与响应体不再写入 `info` 级日志事件；
  - 写入不存在的配置属性（如 `$config->appid`）现在抛 `InvalidArgumentException`，
    原实现会静默创建动态属性（拼写错误被掩盖，旧会话继续被当作当前身份使用）；
  - `Config::fingerprint()` 的取值算法变化（长度前缀拼接，消除分隔符碰撞），
    指纹值本身因此改变；该值仅作不透明标识使用；
  - `login()` 在凭据已变时会先丢弃旧会话，登录成功后同步身份指纹；
  - 身份切换失败（新凭据登录失败、签名配置非法）时不发送业务请求，
    且身份指纹不提交（修正配置后下一次请求会重新尝试切换）。
- 公开 API 签名无变更；`Query` / `Response` 的方法集合不变。
  `login()` / `relogin()` 仅新增可选参数（`?int $timeout`），旧调用方式不受影响。

### 验证

- PHPUnit：132 tests / 370 assertions 全部通过（新增 28 条回归用例）；
- PHPStan level 5：0 错误；
- 验证手段仅限单元测试与本机回环，**未连接任何真实金蝶服务器**；
- **未做实机验证**：金蝶过滤表达式语法、签名算法、会话失效时业务是否已执行、
  16 个写端点的字段与行为，均未在真实环境核对。

---

## [1.0.0-beta.2] - 2026-11-14

### 修正

- **修复 beta.1 的分发缺陷**：beta.1 被 Packagist 首次收录时，其 tag 指向的是更早的提交，
  导致 `composer require trappistes/kingdee-k3cloud-sdk:^1.0@beta` 实际安装到的是
  缺少下述内容的旧版本。beta.2 为修正版。

  相较 beta.1 **实际分发的内容**，beta.2 新增：

  - `src/Http/CurlTransport.php`
    - 移除冗余的 `CURLOPT_COOKIEJAR => ''` —— `CURLOPT_COOKIEFILE => ''` 已足以启用内存 Cookie 引擎
    - 移除析构函数中冗余的 `isset()` 判断 —— 构造失败时 PHP 不会调用析构函数，句柄必定已初始化
  - `.github/workflows/sdk-tests.yml`：PHP 7.4 / 8.1 / 8.3 / 8.4 CI 矩阵 + 依赖下限验证 + PHPStan
  - `phpstan.neon.dist`：PHPStan level 5（仅 `src/`）
  - `THIRD-PARTY-NOTICES.md`：第三方署名声明
  - `composer.json` 的 `test` / `analyze` / `check` scripts
  - `tests/Integration/`：以本机回环地址验证 cURL 会话保持

### 兼容性

- **公开 API 无任何变更**，beta.1 → beta.2 无需修改调用代码
- PHP `^7.4 || ^8.0`，无框架依赖

> **升级建议**：使用 `^1.0@beta` 约束的消费方直接 `composer update` 即可获得 beta.2；
> 若显式锁定了 `1.0.0-beta.1`，需改为 `1.0.0-beta.2`。

---

## [1.0.0-beta.1] - 2026-11-14

首个公开预览版。

### 新增

- **三种认证**：用户名+密码、应用ID+密钥、开放平台请求级签名
- **查询能力**：`Query` 构造器（字段 / 等值 / 集合 / 组织条件 / 排序 / 分页）、
  `queryRows()`、`paginate()`、`exists()`、`queryAll()` 自动翻页、
  `executeBillQueryRows()` 数组行转对象行
- **单据操作**：保存、批量保存、提交、审核、反审核、暂存、下推、删除、分配、
  弹性域、分组、拆单、工作流审核、自定义操作、查看
- **平台操作**：数据中心列表、分组信息、发送消息、附件上传
- **可靠性**：读操作分级重试（退避 + 抖动）、写操作不重试、会话失效自动重登
- **可观测性**：PSR-3 日志接入、密码 / 密钥 / 附件二进制脱敏
- **扩展点**：`Transport` 接口可替换 HTTP 客户端，`request()` 作为任意端点逃生口

### 兼容性

- PHP `^7.4 || ^8.0`
- 扩展 `curl` / `json` / `mbstring`；依赖 `psr/log ^1.1 || ^2.0 || ^3.0`
- 无框架依赖，可用于任意 PHP 项目

### 已知限制

- 16 个写类端点仅有报文契约测试，未在真实 ERP 验证
- `AUTH_SIGNATURE` 未实机验证，需金蝶侧开通第三方授权应用
- 金蝶未提供附件删除端点，附件生命周期由调用方控制

### 说明

- 公开 API 自本版起冻结，beta 期间仅做向后兼容补充
- 写操作不自动重试：网络超时后服务端是否已执行未知，须由调用方按业务单号幂等对账
- 本包以 MIT 许可发布，替代 `kingdee-php/k3cloud`（同为 MIT）；
  签名实现派生自后者，署名见 [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md)

---

发布流程见 [RELEASING.md](RELEASING.md)。
