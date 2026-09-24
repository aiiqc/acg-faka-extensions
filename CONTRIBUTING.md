# 贡献说明

公开源码位于 [aiiqc/acg-faka-extensions](https://github.com/aiiqc/acg-faka-extensions)，首次预发布标识为 `v0.1.0-preview.1`，套装仍为 `0.1.0-dev`。本文件记录提交前截点，下载须以预发布页的实际附件为准，不是稳定版声明。开始前阅读 [README](README.md)、[项目状态](docs/PROJECT_STATUS.md)与[安全说明](SECURITY.md)。

## 修改范围

优先复用现有安装器、同步链和测试。一次修改只解决一个明确问题；说明复现步骤、预期行为、实际影响及恢复方式。不要顺手升级依赖、重构无关模块、放宽官方版本／哈希／权限门或加入第二套同步与支付账本。

使用合成数据；不得提交 `.env`、数据库、支付配置、商户密钥、会话、安装回执、真实站点路径或原始响应／日志。提交前检查 diff 和新增文件。代码注释及 commit message 使用英文；文档沿用所在文件语言。第三方图片、字体、音视频须有明确再分发权限，MIT 代码许可不能代替素材授权。

## 按依赖选择验证层

在仓库根目录运行已审阅且与修改有关的入口，不要直接把 `node --test tests/*.test.mjs` 当作无外部依赖的静态检查。

| 层 | 已有入口与依赖 | 证据边界 |
| --- | --- | --- |
| Node／Shell 契约 | Node.js 22 LTS、Bash、Git及入口实际使用的工具；如 `tests/installer-contract.test.mjs` | 主要检查源码、临时夹具及安全契约，部分入口另有 PHP／Docker 子项，运行前检查文件 |
| PHP 行为 | `tests/local-supply-sync-resume.php`、`tests/local-supply-config-selection.php` 等；需要对应 PHP 扩展、加载路径及官方夹具 | 合成行为不是目标站数据库或真实上游验收，不能脱离各入口夹具直接执行 |
| 容器与安装恢复 | `tests/install-integration.sh`、`tests/install-integration-container.sh`；Docker、固定官方 Git checkout、已审阅的 PHP 集成镜像及 Linux 工具 | 涉及真实临时文件权限和安装恢复；镜像与夹具未备妥时记 `NOT RUN`，不要临时下载未知镜像 |
| 目标站／交易 | 按安装教程独立准备受控测试环境和明确授权 | 不由本地契约或隔离矩阵推定，不使用真实客户数据填补测试 |

例如，仅运行下列 SupplySync／支付契约的非 Docker 子集（刻意排除五个容器子项；仍须阅读当前入口确认依赖未变）：

```bash
node --test \
  --test-skip-pattern='CLI rejects|uses 60-second idle|stages the typed item failure|renders a checkout configuration|passes the isolated PHP behavior suite' \
  tests/local-supply-contract.test.mjs \
  tests/local-bepusdt-adapter-contract.test.mjs
```

这个子集不是完整套件；报告须同时列出跳过项。安装集成 wrapper 用 `git ls-files` 暂存本仓库已跟踪文件，因此无 `.git` 的导出候选不能代替正式源码 checkout 运行该 wrapper。`ACG_FAKA_OFFICIAL_ROOT` 应指向已核实的完整官方夹具，`PIKA_INSTALL_IMAGE` 应指向本机已审阅的集成镜像；当前没有承诺可公开下载的配套镜像或一键依赖安装方案。

## 提交验证结果

说明改了什么、实际运行的完整命令、结果 `PASS`／`FAIL`／`NOT RUN` 及缺失依赖。bug 尽可能给出同一检查的修复前失败与修复后通过；不要为通过而删断言或扩大安全例外。分别报告静态、合成、隔离安装恢复、最终制品和目标环境验证，不把任一层升级为生产成功。

普通功能问题可在[公开 Issues](https://github.com/aiiqc/acg-faka-extensions/issues)提交；疑似漏洞不要公开细节，使用 [SECURITY.md](SECURITY.md) 中的私密渠道。

纯文档改动只核对受影响的事实、相对链接、图片及公开内容，不要求无关全仓测试。截图必须注明实际运行的模板／代码与合成数据边界；不使用生成图冒充产品截图，不提交包含本机路径的原始测试回执。

文档结构复用 GitHub 原生 [CONTRIBUTING.md](https://docs.github.com/en/communities/setting-up-your-project-for-healthy-contributions/setting-guidelines-for-repository-contributors) 与 [Keep a Changelog](https://github.com/olivierlacan/keep-a-changelog) 的人工可读记录方式；不从 Git 日志机械补造发布历史。
