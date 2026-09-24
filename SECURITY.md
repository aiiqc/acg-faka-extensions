# 安全说明

## 当前支持边界

当前为 `v0.1.0-preview.1` 首次预发布，套装版本仍为 `0.1.0-dev`，没有稳定版或已承诺的安全响应时限。公开新安装只推荐固定官方 Acg-Faka `3.7.9 / 5120942d2c13ac900d614b09cfd6fbf672b62840`；旧 `3.6.4`、`3.7.0`、`3.7.5` 仅保留历史兼容与回归记录，不构成旧核心的安全维护承诺。

扩展不替代官方核心更新、系统补丁、独立站点身份、备份恢复、后台访问控制或支付风控。官方应用商店的代码安装能力等同代码执行权限；不能把扩展文件哈希检查解释为已攻陷 FPM 下的不可变保证。部署要求见[安装教程](docs/USER_INSTALL.md)。

## 私密漏洞报告

公开仓库已启用并读回核验 GitHub Private Vulnerability Reporting。请使用[私密报告入口](https://github.com/aiiqc/acg-faka-extensions/security/advisories/new)，按 GitHub 提示登录后提交；启用核验不代表已经接收过真实报告，也不承诺响应时限。本项目没有另行公布的安全邮箱。

不要把漏洞细节、利用步骤或秘密发布到公开 issue、讨论或评论。若上述私密入口暂时不可用，可仅请求维护者恢复入口，不附漏洞细节；无法安全联系时先保留本地最小化证据。不要把报告发往猜测的账号或邮箱。

报告应只含受影响组件版本、固定官方 commit、影响与前提、合成环境的最小复现，以及已脱敏的错误码。不得附数据库 dump、真实订单／客户数据、Token、Cookie、商户 KEY、钱包私钥、完整安装回执或原始 HTTP 正文／头；不要对第三方或生产站执行未授权验证。

## 发现风险时

先停止相关写入或保留维护状态，保护日志和准确制品身份，按既有方案核对实际影响。凭据泄露应由站点负责人通过服务方正式渠道轮换；不要把旧秘密贴到报告中。付款、通知或订单结果未知时先对账，不重复执行；回退代码不会撤销已写业务数据，恢复前须核对在途交易与配对备份。

普通错误排查见[排障文档](docs/TROUBLESHOOTING.md)。公开源码采用 MIT 不代表随附第三方媒体可公开再分发；许可边界见 [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md)。

报告政策参考 GitHub 的 [SECURITY.md 指南](https://docs.github.com/en/code-security/how-tos/report-and-fix-vulnerabilities/configure-vulnerability-reporting/add-security-policy)和[私密漏洞报告设置](https://docs.github.com/en/code-security/how-tos/report-and-fix-vulnerabilities/configure-vulnerability-reporting/configure-for-a-repository)，不另建报告系统。
