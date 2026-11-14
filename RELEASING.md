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
| 1 | `composer.json` 的 `homepage` / `support.source` / `support.issues` 为**真实仓库地址** | 人工确认（当前为 `https://github.com/trappistes/kingdee-k3cloud-sdk`） |
| 2 | `composer validate --no-check-publish --strict` 通过 | `composer validate --no-check-publish --strict` |
| 3 | 全版本测试通过（PHP 7.4 / 8.1 / 8.3 / 8.4）+ 依赖下限 + 静态分析 | CI 全部 Job 变绿（见 §4.2），本地可跑 `composer check` |
| 4 | 源码在 PHP 7.4 下可解析（声明的最低版本是**被验证过的承诺**） | `find src tests -name '*.php' -print0 \| xargs -0 -n1 php -l`（在 7.4 上执行），或由 CI 矩阵的语法检查步骤保证 |
| 5 | 静态分析无告警 | `composer analyze`（PHPStan level 5，仅 `src/`） |
| 6 | 无敏感信息入库 | `git grep -nE "(password|appsecret)\s*=\s*['\"][^'\"]+" -- src tests` 无命中 |
| 7 | LICENSE / CHANGELOG / README 齐备且版本号一致 | 人工确认 |
| 8 | `CHANGELOG.md` 顶部版本号与 tag 一致 | 人工确认 |

### 2.2 建议完成

- [ ] 代码风格一致（人工 Review）—— 基础包**不引入**代码风格工具：
      Pint 属 Laravel 生态，与上游 `kingdee-php/k3cloud` 的风格无关，强行引入只会产生无关 diff
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

> 本仓库为**独立仓库**（包目录即仓库根），CI 工作流位于仓库根目录的
> `.github/workflows/sdk-tests.yml`。

### 4.1 本地验证

```bash
composer update --prefer-dist --no-interaction   # 库不提交 composer.lock
composer check        # 静态分析 + 测试（与 CI 同一串命令）
composer analyze     # PHPStan level 5
composer test        # PHPUnit
```

PHP 7.4 需单独验证：

```bash
find src tests -name '*.php' -print0 | xargs -0 -n1 php7.4 -l
```

### 4.2 CI 矩阵

| Job | 版本 | 内容 |
|---|---|---|
| `tests` | 7.4 / 8.1 / 8.3 / 8.4 | `composer validate` → 全量语法检查 → `composer test` |
| `lowest-deps` | 8.1 | `--prefer-lowest` 验证依赖下限约束未误改 |
| `analyze` | 8.4 | `composer analyze`（独立 job，避免 7.4 工具链兼容问题） |

**PHP 7.4 的兼容性由真实执行保证**（矩阵中实际跑完测试套件），而非静态分析推断——
固定 `phpVersion` 会让 `\CurlHandle` 这类 PHP 8.0+ 才存在的符号产生「未知类」误报。

---

## 5. 发布流程

### 5.1 准备独立仓库

本包已从主项目拆分，**当前即为独立仓库**（包目录即仓库根）。首次建仓用的命令：

```bash
git init && git add -A
git commit -m "feat: 金蝶云星空 WebAPI SDK 首个预览版"
git remote add origin git@github.com:trappistes/kingdee-k3cloud-sdk.git
git push -u origin main
```

> 若包目录仍位于主项目的 `packages/` 下，需要用 `git filter-repo` /
> `git subtree split` 拆分历史，或直接以一次初始提交重新建仓
> （推荐，beta 阶段无历史负担）。

### 5.2 打 tag

**前置条件：§4.2 的 CI 全部 Job 变绿。** tag 一旦推送即视为对外发布，不可撤回。

> ⚠️ **发布前务必确定 tag 指向的提交无误。** Packagist 收录后，
> 该版本的 `source.reference` 即被固定；之后即使强制移动 tag，
> Packagist **不会**重新解析，已收录的版本会永久指向旧提交。
> 若发现打错了 tag，只能发补丁版本（如 `1.0.0-beta.2`）修正，
> 而不能靠移动 tag 收回。

```bash
# 1. 确认工作区干净且已同步
git status
git pull --ff-only

# 2. 打 tag（message 与版本号一致）
git tag -a v1.0.0-beta.1 -m "1.0.0-beta.1"

# 3. 推送 tag
git push origin v1.0.0-beta.1
```

Tag 必须是 `v` 前缀（Composer 识别 `v1.0.0` 与 `1.0.0` 等价，`v` 前缀更通用）。

> 注意：`git push origin v1.0.0-beta.1` 偶发 `src refspec does not match any`
> （本地 tag 与远端 ref 解析不一致），改用 `git push origin --tags` 可正常推送。

创建 GitHub Release（可选，但便于展示 CHANGELOG）：

```bash
gh release create v1.0.0-beta.1 --title "v1.0.0-beta.1" --notes-file CHANGELOG.md
```

### 5.3 发布到 Composer 仓库

**Packagist（公共）—— 必做**

仓库创建后**必须**在 Packagist 提交仓库地址，否则消费方无法通过
`composer require` 安装（GitHub 上的 Release 不等于 Composer 上的发布）。

1. 打开 https://packagist.org/packages/submit
2. 提交仓库地址 `trappistes/kingdee-k3cloud-sdk`（GitHub 会弹出授权请求，需登录并授权）
3. 确认包页面 https://packagist.org/packages/trappistes/kingdee-k3cloud-sdk
   上出现 `v1.0.0-beta.1`

因包内无 `version` 字段，版本来自 **tag**。首次提交后抓取需数分钟，
期间 `composer require` 报 `could not find a version matching` 属正常现象。

> **校验索引是否正确**：Packagist 收录后会固定该版本的 `source.reference`。
> 可用 `curl -s https://repo.packagist.org/p2/<vendor>/<name>.json` 查看
> 其中的 `source.reference` 是否等于 tag 指向的提交，不等则说明索引陈旧，
> 需发补丁版本修正（见 §5.2）。

> 自动化方案：在 `.github/workflows/` 添加 `publish.yml`，使用
> `PackagistComposer/publish-github-action`，配置 `PACKAGIST_API_TOKEN` 与
> `PACKAGIST_USERNAME` 两个仓库 Secret。

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
| 消费方回退到上一个 tag | `composer require trappistes/kingdee-k3cloud-sdk:1.0.0-beta.0`（若该版本存在） |
| 撤回已发布版本 | 删除远端 tag（`git push origin :refs/tags/vX.Y.Z`），Packagist 需手动清除该版本 |
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
详见 [LICENSE](LICENSE)、[THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md) 与 [README 的致谢章节](README.md)。
