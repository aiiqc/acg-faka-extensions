# Acg-Faka Extensions 常见问题与排查

先收集脱敏错误码/诊断编号，再按证据检查服务、网络、权限和配置。不要复制完整原始日志、先改源码、降低安全检查或重复执行写操作。

## 智能货源中心 0.6.9 / 货源同步 1.1.17：先判断当前状态

| 现象 | 正确下一步 |
|---|---|
| 商品分类不到 200，但补齐祖先后超过 200 | 核对两端配套版本；当前完整树独立限 2048，原生组／挂商品分类仍限 200。先核三个计数及全站映射／字节门，不删祖先、不截断、不切 smart 绕过 |
| 镜像预览提示上游不支持或分类树不可用 | 核对源站配套 Hub、启用状态及 registry；缺祖先／权限／结构错误先查明，不回退智能入库或反复确认 |
| 已有映射不能切换分类方式 | 保持原模式；镜像不是旧分类的自动迁移入口，不删映射或手改配置绕过 |
| 镜像模式改后台显示名后分类名称没变 | 这是预期：没有额外别名层，不持续改名／移动／删除／排序；复用保留本站排序 |
| 找不到加价 | 候选在分析前和确认区显示本次加价；核对实际版本，首次默认 0% |
| 刷新后草稿提示恢复 | 只是未确认草稿，核对分类和比例后再确认，不会自动入库 |
| 刚创建任务为 0/0 | worker 通常在上一轮结束约 1 分钟后领取；长时间不动才检查 Catalog timer/扩展开关 |
| 已保存货源但分析没创建 | 刷新现有列表，对该货源分析，不重复新增 |
| 任务可继续但改名被拒绝 | 先继续完成或取消任务，再改名；取消不删除已导入商品 |
| 改名结果未知/恢复记录未完成 | 核对显示名与受管分类；只走插件明确提示的恢复入口，不手工改数据库或另建分类 |
| 确认/保存请求断线 | 先刷新核对实际状态，不自动重复提交 |
| 进度读取暂时失败 | 保留最后已知进度；连续 3 次读取失败后页面停止自动轮询并提示刷新，不代表后台入库失败 |
| 只读进度返回 CSRF 校验失败 | 不等于已证明只是过期。只有旧令牌签名仍能按当前登录会话验证，才可续期并重读一次；换会话/无效签名拒绝，失败时刷新或重新登录，不重放写动作 |
| 旧预览报错 | 普通流程已移除该入口。兼容 API 的 PREVIEW_* 只定位失败阶段，不代表已证实的上游/TLS原因 |

更完整的单一流程及验收条件见 [统一流程与验收清单](CATALOG_WORKFLOW_ACCEPTANCE.md)。候选源码、站点安装版本与真实用户验收分别判断，不能拿旧版零散测试替代本次验收。

### 详情读取失败怎样处理

先看任务是否真的处于「失败」，不要把页面读取失败、worker 空闲退出成功或条目失败计数为 0 当作入库成功。进度和快照已保留也不代表上游已经恢复。

| 诊断类别/错误码 | 含义与下一步 |
|---|---|
| 网络传输：`ITEM_DETAIL_TRANSPORT_FAILED` | 新任务在原有尝试耗尽后记录该件未导入，继续正常商品；旧失败任务仍走原人工继续门 |
| 可重试 HTTP：`ITEM_DETAIL_HTTP_RETRYABLE` | 新任务原有尝试耗尽后记录未导入并继续；不增加外层重试，连续/累计异常过多会停源 |
| 详情兼容提示 | 三个已支持的精确只读详情接口不再仅因 MIME 标签不标准拒绝合法商品。仍须 HTTP 200、严格有界 JSON、协议业务成功、正确详情结构及全部原商品校验；提示不等于失败，也不表示所有 HTTP 200 都可导入 |
| 未取得详情：`ITEM_DETAIL_UNAVAILABLE` | 旧协议解码后的空列表/空商品列表；原解析不能区分空 JSON 对象与空列表，只能确认未取得有效详情，不能证明下架。不清库存、不用目录旧数据补造商品 |
| 单件数据：`ITEM_REMOTE_DATA_INVALID` | 明确的远端普通商品字段格式/范围错误，或合法容器内无效商品叶子；该件不写入，继续其他正常商品。价格/SKU 配置树及 INI 解析不在隔离范围 |
| 详情 JSON：`ITEM_DETAIL_JSON_INVALID` | 仅三个既有详情路径 HTTP 200、cURL 0 后真实 JSON 解码异常，尚未写入该商品：记为未导入并继续，不接受或修补坏数据，不增加自动 HTTP 重试。诊断仅保留数值码与固定类别；depth 指本地解码深度上限，不证明上游语法错误，旧记录无原因显示未记录 |
| 处理结束：`IMPORT_FINISHED_WITH_ISSUES` | 全部快照项已处理，仍有未导入项。核对数量及异常清单，不等于全部成功；处理上游原因后，仅在按钮可用时明确点击「只重试未入库项」，不会自动补处理 |
| 异常过多：`IMPORT_ITEM_FAILURE_LIMIT` | 相邻处理 5 件失败或当前未解决清单达到 100 件，保存触发件并停止货源；不是历史累计 100 件。先核查整体问题，不自动发起补处理 |
| 原因未分类：`ITEM_DETAIL_FETCH_FAILED` | 兼容旧任务；无法据此区分网络、业务或数据问题。可保留人工继续入口，但不能称为已证实的瞬时故障；再次失败应停止并检查 |
| 新的未知失败：`ITEM_DETAIL_UNKNOWN_FAILED` | 本批新失败没有足够安全证据归入可重试类别；不提供人工继续，不自动续跑，先排查；不能借用旧码兼容入口 |
| 商品归一化：`ITEM_DETAIL_NORMALIZATION_FAILED` | 不能据此直接认定上游 MIME 错误。已定位的一种隔离故障是净化缓存子目录为 `0600` 而缺少目录搜索权限；本候选使用目录 `0700`、缓存文件 `0600`，首次净化时仅自动修复此插件已有且 owner 正确的 `HTML/CSS/URI` 目录，遇到链接、非目录或错误 owner 则拒绝。无需手工递归改权限，也不会自动续跑旧失败任务；生产历史响应仍须独立核对 |
| HTTP 拒绝/凭据/业务：`ITEM_DETAIL_HTTP_REJECTED`、`ITEM_DETAIL_CREDENTIALS_INVALID`、`ITEM_DETAIL_BUSINESS_REJECTED` | 不自动重试或继续该失败任务；先核对对应配置与上游业务状态，不反复发送凭据 |
| 历史响应格式：`ITEM_DETAIL_RESPONSE_INVALID` | 不全码开放继续。原有可信旧 MIME 授权路径保留；另仅允许 failed/import、无补处理轮、保存的 JSON 失败诊断恰好绑定 processed（HTTP 200、cURL 0、json_valid=false、有界有效次数）且原快照／加价／进度有效的任务显式继续。保持原失败清单及进度，重读当前尚未处理项一次，不重分析；不伪造历史正文或原因 |
| 预算：`ITEM_DETAIL_BUDGET_EXCEEDED` | 剩余运行时间不足；不自动继续、不通过放宽上限掩盖问题，先检查耗时原因 |
| 其他阶段错误 | 按中文阶段提示处理策略、商品字段、分类或数据库问题；不要把重新分析当作撤销已完成数据 |

