# 智能货源中心：流程与验收清单

本清单说明产品预期行为和现有测试入口，不是某个真实站点的运行回执。版本、证据层与待发布项见[项目状态](PROJECT_STATUS.md)，安装和设置步骤见[安装教程](USER_INSTALL.md)。

## 约定流程

1. 在“智能货源中心”填写协议、HTTPS 上游与凭据，执行“测试并接入”。成功后复用官方共享货源记录，不另建身份系统。
2. 分析阶段只读，默认 `smart` 智能分类。保留上游真实层级须在首次入库前显式选择 `mirror`，且上游具备对应能力；已有映射不能切换模式。
3. 查看完整分类父链、商品及加价计划，人工确认后才创建入库任务。新商品默认不加价，具体运营比例由安装者确认。
4. worker 有界处理，界面显示进度；暂停/取消在当前单项安全边界生效。取消不是回滚，不自动删除已入库数据。
5. 符合检查点条件的可恢复失败经核对原因后继续；业务、凭据、结构或预算错误不能靠重复点击跳过。只补未入库项，不重复创造已有受管商品。
6. 周期同步只管理扩展导入的商品。`basic` 不自动发现新增商品，须重新分析并确认；完整跟随仅在显式选择的货源范围内，对满足配置与单品校验的扩展受管商品生效，并按规则覆盖运营字段。

镜像能力不满足时不静默降级。旧 `ITEM_DETAIL_FETCH_FAILED` 只表示历史未分类失败，保留人工继续入口不代表已经证明上游恢复；新未知失败 `ITEM_DETAIL_UNKNOWN_FAILED` 不提供继续入口。浏览器草稿不保存商户密钥、Cookie 或 CSRF；只读轮询续期不授权重放写操作，写入结果未知时先核对后台状态。

## 可观察验收

| 场景 | 应观察的行为 | 现有入口 |
|---|---|---|
| 安全接入与身份 | 拒绝非法地址、私网、重定向及错误凭据；成功后复用原生身份，秘密不进入任务或响应 | `tests/local-catalog-source-connector-behavior.php`、`tests/local-catalog-hub-contract.test.mjs` |
| 预览与分类 | 同名不同 ID、跨来源、祖先链及冻结计划分别处理；缺父、环或过限拒绝，不部分替换结果 | `tests/local-catalog-classification-suggester-behavior.php`、`tests/local-planned-category-mapper-behavior.php` |
| 任务与恢复 | 确认幂等、暂停/继续/取消、检查点及容量保护有效；活动和可恢复失败任务不被清理 | `tests/local-catalog-job-state-behavior.php`、`tests/local-catalog-job-worker-behavior.php` |
| 改名与隔离 | smart 受管节点按规则联动；mirror 不加显示别名层、不持续改写上游树，漂移拒绝 | `tests/local-native-category-rename-behavior.php`、`tests/local-catalog-admin-controls-behavior.php` |
| 周期同步 | 字段选择、完整跟随、无重复加价、游标恢复和缺货策略符合配置；错误不突破预算/保护门 | `tests/local-supply-field-selection.php`、`tests/local-supply-sync-resume.php`、`tests/local-supply-config-selection.php` |
| 浏览器交互 | 桌面/移动布局、只读轮询及错误恢复正确；写动作不因轮询失败自动重放 | `tests/local-catalog-hub-ui-runtime.test.mjs`、`tests/local-presentation-browser.mjs` |
| 安装与恢复 | 精确核心指纹、载荷、回执、doctor、restore/reinstall及配置接续分别核验 | `tests/installer-contract.test.mjs`、`tests/install-integration.sh` |

这些入口各有 PHP、数据库、浏览器或隔离环境前置条件，不是一组可随意在生产运行的命令。按[贡献指南](../CONTRIBUTING.md)选择入口，不把真实账号、生产数据或秘密放进夹具。

## 证据边界

- 源码合同和合成夹具只证明对应测试范围，不证明任意上游可用、全量商品正确或客户交易成功。
- 截图展示合成界面，不代替正式登录、真实上游或设备验收。
- 镜像元数据、任务与已入库业务数据需要配对恢复，不能只换旧代码或关闭开关冒充回滚。
- 本公开候选未部署或手工触发真实同步。用户流程结果须独立记录，不能将旧版本反馈转为当前版本 PASS。
- 完整集成、浏览器和约定用户完整测试，以及安装说明、素材许可、秘密检查、贡献指南及实际安全报告方式，须在对应发布范围收口；本地候选准备不等于公开授权。
