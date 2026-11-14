# 发布指南（RELEASING）

本文件描述 `trappistes/kingdee-k3cloud-sdk` 的发布流程、发布前检查项与回滚方式。

> 版本号由 **git tag** 定义，包内 `composer.json` **不含** `version` 字段。

---

## 1. 发布形态

| 形态 | 适用场景 | 消费方安装方式 |
|---|---|---|
| 公共仓库（Packagist） | 开源分发 | `composer require trappistes/kingdee-k3cloud-sdk:^1.0@beta` |
| 私有仓库（自建 Composer / VCS） | 企业内部分发 | `composer require trappistes/kingdee-k3cloud-sdk:^1.0@beta` + 仓库配置 |
| Git 仓库直接引用 | 灰度 / 未发布 tag | `"repositories": [{"type":"vcs","url":"git@github.com:trappistes/kingdee-k3cloud-sdk.git"}]` |

包本身**不依赖任何框架**（无 Laravel / Symfony / Illuminate 依赖），消费方无需适配。

---

## 2. 发布前检查清单

### 2.1 必须完成（阻断发布）

| # | 检查项 | 校验方式 |
|---|---|---|
| 1 | `composer.json` 的 `homepage` / `support.source` / `support.issues` 为**真实仓库地址** | 人工确认（当前为占位地址，发布前必须替换） |
| 2 | `composer validate --no-check-publish` 通过 | `composer validate --no-check-publish` |
| 3 | 全版本测试通过（PHP 7.4 / 8.1 / 8.3 / 8.4） | `composer test` 或见 §4 |
| 4 | 源码在 PHP 7.4 下可解析（声明的最低版本是**被验证过的承诺**） | `find src tests -name '*.php' -print0 \| xargs -0 -n1 php -l`（在 7.4 上执行） |
| 5 | 代码风格一致 | `vendor/bin/pint --test packages/kingdee-k3cloud-sdk/src packages/kingdee-k3cloud-sdk/tests` |
| 6 | 无敏感信息入库 | `git grep -nE "(password|appsecret)\s*=\s*['\"][^'\"]+" -- src tests` 无命中 |
| 7 | LICENSE / CHANGELOG / README 齐备且版本号一致 | 人工确认 |
| 8 | `CHANGELOG.md` 顶部版本号与 tag 一致 | 人工确认 |

### 2.2 建议完成

- [ ] 至少 1 位非作者维护者完成 Code Review
- [ ] 在**测试帐套**完成一次端到端冒烟（见 §5）
- [ ] README 中的示例代码与当前公开 API 一致（公开 API 变更后必须复核）

---

## 3. 版本号策略