「继续原任务」和「只重试未入库项」是两种明确操作：前者从原检查点继续未处理部分，后者只访问可信异常清单中的精确快照索引，不重抓目录、不重跑成功商品。两者复用原分类、快照、加价、货源身份、锁及 revision 校验；升级、刷新和请求结果未知都不会自动提交。补处理单独显示本轮进度，原 processed/total 不倒退、不重复递增；成功补入或确认已存在后，未导入数减少并归入成功/已存在类别，失败则保留准确索引及最新原因。

只有后台核验通过才显示相应按钮；没有按钮不能手工修改 jobs.json、倒退 processed 或删除重建商品。原全量扫描相邻 5 件／当前未解决 100 件停止规则仍有效。显式补查只冻结最多 100 个失败索引，白名单商品级失败不再阻断尾项；轮内已提交项不重做，暂停／中断保持游标与计数，安全／凭据／业务拒绝／预算／数据库和未知写结果仍立即停。

旧 halted 补处理任务的「继续本次补处理」复用 retry_failed，只清旧停止标记，从原清单检查点接续；不重做已处理项、不清历史总账。普通暂停沿原 resume 继续。完整一轮结束且资格成立后，「开始新一轮未入库项补查」才明确建立新清单，绝不自动开始。补查结束而原扫描未完成时保持暂停；明确继续原扫描仍受原扫描阈值限制。旧任务无可信明细时不伪造异常清单。

可选 response_structure 仅描述本次已有响应的类型／数量／必要整数业务码，无新请求、无原文、无名称／URL／凭据。空数组与空对象无法由关联解码区分，旧缺失字段保持缺失。HTTP 200、合法 JSON 或空详情均不能证明已下架。写入成功但检查点未提交的未知窗口须先对账，不能把游标去重解释为网络 exactly-once。

新版仍为 schema 4，兼容读旧记录；不认识新结构摘要和补查状态的旧版可能拒绝读取，因此不能假定双向回滚兼容。开始续跑前先验证新记录读写并确定恢复策略。仅部署期且尚无新状态／业务写入时，可使用完整配对备份恢复；发生新处理后禁止只切旧代码、只恢复旧 jobs 或用部署前进度覆盖新业务结果。应停止、对账、保留最新状态并做兼容前向修复。

MIME 兼容仅作用于已支持的三个只读详情端点。目录、图片、支付和其他端点仍用原规则；HTTPS、证书、公网 DNS/IP 固定、禁代理/跳转、大小/深度/节点/时间限制不变。不从 HTML 提取 JSON、不修补坏 JSON、不用旧目录字段补造详情，也没有面向普通用户的“不安全兼容”开关。

每次客户端调用最多请求 3 次（含首次），不是跨任意进程崩溃恢复的累计保证；基础退避为 500／1000 毫秒，仅可重试传输错误和 HTTP `408/429/502/503/504` 适用。合法 `Retry-After` 会受既有剩余预算约束，容不下等待与下一次尝试就停止；不保证发满 3 次。连接 5 秒、请求 90 秒、单货源 120 秒、整轮 300 秒及 systemd 8 分钟外层上限不变，详情/目录读取空闲上限仍为 30／60 秒。无可信次数时显示「请求次数未记录」；旧明细不可得的失败任务不可续跑，但保留明确取消出口。

日志诊断只保留固定类别、错误码和有界的 HTTP/cURL 数值、耗时、实际尝试数；真实 JSON 解析异常可附成对 `json_error_code`／`json_error`（depth、utf8、syntax、unknown），不保存异常消息。worker 详情事件使用任务哈希。item_failures 仍只存索引、安全码和尝试数，最新诊断不代表每个历史失败的原因。次数 0 表示未记录有效测量，不证明零网络。没有正文不能推断 WAF、登录页或凭据失效；这些内容不能入库，持续异常由既有停止门收束。不要粘贴响应正文/头、真实商品编号、商户信息或秘密。

HTTP522 不在上述自动重试白名单，当前请求按 HTTP 拒绝处理；“不自动重试”不等于“永久故障”，也不能只凭状态码证明是瞬时问题。先核对阶段、同轮来源结果和已提交进度，不能把新的有界诊断当成上游故障已修复。目录／详情／图片摘要是聚合证据，不是每件商品的完整请求流水。

`applied.zero` 表示 zero 动作成功返回，可能含原本已经为零的商品；不等于实际正库存变零数。总量熔断触发且仍有 zero 动作，单独不足以证明显式零独立通道覆盖；必须关联原正库存、当轮明确零及对应落库，并排除其他写入。总量比例也不是显式零比例。

### 经批准的单次详情诊断

`scripts/diagnose-supply-item.php` 只声明内存入口函数，用于已准确授权的一个货源、一个失败商品详情；不独立执行或输出，每个进程最多发送一次请求，不重试、不导入、不继续任务，也不读取数据库、站点配置、快照或写入日志/状态。操作者须先独立核对候选脚本及依赖、官方固定 `app/Util/Str.php` 的 SHA/权限，以及输入与目标失败项的对应关系。只在没有站点 bootstrap、自动加载器或预加载业务类的受信独立 CLI 进程调用。

真实凭据仅允许由本站已审查的服务端只读 PDO reader 在同一进程内存直接供给函数，不得跨进程序列化到 stdin、命令参数、环境变量、终端回显或临时文件。入口文件被 `require_once` 时只声明函数，不运行 CLI、不加载依赖、不改变 PHP 错误设置，也不输出或退出。调用示例中的变量须来自这一受控 reader；本入口不提供或新增数据库/任务读取器，没有已审查的供给路径时应停止：

