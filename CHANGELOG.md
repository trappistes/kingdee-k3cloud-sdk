# Changelog

本文件记录 `trappistes/kingdee-k3cloud-sdk` 的变更。

格式参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
版本号遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

> 版本号由 git tag 定义，`composer.json` 不含 `version` 字段。

---

## [1.0.0-beta.1] - 2026-11-14

首个公开预览版，替代原 `kingdee-php/k3cloud`（MIT）。

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

- PHP `^7.4 || ^8.0`（CI 矩阵 7.4 / 8.1 / 8.3 / 8.4 持续验证）
- 扩展 `curl` / `json` / `mbstring`；依赖 `psr/log ^1.1 || ^2.0 || ^3.0`
- 无框架依赖，可用于任意 PHP 项目

### 与旧实现的差异

| 旧实现 | 本 SDK |
|---|---|
| 数组式查询参数 | `Query` 对象，条件组合集中可校验 |
| 静默 `die` / 吞异常 | 异常分层：认证 / 业务 / 传输 / 配置 |
| 重试策略混杂 | 读可重试、写不重试 |
| 分页需自行处理 | `queryAll()` 自动翻页 |

### 已知限制

- 16 个写类端点仅有报文契约测试，未在真实 ERP 验证
- `AUTH_SIGNATURE` 未实机验证，需金蝶侧开通第三方授权应用
- 金蝶未提供附件删除端点，附件生命周期由调用方控制

### 说明

- 公开 API 自本版起冻结，beta 期间仅做向后兼容补充
- 写操作不自动重试：网络超时后服务端是否已执行未知，须由调用方按业务单号幂等对账
- 本包以 MIT 许可发布；签名实现派生自 MIT 许可的 `kingdee-php/k3cloud`，致谢见 README 与 LICENSE

---

发布流程见 [RELEASING.md](RELEASING.md)。
