# Changelog

本文件记录 `trappistes/kingdee-k3cloud-sdk` 的变更。

格式参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
版本号遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

> 版本号由 git tag 定义，`composer.json` 不含 `version` 字段。
> Packagist 会为每个版本固定其首次收录时的 tag 引用，故**本文档记录的是 git 层面的内容**，
> 与 Packagist 上实际分发的产物可能存在差异（参见下文 `1.0.0-beta.2` 条目）。

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