```php
require_once $diagnosticReleaseRoot . '/scripts/diagnose-supply-item.php';
$report = pikaDiagnoseSupplyItem($verifiedCoreRoot, $sourceFromReadonlyReader, $codeFromVerifiedSnapshot);
```

`source` 只含字符串 `domain/app_id/app_key` 和整数 `type`（0/1/2），`code` 为字符串。函数每个 PHP 进程仅允许调用一次，前置校验失败也消耗该次机会；第二次调用固定返回 `preflight/attempts=0`，不得循环或启动新进程自行重试。函数返回固定报告，不写 stdout，并在 `finally` 恢复调用前的 display/log/error handler 设置。

`coreRoot` 必须是已核实官方源码的规范绝对目录，不使用 symlink。调用仅加载该目录的 `app/Util/Str.php` 及候选的 `SourcePolicy/UpstreamFailure/RunBudget/SafeHttpClient/SharedGateway.php`；不加载业务 bootstrap。调用方须关闭自动 prepend/append，设置独立的操作系统硬超时，并保留既有客户端超时上限。函数仅返回固定 v1 脱敏数组：记录有界状态/MIME 分类、JSON 是否可解析及字段类型，不返回响应原文、头值、域名、商品编号或凭据。前置失败返回 `category=preflight/attempts=0`；得到报告不代表商品有效、兼容问题已解决或可以续跑。超时或结果未知时先核对回执，不能自行再调用。

当前 bundle 是待发布的 `0.1.0-dev` 公开候选，公开下载和私密安全报告渠道尚未配置，见[项目状态](PROJECT_STATUS.md)及[安全说明](../SECURITY.md)。源码契约、合成测试和历史有界隔离不等于本公开候选已通过最终制品、目标站部署或真实交易验收。原生后台／HTTP／真实数据库串联、长期 timer、下游 Merchant API 及支付异常路径须按目标环境独立验证。

## 1. 收集不含秘密的基本信息

```bash
printf 'site=%s\n' "$PIKA_SITE_ROOT"
git -C "$PIKA_SITE_ROOT" rev-parse HEAD
"$PIKA_PHP_BIN" -v
"$PIKA_PHP_BIN" -m | grep -E '^(bcmath|curl|json|mbstring|openssl|pdo_mysql|posix)$'
id "$PIKA_WEB_USER"
sudo "$PIKA_RELEASE_DIR/scripts/doctor.sh" --site-root "$PIKA_SITE_ROOT" --php "$PIKA_PHP_BIN"
```

上例 doctor 用于未安装站；已有安装须加 `--installed`，不能用 preinstall 模式判断既有安装。`bcmath` 只在官方 `3.7.9` 必需，并须另外核对实际 FPM；列出 CLI 模块不代表 FPM 已就绪。

报告问题时可提供脱敏后的版本、commit、错误码和服务状态，但不要提供：数据库密码、商户 KEY、BEpusdt 密钥、Cookie、Token、上游完整 URL 或安装回执内容。公开报告先移除真实站点路径和账号；疑似漏洞按[安全说明](../SECURITY.md)处理。

## 2. `unsupported commit`

原因：目标站点不是支持的官方 commit。

检查：

```bash
git -C "$PIKA_SITE_ROOT" status --short
git -C "$PIKA_SITE_ROOT" rev-parse HEAD
```

公开新安装只推荐以下精确官方基线：

```text
3.7.9  5120942d2c13ac900d614b09cfd6fbf672b62840
```

旧 `3.6.4`、`3.7.0`、`3.7.5` 仅为历史兼容与回归基线，见 [compatibility.json](../compatibility.json)，不是降级建议。不要在有业务数据或未提交修改的站点直接强制切换 commit。准备一个新的官方 checkout，完成数据库和配置迁移评估后再安装；不要公开可能包含凭据的 Git remote URL。

## 3. `compatibility hash mismatch`

原因：`config/app.php` 或五个兼容桥文件与官方目标版本不同，常见于在线更新、历史魔改或残留安装。

检查差异：

```bash
git -C "$PIKA_SITE_ROOT" status --short -- \
  config/app.php \
  kernel/Kernel.php \
  kernel/Helper.php \
  app/Controller/Admin/Api/Config.php \
  app/View/Admin/Footer.html \
  assets/common/js/editor/markdown/editorv2.js
```

不要使用 `git reset --hard` 覆盖现有站点。先备份并确认修改来源；最安全的做法是从官方 commit 建立新的干净站点进行 canary。

如果网站设置的公告在 HTML 源码模式下显示“保存成功”，刷新后却仍是旧内容，这是 Acg-Faka 3.6.4 编辑器的已知问题：保存前会用旧的 Markdown 缓冲区覆盖 Ace HTML 内容。本项目的 3.6.4 兼容桥精确回移了官方 3.6.5 的修复，并让生产后台用独立缓存键加载已修复的编辑器源文件；不要通过直接改数据库或放宽 `runtime/config` 权限绕过。

## 4. `site must be a ... Git checkout`

原因：站点来自 ZIP、Composer 制品、Docker 镜像，或 `.git` 是符号链接。

V0.1 的兼容门要求保留官方非符号链接 `.git` 身份。改用 `git clone` 得到的非容器官方 checkout；不要新建一个伪造的 `.git` 目录。

## 5. release 权限或祖先目录错误

常见错误包含：

- `must be root:root`；
- `must not be group/world writable`；
- `must not have an extended POSIX ACL`；
- `release ancestor`；
- `trusted release path`。

检查：

```bash
namei -om "$PIKA_RELEASE_DIR/scripts/install.sh"
sudo find -P "$PIKA_RELEASE_DIR" -maxdepth 3 -printf '%u:%g %m %p\n' | head -n 100
```

release 必须位于 root 拥有、Web 用户不可写的路径。修复前先确认变量解析为预期的精确目录：

```bash
test "$PIKA_RELEASE_DIR" = "$(realpath -e -- "$PIKA_RELEASE_DIR")"
sudo chown -R root:root "$PIKA_RELEASE_DIR"
sudo chmod -R go-w "$PIKA_RELEASE_DIR"
```

如果祖先目录本身由非 root 拥有、带扩展 ACL 或可被 Web 用户替换，应重新解压到 `/opt/pika-local-extensions/releases/<版本>`，而不是放宽安装器。

如果错误是 `root is replaceable by a non-root identity` 或 `parent is replaceable by a non-root identity`，检查的是目标站点，不是 release。站点根目录、五个兼容桥及其父目录必须是 `root:root`，不能是 `root:<站点组>`：