遵循 [语义化版本](https://semver.org/lang/zh-CN/)：

| 版本 | 含义 | 兼容性承诺 |
|---|---|---|
| `1.0.0-beta.N` | 预览版 | 公开类、方法签名、配置键、异常体系**冻结**；只做向后兼容补充 |
| `1.0.0` | 首个正式版 | 冻结 API，进入语义化版本常规维护 |
| `1.x.y` | 常规迭代 | `x` 为主版本（不兼容变更），`y` 为次版本（向后兼容新增） |

**beta 期间允许的变更**：新增方法、新增配置键（带默认值）、修复缺陷、文档补充。
**beta 期间禁止的变更**：删除/重命名公开方法、修改方法签名、修改异常层级、修改配置键语义。

---

## 4. 测试矩阵

```bash
# 包内独立安装（不依赖主项目）
cd packages/kingdee-k3cloud-sdk
composer update --prefer-dist --no-interaction

# 各 PHP 版本分别执行（PHP 7.4 必须单独验证）
php vendor/bin/phpunit -c phpunit.xml
```

CI 工作流：`.github/workflows/sdk-tests.yml`（矩阵 7.4 / 8.1 / 8.3 / 8.4）。
作为独立仓库发布时，把该文件放到**仓库根目录**的 `.github/workflows/`，
并删除 `defaults.run.working-directory`（包目录即仓库根）。

---

## 5. 发布流程

### 5.1 准备独立仓库

```bash
# 1. 新建仓库（空仓库）
git init packages/kingdee-k3cloud-sdk
cd packages/kingdee-k3cloud-sdk

# 2. 首次提交
git add -A
git commit -m "feat: 金蝶云星空 WebAPI SDK 首个预览版"

# 3. 关联远端
git remote add origin git@github.com:trappistes/kingdee-k3cloud-sdk.git
git push -u origin main
```

> 注意：包目录当前已位于主项目仓库的 `packages/` 下，
> 需要用 `git filter-repo` / `git subtree split` 拆分历史，
> 或直接以一次初始提交重新建仓（推荐，beta 阶段无历史负担）。

### 5.2 打 tag

```bash
git tag -a v1.0.0-beta.1 -m "1.0.0-beta.1"
git push origin v1.0.0-beta.1
```

Tag 必须是 `v` 前缀（Composer 识别 `v1.0.0` 与 `1.0.0` 等价，`v` 前缀更通用）。

### 5.3 发布到 Composer 仓库

**Packagist（公共）**

1. 在 Packagist 提交仓库地址并关联；
2. Packagist 会自动从 `composer.json` 读取版本 —— 因包内无 `version` 字段，
   版本来自 **tag**；
3. 确认包页面上出现 `1.0.0-beta.1`。

**私有 Composer 仓库（ satis / artifact）**

```bash
# satis.json 示例
{
    "repositories": [
        { "type": "composer", "url": "https://repo.example.com" },
        { "packagist.org": false }
    ]
}
```

**私有 Git 仓库（无需 Composer 仓库）**

消费方 `composer.json`：

```json
{
    "repositories": [
        { "type": "vcs", "url": "git@github.com:trappistes/kingdee-k3cloud-sdk.git" }
    ],
    "require": {
        "trappistes/kingdee-k3cloud-sdk": "^1.0@beta"
    }
}
```

```bash
composer update trappistes/kingdee-k3cloud-sdk --prefer-dist
```

### 5.4 消费方验证

```bash
composer require trappistes/kingdee-k3cloud-sdk:^1.0@beta
composer show trappistes/kingdee-k3cloud-sdk   # 确认版本为 1.0.0-beta.1
php -r 'require "vendor/autoload.php"; var_dump(class_exists(\Trappistes\Kingdee\K3Cloud\Client::class));'
```

---

## 6. 主项目切换（消费方视角）

主项目当前通过 **path 仓库**引用本地包（开发期）：

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "packages/kingdee-k3cloud-sdk",
            "options": { "versions": { "trappistes/kingdee-k3cloud-sdk": "1.0.0-beta.1" } }
        }
    ],
    "require": { "trappistes/kingdee-k3cloud-sdk": "^1.0@beta" }
}
```

切换到已发布的仓库时：

```json
{
    "repositories": [
        { "type": "vcs", "url": "git@github.com:trappistes/kingdee-k3cloud-sdk.git" }
    ],
    "require": { "trappistes/kingdee-k3cloud-sdk": "^1.0@beta" }
}
```

```bash
rm -rf vendor/trappistes && composer update trappistes/kingdee-k3cloud-sdk --prefer-dist
```

---

## 7. 回滚

| 场景 | 操作 |
|---|---|
| 消费方回退到上一个 tag | `composer require trappistes/kingdee-k3cloud-sdk:1.0.0-beta.0` |
| 撤回已发布版本 | 删除远端 tag（Packagist 需手动清除该版本） |
| 发布后发现缺陷 | 补丁版本 `1.0.0-beta.2`（beta 期间不做 immutable 版本） |

> 因 beta 阶段 API 已冻结，**不兼容变更必须走新主版本**（如 `2.0.0-beta.1`），
> 不可在同一主版本内删除或改变公开方法签名。

---

## 8. 当前版本的已知限制（发布时需在 README 同步）

| 限制 | 说明 | 影响面 |
|---|---|---|
| 16 个写类端点仅有契约测试 | `save` / `submit` / `audit` / `delete` / `unAudit` / `draft` / `push` / `allocate` / `flexSave` / `groupSave` / `groupDelete` / `disassembly` / `workflowAudit` / `batchSave` / `operation` / 附件上传 未在真实 ERP 验证 | 调用方需在测试帐套先行验证 |
| 开放平台签名认证未实机验证 | 需金蝶侧为测试帐套开通第三方授权应用 | `AUTH_SIGNATURE` 通道 |
| 应用密钥派生规则 | 采用 PHP 变体（`base64_decode` 后与固定密钥异或再 `base64_encode`），与官方 Java/Python 示例的原始字节变体存在差异 | 仅影响 `AUTH_SIGNATURE` |
| 附件无删除接口 | 金蝶未提供附件删除端点，附件生命周期需由调用方通过挂载对象与去重控制 | 附件相关业务 |
| 不做环境隔离 | 「禁止在开发环境连生产帐套」属**应用侧责任**，SDK 不感知该策略 | 调用方需自行在构造客户端前拦截 |

---

## 9. 第三方致谢

签名认证实现派生自 MIT 许可的 [`kingdee-php/k3cloud`](https://github.com/kingdee-php/k3cloud)，
其协议端点与报文结构亦与该实现等价。本包以 MIT 许可发布，
详见 [LICENSE](LICENSE) 与 [README 的致谢章节](README.md)。