```bash
sudo stat -c '%U:%G %a %n' \
  "$PIKA_SITE_ROOT" \
  "$PIKA_SITE_ROOT/kernel" \
  "$PIKA_SITE_ROOT/kernel/Kernel.php" \
  "$PIKA_SITE_ROOT/kernel/Helper.php" \
  "$PIKA_SITE_ROOT/app/Controller/Admin/Api" \
  "$PIKA_SITE_ROOT/app/Controller/Admin/Api/Config.php" \
  "$PIKA_SITE_ROOT/app/View/Admin/Footer.html" \
  "$PIKA_SITE_ROOT/assets/common/js/editor/markdown/editorv2.js"
```

不要粗暴地把整个站点改成 world-readable。不可变源码路径使用 `root:root`；数据库配置等秘密文件仍应是 `root:<专用站点组> 0640`；运行目录和公开图片缓存才归专用 Web 用户。

## 6. `--web-user UID/GID is already assigned`

原因：另一站的 LocalExtensions 外部状态已经使用这个 UID 或主 GID。

每站创建并配置独立的系统用户与独立主组，然后确保该站点的专用 PHP-FPM pool 和 scheduler 都使用同一个新身份。不要删除 `/var/lib/pika-local-extensions/sites` 下的其他站点状态来绕过检查。

### 6.1 `dedicated Web identity must have zero processes`

原因：安装或恢复虽然收到了 `--confirm-maintenance`，但 Linux `/proc` 仍发现该站 Web UID 的 real/effective/saved/fs UID 进程。脚本不会替你杀进程，也不会在这种状态下继续 root 文件写入。

保持外部 503，先确认 scheduler、CLI 和该站专用 FPM pool 都已停止，再只读检查：

```bash
sudo pgrep -a -u "$PIKA_WEB_USER" || true
sudo pgrep -af "$PIKA_SITE_ROOT/local-extensions/extensions/(PikaSupplySync/bin/sync.php|PikaCatalogHub/bin/worker.php)" || true
sudo systemctl status '<该站专用FPM服务名>' --no-pager
sudo systemctl list-timers \
  "pika-supply-sync-$PIKA_INSTANCE.timer" \
  "pika-catalog-worker-$PIKA_INSTANCE.timer"
```

必须等前两条都没有本站进程后再重试。若多个站共享同一个 FPM master，只排空本站 pool，不要停止其他站点；也不要用不同用户运行安装器来绕过进程门。

## 7. `external state already exists`

V0.1 安装器是 install-only，不是 update 命令。这个错误通常表示相同规范路径已经安装过，或失败后需要先核对残留。

检查安装状态：

```bash
sudo "$PIKA_RELEASE_DIR/scripts/doctor.sh" \
  --site-root "$PIKA_SITE_ROOT" \
  --installed \
  --php "$PIKA_PHP_BIN"
```

如果 installed doctor 通过，就不要重复安装。若失败，保留错误、外部状态和备份目录，按安装回执执行受控恢复；不要手工删除状态目录。

## 8. 安装成功但后台没有「本地扩展」

依次检查：

1. `doctor.sh --installed` 是否通过；
2. 是否已经重启或安全 reload 该站的专用 PHP-FPM，清除旧 OPcache；
3. 当前登录用户是否是系统管理员；
4. 浏览器请求 `/admin/api/localExtensions/listing` 是否返回 JSON，而不是 Nginx 503、登录页或 HTML 错误；
5. PHP-FPM 与 Nginx 错误日志。

```bash
sudo systemctl status '<专用FPM服务名>' --no-pager
sudo journalctl -u '<专用FPM服务名>' -n 100 --no-pager
sudo tail -n 100 '<Nginx错误日志路径>'
```

本地扩展菜单只管理服务器安装器登记的受信扩展。`PikaBEpusdtAdapter` 属于异次元原生支付适配器，应在「支付管理」中查找；它不会显示在「本地扩展」或应用商店。

如果首次登录后所有后台页面都回到本地使用协议，请先在官方协议页正常完成同意。协议未完成时，官方核心会主动拦截其他后台页面；不要把它当作本地扩展路由故障，也不要修改内核绕过。

## 9. 点击「启动」或保存配置报错

先刷新后台页面更新页面凭证，再核对动作是否已保存成功；不要直接重复提交。`LOCAL_EXTENSIONS_CSRF_INVALID` 只表示 CSRF 校验失败，不证明原因仅是过期。候选的自动续期只覆盖只读任务轮询，且旧令牌签名仍能按当前登录会话验证时，才可最多续期并重读一次；换会话或无效签名拒绝。它不覆盖「启动」、保存配置、确认入库或其他写动作。续期失败、会话失效或变化时须刷新/重新登录。确认系统管理员会话、FPM 用户身份以及外部状态权限：

```bash
sudo "$PIKA_RELEASE_DIR/scripts/doctor.sh" --site-root "$PIKA_SITE_ROOT" --installed --php "$PIKA_PHP_BIN"
sudo find /var/lib/pika-local-extensions/sites -maxdepth 3 -printf '%u:%g %m %p\n'
```

不要把 `/var/lib/pika-local-extensions` 整体改成 `777`。每个站点哈希目录由 root 管理，只有其 `runtime` 应归对应 Web UID/GID，具体模式由安装器创建和验证。

### 9.1 `official runtime directory ...` 或远端图片本地化失败

installed doctor 报 `official runtime directory` 时，表示异次元 3.6.4 自己需要的项目内运行目录发生了缺失、符号链接、所有者、模式或 ACL 漂移。先保持维护状态，只读取元数据：

```bash
sudo stat -c '%U:%G %a %n' \
  "$PIKA_SITE_ROOT/assets/cache" \
  "$PIKA_SITE_ROOT/assets/cache/general" \
  "$PIKA_SITE_ROOT/assets/cache/general/image" \
  "$PIKA_SITE_ROOT/assets/cache/pika-supply-sync" \
  "$PIKA_SITE_ROOT/app/Pay" \
  "$PIKA_SITE_ROOT/app/Plugin" \
  "$PIKA_SITE_ROOT/app/View/User/Theme" \
  "$PIKA_SITE_ROOT/config" \
  "$PIKA_SITE_ROOT/kernel/Install" \
  "$PIKA_SITE_ROOT/kernel/Install/OS" \
  "$PIKA_SITE_ROOT/runtime"
sudo namei -om "$PIKA_SITE_ROOT/assets/cache/general/image"
```

`assets/cache`、`assets/cache/general`、`assets/cache/general/image`、`assets/cache/pika-supply-sync`、`app/Pay`、`app/Plugin`、`app/View/User/Theme` 应为本站专用 Web UID/GID `0755`；`kernel/Install/OS`、`runtime` 应为同一身份 `0750`。`config` 与 `kernel/Install` 应为 `root:<本站 Web GID> 0750`。这些列出的浅层路径都不能是符号链接、不能 group/world write、不能有扩展 ACL；它们的模式要求不因下述 Smarty 后代目录例外而改变。

若错误改为 `official ... tree`、`official mutable config file` 或 `official ... core file`，表示顶层目录看似正确，但子树写契约或 root 管理文件需要核对。不要递归 `chown`：`assets/cache/**`、`runtime/**`、`kernel/Install/OS/**`、官方商店安装的插件/支付/主题子树应由专用 Web 身份拥有并保持 owner 可写；只有 `config/store.php`、`config/mcp.php`、`config/terms` 与 `kernel/Install/Lock` 是位于 root 保护父目录内的精确 Web 文件，模式为 `0600` 或 `0640`。`config/app.php`、`config/database.php`、`app/Pay/Base.php`、`app/Pay/Pay.php`、`app/Pay/Signature.php`、`kernel/Install/Install.sql` 仍由 root 管理。异次元 `kernel/File/File.php` 可能把递归可变子树内的新节点设为 `0777`；固定制品安装器只为精确专用 Web UID/GID 的真实目录或单硬链接普通文件兼容这一模式。官方 Smarty 还会自然生成 `runtime/view` 及其斜线边界后代的 `0771` 目录：这不是普通用户配置错误，安装器仅对该路径内的真实目录增加精确 `0771` 兼容，并在隔离屏障内按原事务规范化为 `0751`。其他 `0777` 节点安装后最多为 `0755`；普通文件或路径外目录的 `0771`，以及 `0666`、`0770` 等模式都不是这一例外。若旧制品拒绝了原生 Smarty 目录，应保留失败现场并使用经过对应验收的新固定制品，不能先把缓存改成 `0755` 或 `0777` 伪造通过。若节点属于 root 或其他身份，或带特殊位、符号链接、扩展 ACL，必须保持维护并调查来源。通过完整快照恢复后重新安装固定制品，让安装器规范化；不要手工混合权限或在已安装站直接重跑 install。

官方清理缓存后，Smarty 可再次原生生成同范围的 `0771` 目录；installed doctor 和 restore 前置验证共用精确例外，仅接受规范 `runtime/view` 本身或其斜线边界后代、真实目录、精确模式及本站专用 Web UID/GID，仍检查无符号链接和 ACL。校验本身不改权限，浅层根和恢复屏障不放宽；`0771` 普通文件、非目标路径、其他身份、特殊位或其他 group/world-write 模式继续拒绝。详见[安装前后权限契约](USER_INSTALL.md#扩展安装准备与安装后的运行权限)。

异次元原生「接入货源」若连续显示 `远端图片下载失败`，而上游图片本身能返回有效图片，优先检查 `assets/cache/general/image`。官方代码会把目录创建或写入失败压缩成同一条提示，并对确定性失败再重试一次。原生页面把批量进度和日志保存在当前浏览器的 `localStorage`，不是服务器后台队列；以后再次打开该页面时可能重新显示旧的完成窗口，不代表服务器刚刚又执行了一轮。应先按 Web 访问日志的请求时间及数据库创建时间判断是否发生新写入。

原生批量入库允许部分成功，所以看到「总计／成功／失败」后不能只重试失败项或直接假设数据库未改变。先停止继续操作，按 `shared_id`、创建时间和本地商品编号做脱敏只读盘点，确认成功项是否与 Pika 的 `PKS1` 商品重复；需要清理时必须先备份并使用精确范围与硬上限。不要反复启动几千项任务，也不要把整个站点或缓存改成 `0777`。整套扩展的正常新货源流程应改用「智能货源中心」，不要再点原生「接入货源」。

V0.1 是 install-only：已安装站不能靠再次执行 `install.sh` 修复该错误，receipt restore 也会先经过同一 installed verifier。正确流程是停止浏览器任务和 scheduler、保持外部 503 并排空本站 FPM/CLI 写入者，然后由本整包部署流程恢复安装前的完整站点与外部状态快照。确认外部状态和 Pika 目标文件已经回到安装前状态后，依次执行：

```bash
sudo "$PIKA_RELEASE_DIR/scripts/doctor.sh" \
  --site-root "$PIKA_SITE_ROOT" \
  --php "$PIKA_PHP_BIN"
sudo "$PIKA_RELEASE_DIR/scripts/install.sh" \
  --site-root "$PIKA_SITE_ROOT" \
  --web-user "$PIKA_WEB_USER" \
  --confirm-maintenance \
  --php "$PIKA_PHP_BIN"
```

第一条必须输出 preinstall `DOCTOR_PASS` 才能执行第二条。若没有安装前完整快照，保持 503、保留 installed doctor 原始错误并停止，交由服务器管理员恢复；不要手工删除外部状态或绕过 verifier。

只有在 installed doctor 已通过后，才可以用专用 Web 身份做一个不含业务数据的写入探针，并立即删除：

```bash
sudo -u "$PIKA_WEB_USER" sh -c \
  'probe="$1/.pika-write-probe-$$"; : > "$probe" && rm -- "$probe"' \
  pika-write-probe "$PIKA_SITE_ROOT/assets/cache/general/image"
```

探针失败时保留完整错误并停止。scheduler 也会拒绝缺失或漂移的 `assets/cache/pika-supply-sync`，不会以 root 替你新建。不要用递归 `chown`/`chmod`、危险 ACL 或手工复制目录绕过 verifier。

## 10. 同步 CLI 提示扩展未启用

错误：

```text
PikaSupplySync LocalExtension 未启用
```

登录系统管理员后台，打开「本地扩展」，先保存 PikaSupplySync 配置，再点击「启动」。CLI 不提供绕过后台状态的参数。

## 11. dry-run 或真实同步返回 `error` / `partial` / `locked` / `held_empty_catalog`

先区分本轮目录前置失败、动作阶段部分完成与安装／配置／程序／业务不变量异常，并核对本轮日志和最近动作阶段 state；不要直接重新执行同步。首次安装仍保持 timer 未安装，其他情况按[安装教程第 11 节](USER_INSTALL.md#11-解除维护前的验收清单)及本次准确授权处理。下面的 dry-run 也会请求上游，只在已确认的诊断目标与轮数／时长上限内执行：

```bash
sudo -u "$PIKA_WEB_USER" "$PIKA_PHP_BIN" \
  "$PIKA_SITE_ROOT/local-extensions/extensions/PikaSupplySync/bin/sync.php" \
  --root="$PIKA_SITE_ROOT" \
  --mode=basic \
  --dry-run
```

检查：

- 智能货源中心是否已经通过异次元官方接口保存该货源；
- 上游根地址是否为公共 HTTPS 443；
- URL 是否误含凭据、query 参数、私网 IP 或保留地址；
- DNS 是否解析到公共地址，TLS 证书是否有效；
- 上游接口是否返回空目录、异常结构、重复 SKU 名称/ID；
- `source_ids` 是否引用真实共享店铺 ID；
- 单轮资源上限、封面缓存上限或批量清零熔断是否触发。

batch 是上限而非每轮保证完成量；`partial` 可含已提交动作，仍须保留来源 `status/applied/failed` 的真实结果。请求诊断仅提供按目录／详情／图片聚合的有界摘要，不是完整商品级追踪；预算数值来自最近既有检查的采样，未采集字段省略。先按失败阶段和不变量判断是否停止，不因 HTTP 数字变化机械卸装，也不能把旧 state 或成功提交的部分当作本轮全部通过。

扩展禁止重定向，并会绑定已验证的公共 DNS 地址。不要通过关闭 TLS 验证、放行私网 URL 或把密钥打印到日志来排障。

`locked` 表示该货源的 CatalogHub 入库或另一轮同步仍持有同一货源锁；先确认旧进程确实结束，再重新运行，不要删除仍被使用的锁文件。`held_empty_catalog` 表示已认证上游突然返回空目录，扩展为防止批量误清零而保持原商品不动；先确认上游目录恢复为合理的非空结果，再从智能货源中心重试分析或重新 dry-run。这两种状态都不是成功验收，不能据此开站、安装 timer 或宣称货源已通过。

### 11.1 智能货源中心接入、分析或后台任务异常

「协议版本」必须按下拉框的真实值选择，不要根据名称自行猜编号：`0` — 异次元(V3.1.2 重构后全新版)、`2` — 异次元(V3.1.1 之前旧版)、`1` — 萌次元(V4.0)；界面默认选中 `0`。

「测试并接入」包含两个连续步骤：先由 Pika 安全连接入口验证 HTTPS 上游并写入异次元原生共享店铺表，再用返回的货源 ID 创建 Pika 分析任务。商户 KEY 只进入本次请求与原生 `shared.app_key`，请求结束时页面清空输入框；它不进入 Pika 状态、快照、响应或日志。

- 提示「货源已保存，但分析任务创建失败」：刷新页面，在「已接入货源」对原记录点击「智能分析」重试。不要重复新增同一货源，也不要再次发送 KEY。
- 提示地址不安全或连接失败：只检查 HTTPS 根地址、证书、公共 DNS、协议版本和上游防火墙。不要改用 HTTP、关闭证书验证、放行私网地址或把 KEY 粘贴进日志。
- 分析失败：DNS 与 TLS 正常不代表已认证商品目录会及时返回；上游无首字节、HTTP 非 200、响应过大、空目录或结构异常都会失败。失败不会创建商品或分类。
- 分类建议不准确：建议器只按上游分类名称确定高置信归属。重点检查低置信、冲突与「其他」，修改映射后再确认；确认之前不会开始入库。
- 浏览器收到 504：不要立刻重复提交商户凭据。先刷新「已接入货源」，确认服务端是否已经保存；再核对本站 Nginx/FPM/PHP 的 150 秒链，避免出现“浏览器失败、服务端已写入”的未知结果：

```bash
sudo nginx -T 2>&1 | grep -nE 'server_name|fastcgi_read_timeout|fastcgi_pass'
sudo systemctl cat '<该站专用FPM服务名>'
sudo '<实际php-fpm二进制>' -tt 2>&1 \
  | grep -E '^\[|request_terminate_timeout|max_execution_time'
```

只调整本站 server 和专用 pool，目标分别是 `fastcgi_read_timeout 150s`、`request_terminate_timeout = 150s`、`php_admin_value[max_execution_time] = 150`。修改后必须先通过 `nginx -t` 与 PHP-FPM `-tt` 再受控 reload；不要全局放宽共享配置。

- 任务一直是 `queued`：确认 `PikaCatalogHub` 与 `PikaSupplySync` 都已启动，并检查 Catalog worker timer、service 与日志：

```bash
sudo systemctl is-active "pika-catalog-worker-$PIKA_INSTANCE.timer"
sudo systemctl show "pika-catalog-worker-$PIKA_INSTANCE.service" \
  -p Result -p ExecMainStatus -p ActiveEnterTimestamp -p InactiveEnterTimestamp
sudo journalctl -u "pika-catalog-worker-$PIKA_INSTANCE.service" -n 100 --no-pager
```

Catalog worker 在上一轮结束约 1 分钟后再次唤醒、每轮最多处理 20 项，没有任务时会快速退出。上游较慢时两次启动的间隔会相应变长。暂停、继续或取消只会在当前单项处理结束后的安全边界生效；上游请求正在进行时可能不会立刻改变状态。取消不是回滚，不会删除已经创建的分类或商品。

后台最多保留 64 条任务。达到上限时只退役可回收的已完成、已取消或不可恢复失败记录，活动任务及仍可人工继续的失败任务不会被删除；先完成或明确取消后再释放容量。如果 worker 反复以 `WORKER_START_FAILED` 退出且没有领取任务，应保持 timer 停用并检查站外状态目录中 `jobs.json` 的所有者/权限及快照目录；不要手工删除 `snapshot_gc`、任务记录或快照文件，持久清理回执必须由受信 JobService 精确收口。

若出现空间不足、快照写入失败或 canary 容量门未通过，先保持外部 503 与两个 timer 停用，按安装教程记录各目标文件系统的 `df -B1`，并用 `du -sb` 复核站点、站外状态与图片缓存。容量评估必须包含 16 MiB × 64 = 1,073,741,824 字节的理论快照上限，再加完整灾备与图片缓存增长余量；数据库备份大小必须另算。容量门不会触发额外自动清理，也不能依赖正常终态轮转来腾空间；不要手工删除任务、`snapshot_gc`、快照、备份或图片。应先扩容或把备份迁移到已验证的独立文件系统，再重新执行容量门。

报告问题时只提供 source ID、任务 ID、失败时间和固定错误文案；不要提供上游 URL、商户 ID、KEY、响应正文或浏览器 Cookie。真实付款、充值与真实订单不属于 CatalogHub 接入/分类/入库流程的验收范围。

## 12. 为什么第一次没有导完全部商品

智能货源中心的初次入库由 Catalog worker 每轮最多处理 20 项，timer 在上一轮结束约 1 分钟后继续下一轮。几千个商品需要跨多轮完成，浏览器可以关闭；不要反复创建相同任务。

PikaSupplySync 的「单货源每批上限」1–500 只控制后续同步，不会把 Catalog worker 的 20 项边界放大。日常建议使用 `basic`：它会更新已由 Pika 管理的商品，但不会自动发现上游以后新增的商品。上游增加新品时，重新执行「智能分析」，确认新增分类映射后再创建入库任务。

## 13. 库存 0 隐藏后没有自动恢复

零库存商品在 CatalogHub 初次入库时也会创建，只是 Pika 主题会隐藏。库存恢复不是实时推送，必须等待下一次成功同步。依次确认：

- 上游接口已经返回大于 0 的库存；
- timer 是 active，最近一次 service 退出成功；
- 商品是 PikaSupplySync 自己导入的 `PKS1...` 商品；
- 管理员没有手工把商品下架；
- 该轮没有触发上游异常、资源上限或清零熔断。

```bash
sudo systemctl list-timers "pika-supply-sync-$PIKA_INSTANCE.timer"
sudo systemctl status "pika-supply-sync-$PIKA_INSTANCE.service" --no-pager
sudo journalctl -u "pika-supply-sync-$PIKA_INSTANCE.service" -n 100 --no-pager
```

Pika 主题只负责隐藏库存 0；扩展不会改商品 `status`。上游补货、下一轮同步成功且商品仍为管理员上架状态后，前台才恢复显示。

## 14. timer active，但 service 显示 inactive

SupplySync 与 Catalog worker service 都是 `Type=oneshot`。任务完成或 worker 发现没有待办任务后，`inactive (dead)` 可以正常；看最近一次退出码和日志，不要要求 service 常驻。

现有 `OnActiveSec` 与 `OnUnitInactiveSec` 按 OR 触发；timer 为 active 不代表业务成功，实际下次触发不能仅从单次运行结束时间推算。详见[两组 systemd 后台服务](USER_INSTALL.md#10-安装两组-systemd-后台服务)，本次不修改 unit 或间隔。

```bash
sudo systemctl is-active "pika-supply-sync-$PIKA_INSTANCE.timer"
sudo systemctl is-active "pika-catalog-worker-$PIKA_INSTANCE.timer"
sudo systemctl show "pika-supply-sync-$PIKA_INSTANCE.service" \
  -p Result -p ExecMainStatus -p ActiveEnterTimestamp -p InactiveEnterTimestamp
sudo systemctl show "pika-catalog-worker-$PIKA_INSTANCE.service" \
  -p Result -p ExecMainStatus -p ActiveEnterTimestamp -p InactiveEnterTimestamp
sudo journalctl -u "pika-supply-sync-$PIKA_INSTANCE.service" -n 100 --no-pager
sudo journalctl -u "pika-catalog-worker-$PIKA_INSTANCE.service" -n 100 --no-pager
```

## 15. timer 安装或移除被拒绝

常见原因：

- instance 不是小写字母/数字/连字符的安全 ID；
- 不是 root；
- release 或 PHP CLI 不是 root 可验证的可信路径；
- 已存在同名但不是本脚本管理的 unit；
- service 仍在运行；
- site-root、web-user 与 unit marker 不一致。

若日志出现 `WorkingDirectory= path is not absolute`，先确认使用的是包含 systemd 路径转义修复的当前制品，并重新核对制品 SHA256；不要手工给 `WorkingDirectory=` 的绝对路径包双引号。安装脚本在写入 unit 前会运行 `systemd-analyze verify`，失败时会自动恢复原 unit 状态。

若脚本以退出码 `70` 报告 `scheduler rollback was incomplete`，不要重启服务器，也不要清理提示的 `/run/pika-scheduler.*` 目录；`/run` 会在重启后丢失。保持对应站点 503、两个 timer 停用，并立即把提示的精确目录复制到 root-only 的持久备份位置后再诊断。不要用宽泛 glob 猜测目录，也不要手工混合旧新 unit。

不要手工覆盖同名 unit。先核对实际文件：

```bash
sudo systemctl cat "pika-supply-sync-$PIKA_INSTANCE.service"
sudo systemctl cat "pika-supply-sync-$PIKA_INSTANCE.timer"
sudo systemctl cat "pika-catalog-worker-$PIKA_INSTANCE.service"
sudo systemctl cat "pika-catalog-worker-$PIKA_INSTANCE.timer"
sudo systemctl status "pika-supply-sync-$PIKA_INSTANCE.service" --no-pager
sudo systemctl status "pika-catalog-worker-$PIKA_INSTANCE.service" --no-pager
```

## 16. Pika 主题没有生效

安装器只复制主题文件，不写数据库。请在异次元原生网站设置中把「PC 商城主题」和「PC 会员中心主题」都选择为 `Pika`，移动端设为跟随并保存，然后清除应用缓存或安全重载专用 FPM，最后使用无痕窗口分别确认商城与会员中心。

如果页面已有 Pika 标记但没有样式、Logo 或脚本，先分别检查源站与 CDN：

```bash
curl -I 'https://<域名>/app/View/User/Theme/Pika/Assets/pika.css?theme=1.1.6'
curl -I 'https://<域名>/app/View/User/Theme/Pika/Assets/pika.js?theme=1.1.6'
curl -I 'https://<域名>/app/View/User/Theme/Pika/Assets/topfans-logo.png'
curl -I 'https://<域名>/app/View/User/Theme/Pika/Assets/topfans-bg-poster.jpg'
curl -I 'https://<域名>/app/View/User/Theme/Pika/Assets/topfans-bg.mp4'
```

这些请求都应返回 `200` 及对应的 `text/css`、JavaScript、`image/png`、`image/jpeg` 与 `video/mp4` 类型。若源站返回 `404`，请把下面的精确静态规则放在通用 `/app` 拒绝规则之前，并确认没有 `location ^~ /app` 抢先匹配：

Pika 的 CSS/JS 使用主题版本作为查询参数，不沿用异次元核心的 `APP_VERSION`。若 HTML 仍引用旧的 `?theme=`，说明模板或页面缓存未刷新；先核对当前固定制品与主题版本，再安全重载该站专用 FPM。不要用全站 CDN purge 掩盖版本不一致。

```nginx
location ~* ^/app/View/User/Theme/Pika/Assets/.+\.(?:css|js|svg|png|jpe?g|mp4)$ {
    try_files $uri =404;
    access_log off;
    expires 7d;
    add_header X-Content-Type-Options "nosniff" always;
}
```

保留直接 PHP 与通用 `/app` 拒绝规则；不要为了修复主题而开放整个主题目录。若源站已是 `200`、CDN 仍返回旧 `404`，先等待短期错误缓存自然过期，再按 CDN 提供商的精确 URL 清缓存能力处理。

如果 `doctor.sh --installed` 不通过，不要通过手工复制主题文件修补；先处理完整性问题。

## 17. PikaBEpusdtAdapter 与应用商店、支付排查

### 应用商店中找不到 BEpusdt

这是预期行为。`PikaBEpusdtAdapter` 随本仓库的服务器安装器写入异次元原生支付目录，安装后应在「支付管理」中出现，不需要登录应用商店，也不会显示在应用商店或「本地扩展」。

如果「支付管理」中也没有看到它，先运行 installed doctor，并确认专用 PHP-FPM 已重启以清除 OPcache。不要把适配器目录手工复制到另一个站点。compatibility-first 安装下，`app/Pay` 目录本身应精确归本站专用 Web UID/GID、模式 `0755`；不要递归改写内部适配器、增加 group/world write 或改成 `0777`。

### 保存配置后，支付拨测或创建交易被拒绝

逐项检查：

- `gateway_origin` 只能是本机回环 HTTP origin，例如 `http://127.0.0.1:<端口>`；不能使用公网主机、路径、query、userinfo 或 HTTPS 反向代理地址。
- `checkout_origin` 必须是公开可访问的小写 HTTPS origin，且 V0.1 必须与 `merchant_origin` 完全相同；不能使用 `127.0.0.1`、私网 IP、显式端口、路径、query 或 userinfo。
- `merchant_origin` 必须是当前异次元站点唯一的规范 HTTPS origin。它与实际 callback/return URL 不一致时适配器会故意拒绝创建交易，避免 Host 注入、开放重定向与签名回调外送。
- 异次元原生「自定义支付回调域名」对应 `callback_domain`。只要启用了 Pika BEpusdt，它就必须明确设置，并与 `merchant_origin`、`checkout_origin` 逐字相同。克隆旧数据库或更换域名时，该值不会自动跟随新域名；旧值不为空时异次元也不会回退到当前访问域名。
- `fiat` 必须与后端实际接受的法币代码一致。

当前适配器同时支持异次元 3.6.4 与 3.7.0 的商品订单 callback/return 和余额充值 callback/return；两版原生支付接口固定文件相同。若商品结账能打开、余额充值却提示「BEpusdt 配置不可用」，先确认安装的是 `PikaBEpusdtAdapter 0.1.1` 或更新版本，再运行 installed doctor。不要只用后台支付拨测替代余额充值路径；拨测覆盖商品订单形态，不能证明充值回跳形态已经兼容。

installed doctor 报 `BEpusdt origin verification failed` 时，不要修改 Token、钱包或 RPC。先保持支付接口停用，分别在异次元原生其他设置与该支付配置档中核对 `callback_domain`、`merchant_origin`、`checkout_origin`；通过后台正常保存配置后重跑 doctor。该检查不会读取 Token，也不会在错误信息中输出域名或配置值。

API Token 和站点命名空间不在后台表单或异次元数据库中。若提示凭据缺失，请以 root 从受信 release 运行一次：

```bash
sudo /opt/pika-local-extensions/releases/<版本>/scripts/configure-bepusdt.sh \
  --site-root /opt/acg-faka \
  --namespace demo1
```

命令会从终端静默读取 Token，并以 `root:<本站 Web 组> 0640` 写到站点外的 `secrets` 目录。它不会显示 Token，也不会覆盖已存在的凭据。不要用 `echo TOKEN | ...` 或把 Token 放进参数、环境变量、聊天记录和 Shell 历史。

### 能创建订单，但不能打开结账页或不能到账

这不属于 PikaSupplySync。适配器只是异次元到 BEpusdt 后端的连接器，依次检查：

- BEpusdt 后端是否运行，API 是否只在异次元主机的本机回环监听；
- `gateway_origin`、`checkout_origin`、`merchant_origin` 与站点外 API Token 是否分别匹配后端、公开入口和当前异次元站点；
- 当前支付方式是否为支持的 `usdt.bep20`、`tron.trx` 或 `usdt.trc20`；
- 钱包地址、链、RPC 与监听状态；
- 公开结账域名的 DNS、TLS、Nginx、时间同步和防火墙；Nginx 只应使用仓库 `packaging/nginx` 的白名单路由，不能反代整个 BEpusdt 端口；
- 后端日志是否收到创建订单与链上到账事件；
- 异次元回调是否返回成功，以及订单号、金额和币种是否一致。

多站共用一套 BEpusdt 时，不要在数据库中手工改写适配器生成的站点命名空间，也不要把一个站的完整支付配置文件复制到另一个站。先用每站独立的受控小额订单确认跳转和回调归属正确。

安装验证、页面可见性、API 健康检查都不能证明真实支付成功。真实付款、充值和商品订单必须由站长本人使用受控钱包和明确金额上限执行。不要在公开工单里粘贴 API Token、钱包私钥、Cookie、完整回调签名或可复用的付款链接。

## 18. 需要恢复时为什么不能直接运行 restore

restore 是文件级恢复，不会自动停止业务，也不会恢复数据库、主题选择或支付配置。运行前必须：

- 后台停用扩展并切回官方主题；
- 在「支付管理」中停用 `PikaBEpusdtAdapter`；
- 同时核对异次元订单/充值与 BEpusdt，确认没有 pending 或尚未结清的在途交易；
- 建立数据库快照；
- 删除本站 scheduler；
- 外部 503 生效；
- 专用 FPM 和所有写入者停止；
- 没有 SupplySync CLI、CatalogHub worker、run lock 或 source lock。

排空检查必须同时匹配两个 CLI 入口，不能只检查旧的同步进程：

```bash
sudo pgrep -af "$PIKA_SITE_ROOT/local-extensions/extensions/(PikaSupplySync/bin/sync.php|PikaCatalogHub/bin/worker.php)" || true
```

缺少任一条件都应保持维护状态，先完成条件再恢复。restore 会再次从 `/proc` 验证专用 Web UID 零进程，临时收回 7 个官方可写浅层根，并在全部 root 写入结束后才按原 compatibility-first 契约交还；这不是仅凭确认参数跳过的检查。成功后的外部状态会移到 `/var/lib/pika-local-extensions/archives`，不要立即删除；等数据库和站点恢复验证完成并超过保留窗口后再处理。
