!function () {
    'use strict';

    const root = document.getElementById('local-extensions-root');
    if (!root) return;
    const csrf = String(root.dataset.csrf || '');
    let alive = true;
    const syncFields = ['sync_name', 'sync_cover', 'sync_description', 'sync_price', 'sync_inventory', 'sync_options'];
    const legacySelection = extension => extension.id === 'PikaSupplySync'
        && syncFields.every(key => !Object.prototype.hasOwnProperty.call(extension.values || {}, key));

    const node = (tag, className, text) => {
        const element = document.createElement(tag);
        if (className) element.className = className;
        if (text !== undefined) element.textContent = String(text);
        return element;
    };

    const post = async (url, data) => {
        const body = new URLSearchParams({...data, csrf_token: csrf});
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
            body: body.toString()
        });
        const contentType = String(response.headers.get('content-type') || '').toLowerCase();
        if (!contentType.includes('application/json')) {
            throw new Error('服务暂时不可用，请刷新页面后重试');
        }
        let payload;
        try {
            payload = await response.json();
        } catch (_) {
            throw new Error('服务返回格式异常，请刷新页面后重试');
        }
        if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
            throw new Error('服务返回格式异常，请刷新页面后重试');
        }
        if (!response.ok || Number(payload.code) !== 200) {
            throw new Error(String(payload.msg || '请求失败'));
        }
        return payload;
    };

    const announceError = error => {
        if (!alive) return;
        if (window.message && typeof window.message.error === 'function') {
            window.message.error(error.message || '请求失败');
        } else {
            root.replaceChildren(node('div', 'alert alert-danger', error.message || '请求失败'));
        }
    };

    const createSetting = (setting, extension) => {
        const wrapper = node('div', 'local-extension-setting');
        const label = node('label', 'form-label', setting.label);
        label.htmlFor = `local-extension-${extension.id}-${setting.key}`;
        let input;
        if (setting.type === 'select') {
            input = node('select', 'form-select');
            (setting.options || []).forEach(option => {
                const item = node('option', '', option.label);
                item.value = String(option.value);
                input.append(item);
            });
        } else {
            input = node('input', setting.type === 'checkbox' ? 'form-check-input' : 'form-control');
            input.type = setting.type === 'number' ? 'number' : setting.type;
            if (setting.type === 'number') {
                input.min = String(setting.min);
                input.max = String(setting.max);
            }
            if (setting.type === 'text' || setting.type === 'password') {
                input.minLength = Number(setting.min || 0);
                input.maxLength = Number(setting.max || 4096);
            }
        }
        input.id = label.htmlFor;
        input.dataset.settingKey = setting.key;
        input.dataset.settingType = setting.type;
        input.required = Boolean(setting.required && setting.type !== 'password');
        const value = extension.values?.[setting.key];
        if (setting.type === 'checkbox') input.checked = legacySelection(extension) && syncFields.includes(setting.key) ? true : Boolean(value);
        else if (setting.type !== 'password' && value !== undefined) input.value = String(value);
        if (setting.type === 'password' && extension.password_configured?.[setting.key]) {
            input.placeholder = '已配置；留空表示保留原值';
        }
        wrapper.append(label, input);
        if (extension.id === 'PikaSupplySync' && setting.key === 'follow_upstream_config') {
            const help = node('p', 'text-muted mb-0', '两份名单均填写共享店铺 ID，以英文逗号分隔；参与同步来源不会扩大完整跟随范围。上游变更/删除将覆盖跟随名单内商品 config 中的本地手工修改。只有价格与规格均生效时才执行；关闭任一项会暂停跟随，但不会取消此选择。关闭完整跟随不会恢复已覆盖或删除的本地内容。');
            help.id = `${input.id}-help`;
            input.setAttribute('aria-describedby', help.id);
            wrapper.append(help);
        }
        return wrapper;
    };

    const settingsForm = extension => {
        const form = node('form', 'local-extension-settings mt-4');
        if (extension.id === 'PikaSharedAccess') {
            form.append(node('p', 'text-muted mb-0', '先保存名单再启动。每项填写本站商户账号ID@对应服务器出口IP，使用英文逗号分隔；同账号多个出口分别填写配对。不支持域名、端口、CIDR或通配符，空名单启用后拒绝全部共享接入。只检查服务器确认的来源地址，不读取客户端转发头；代理来源须由站点管理员正确配置。'));
        }
        if (extension.id === 'PikaSupplySync') {
            form.append(node('p', legacySelection(extension) ? 'alert alert-warning mb-0' : 'text-muted mb-0',
                legacySelection(extension)
                    ? '尚未确认六项同步选择：当前仍按旧规则运行，下方勾选只是待保存选择。首次保存后才按六项与商品级开关共同生效；旧规则中配置同步可能同时更新规格价格。'
                    : '六项只控制后续周期更新，不影响首次入库。未勾字段保留本地，全部不勾不更新商品。'));
            form.append(node('p', 'text-muted mb-0', '名称、图片、说明、规格仍受单品「远端配置参数同步」限制；价格受「远端价格同步」限制，库存受数量同步限制。勾选不代表绕过单品开关。规格无法精确匹配价格时保留相关本地内容并提示待确认。保存不会入库或开启定时器。'));
        }
        (extension.settings || []).forEach(setting => form.append(createSetting(setting, extension)));
        const follow = form.querySelector('[data-setting-key="follow_upstream_config"]');
        const sources = form.querySelector('[data-setting-key="follow_upstream_config_source_ids"]');
        const validateFollowScope = () => {
            if (extension.id !== 'PikaSupplySync' || !follow || !sources) return;
            const ids = sources.value.trim() === '' ? [] : sources.value.split(',');
            const valid = ids.length > 0 && ids.length <= 100 && sources.value.length <= 1024
                && ids.every(id => /^\d+$/.test(id.trim()) && !/^0+$/.test(id.trim()));
            sources.setCustomValidity(follow.checked && !valid
                ? '完整跟随配置需要明确填写 1 至 100 个共享店铺 ID，以英文逗号分隔正整数，不能留空。' : '');
        };
        if (extension.id === 'PikaSupplySync' && follow && sources) {
            follow.addEventListener('change', validateFollowScope);
            sources.addEventListener('input', validateFollowScope);
        }
        const submit = node('button', 'btn btn-sm btn-primary align-self-start', '保存配置');
        submit.type = 'submit';
        form.append(submit);
        form.addEventListener('submit', async event => {
            event.preventDefault();
            validateFollowScope();
            if (!form.reportValidity()) return;
            const values = {};
            form.querySelectorAll('[data-setting-key]').forEach(input => {
                const key = input.dataset.settingKey;
                values[key] = input.dataset.settingType === 'checkbox' ? input.checked : input.value;
            });
            submit.disabled = true;
            try {
                await post('/admin/api/localExtensions/saveSettings', {
                    id: extension.id,
                    settings_json: JSON.stringify(values)
                });
                await load();
                if (window.message?.success) window.message.success('配置已保存');
            } catch (error) {
                announceError(error);
            } finally {
                submit.disabled = false;
            }
        });
        return form;
    };

    const syncStatusCard = initial => {
        const section = node('section', 'local-sync-status mt-4');
        section.setAttribute('aria-label', '货源同步运行记录');
        const title = node('div', 'd-flex justify-content-between align-items-center gap-2');
        const refresh = node('button', 'btn btn-sm btn-light-primary', '刷新记录');
        refresh.type = 'button';
        title.append(node('h5', 'mb-0', '同步运行记录'), refresh);
        const content = node('div', 'local-sync-status__content');
        const warning = node('p', 'text-warning mb-0');
        warning.setAttribute('role', 'status');
        section.append(title, node('p', 'text-muted mt-2',
            '调度状态：未核实；当前是否正在运行：未核实。扩展开关不代表定时器状态；以下为历史记录，不是实时进度。'), warning, content);
        const count = value => Number.isInteger(value) && value >= 0 && value <= 50000 ? String(value) : '未记录';
        const object = value => value && typeof value === 'object' && !Array.isArray(value);
        const measured = (value, min, max) => Number.isInteger(value) && value >= min && value <= max ? String(value) : '未取得';
        const stages = {catalog: '目录', detail: '详情', image: '图片'};
        const categories = {none: '请求完成', transport: '网络传输失败', http_retryable: '上游暂不可用',
            http_rejected: 'HTTP 请求被拒绝', credentials: '凭据校验失败', business: '业务返回失败',
            content_type: '响应类型异常', json: 'JSON 无效', schema: '响应结构异常', response_size: '响应超过大小上限',
            budget: '请求预算耗尽', item_unavailable: '商品不可用', item_invalid: '商品详情无效', unknown: '原因未分类'};
        const remoteReasons = {unknown: '原因未分类', merchant_unknown: '商户不存在', signature_rejected: '签名被拒绝',
            code_missing: '缺少商品编号', not_found: '商品不存在', not_shared: '商品未共享', off_shelf: '商品已下架',
            waf_rejected: '请求被防护规则拒绝', upstream_unavailable: '上游服务不可用'};
        const shapes = {missing: '缺字段', null: 'null', boolean: '布尔值', number: '数字', string: '字符串',
            list: '列表', object: '对象', empty_array_or_object: '空数组或对象'};
        const messages = new Set([
            '规格或价格变更待确认：无法精确匹配的部分保留本地，其他已选字段按单品开关处理',
            '图片未刷新：已保留原图，其他字段仍按有效开关和安全门处理',
            '图片配额已耗尽：保留原图，其他有效字段继续按时间和文本预算处理',
            '单货源预算已耗尽', '本轮预算已耗尽', '远端返回业务失败', '远端凭据验证失败',
            '远端商品不可用', '远端商品详情无效', '远端 HTTPS 请求失败', '同步失败，原因未分类',
            '货源同步失败，未执行商品写入',
        ]);
        const mapped = (labels, value, fallback = '未取得') => typeof value === 'string' && Object.hasOwn(labels, value) ? labels[value] : fallback;
        const renderDiagnostic = (label, diagnostic, includeAttempts = false) => {
            const block = node('div', 'local-sync-diagnostic mb-2');
            if (!object(diagnostic)) return block;
            const structure = object(diagnostic.response_structure) ? diagnostic.response_structure : {};
            block.append(node('p', 'mb-0', `${label}：${mapped(stages, diagnostic.stage, '阶段未取得')}；${mapped(categories, diagnostic.category, '原因未分类')}`),
                node('p', 'text-muted mb-0', `HTTP ${measured(diagnostic.http_status, 100, 599)}；业务码 ${measured(structure.business_code, -999999, 999999)}；cURL ${measured(diagnostic.curl_code, 0, 999)}；请求耗时 ${measured(diagnostic.elapsed_ms, 0, 480000)} ms；尝试 ${measured(diagnostic.attempts, 0, 3)} 次；接收 ${measured(diagnostic.received_bytes, 0, 16842752)} 字节。`));
            if (Object.hasOwn(diagnostic, 'remote_reason')) {
                block.append(node('p', 'text-warning mb-0', `上游返回的声明，未核实根因：${mapped(remoteReasons, diagnostic.remote_reason, '原因未分类')}。`));
            }
            if (object(diagnostic.response_structure)) {
                block.append(node('p', 'text-muted mb-0', `数据形状 ${mapped(shapes, structure.data_type)}；数据数量 ${measured(structure.data_count, 0, 10000)}；首个 children ${mapped(shapes, structure.first_children_type)}／${measured(structure.first_children_count, 0, 10000)}；首项 ${mapped(shapes, structure.first_item_type)}。`));
            }
            if (object(diagnostic.timings_ms)) {
                const timing = diagnostic.timings_ms;
                block.append(node('p', 'text-muted mb-0', `累计毫秒：DNS ${measured(timing.dns, 1, 480000)}；连接 ${measured(timing.connect, 1, 480000)}；TLS ${measured(timing.tls, 1, 480000)}；首字节 ${measured(timing.first_byte, 1, 480000)}；总计 ${measured(timing.total, 1, 480000)}。`));
            }
            if (includeAttempts && Array.isArray(diagnostic.attempt_history)) {
                diagnostic.attempt_history.slice(0, 3).forEach((attempt, index) => {
                    if (object(attempt)) block.append(renderDiagnostic(`第 ${index + 1} 次尝试`, attempt));
                });
            }
            return block;
        };
        const diagnostics = entry => {
            const rows = Array.isArray(entry.errors) ? entry.errors.slice(0, 20).filter(object) : [];
            const requests = object(entry.request_diagnostics) ? entry.request_diagnostics : {};
            const hasRequests = Object.keys(stages).some(stage => object(requests[stage]));
            const block = node('details', 'local-sync-diagnostics mt-2');
            block.append(node('summary', '', `诊断事件总数 ${measured(entry.error_total, 0, 20000)}；展示 ${rows.length} 条（与失败商品数不同）`));
            if (typeof entry.phase === 'string') {
                block.append(node('p', 'text-muted mb-1', `本轮最后记录位置：${mapped({preflight: '前置检查', catalog: '目录读取', planning: '批次规划', actions: '商品处理'}, entry.phase)}。`));
            }
            if (entry.errors_truncated === true || (Number.isInteger(entry.error_total) && entry.error_total > rows.length)) {
                block.append(node('p', 'text-warning mb-1', '记录已截断或有不可读条目，最多展示 20 条；未展示的条目不能推断原因。'));
            }
            if (rows.length === 0 && (!Number.isInteger(entry.error_total) || entry.error_total > 0)) {
                block.append(node('p', 'text-muted mb-1', '未取得逐项诊断，不能从失败数推断原因。'));
            }
            if (rows.length > 0 || hasRequests || object(entry.failure_diagnostic)) {
                block.append(node('p', 'text-muted mb-1', 'HTTP 200 不代表业务成功。计时各值从该次 curl 开始累计，不是独立阶段耗时；DNS 仅为 libcurl 固定解析阶段，不含连接策略在 curl 外部执行的 DNS 查询。缺失表示未取得。'),
                    node('p', 'text-muted mb-1', '最近记录不是平均值、p95 或完整请求历史，也不是商品覆盖率或实时端到端进度。'));
            }
            rows.forEach((row, index) => {
                const identity = typeof row.code_hash === 'string' && /^[a-f0-9]{12}$/.test(row.code_hash)
                    ? `商品哈希 ${row.code_hash}` : '货源级记录／商品哈希未取得';
                block.append(node('p', 'mb-1', `${index + 1}. ${identity}：${messages.has(row.message) ? row.message : '具体原因未记录'}`));
                if (object(row.diagnostics)) block.append(renderDiagnostic('本项观测', row.diagnostics));
            });
            if (object(entry.failure_diagnostic)) block.append(renderDiagnostic('本轮最后失败观测', entry.failure_diagnostic));
            Object.keys(stages).forEach(stage => {
                const request = requests[stage];
                if (!object(request)) return;
                block.append(node('p', 'mb-1', `${stages[stage]}请求次数 ${measured(request.count, 0, 10000)}`));
                if (object(request.last)) block.append(renderDiagnostic('最近一次请求', request.last, true));
                if (object(request.last_failure)) block.append(renderDiagnostic('最近一次失败请求', request.last_failure, true));
            });
            return block;
        };
        const record = (label, entry) => {
            const block = node('div', 'local-sync-record');
            if (!entry || typeof entry !== 'object') {
                block.append(node('p', 'mb-0', `${label}：未找到可读记录，不代表从未执行。`));
                return block;
            }
            const labels = {ok: '批次完成', partial: '部分完成／有失败或受限', error: '本轮失败',
                locked: '未执行：货源锁被占用', held_empty_catalog: '未执行：空目录安全拦截'};
            const applied = entry.applied || {};
            const selectedUnknown = [entry.planned_held_unknown, applied.held_unknown]
                .some(value => Number.isInteger(value) && value > 0 && value <= 10000);
            const status = entry.status === 'ok' && selectedUnknown ? 'partial' : entry.status;
            let outcome = labels[status] || '结果未核实';
            if (status === 'ok' && entry.planned === 0) outcome = '本批无动作，不代表全部商品已同步';
            if (entry.kind === 'actual' && status === 'ok' && entry.planned !== 0
                && ['sync', 'import', 'zero'].every(key => Number.isInteger(applied[key]) && applied[key] === 0)) {
                outcome = '本批未保存商品（可能被单品开关或安全规则跳过）';
            }
            const mode = ['basic', 'full'].includes(entry.mode) ? entry.mode : '模式未记录';
            const time = typeof entry.recorded_at === 'string' && entry.recorded_at.length <= 40
                ? entry.recorded_at : '时间未记录';
            const zone = entry.timezone === 'UTC' ? 'UTC' : '历史站点时间，时区未记录';
            block.append(node('p', 'mb-1', `${label}：${outcome}`),
                node('p', 'text-muted mb-1', `${time}（${zone}） · ${mode}${entry.origin === 'state' ? ' · 来自每源历史状态，日志可能已截断' : ''}`));
            block.append(node('p', 'mb-0', `计划 ${count(entry.planned)}；同步保存 ${count(applied.sync)}；新建 ${count(applied.import)}；库存清零 ${count(applied.zero)}；未知库存保护 ${count(applied.held_unknown)}；失败／待确认 ${count(entry.failed)}`));
            if (object(entry.field_sync)) {
                const fields = entry.field_sync;
                const fieldCount = key => Number.isInteger(fields[key]) && fields[key] >= 0 && fields[key] <= 500
                    ? String(fields[key]) : '未知';
                block.append(node('p', 'mb-0', `常规计划动作 ${fieldCount('trade_planned')}；非图片保存 ${fieldCount('noncover_saved')}；媒体已复查 ${fieldCount('media_attempted')}／${fieldCount('media_planned')}；已刷新 ${fieldCount('media_refreshed')}；失败 ${fieldCount('media_failed')}；未完成 ${fieldCount('media_deferred')}`),
                    node('p', 'text-muted mb-0', '常规与媒体计划动作可重叠同一商品。非图片保存按不同商品计数，可能来自媒体首次详情，不代表价格、库存、规格全部成功；媒体刷新也不代表六项字段全部成功。媒体已复查表示动作已开始；未完成仅指本批已选媒体，含配额、时间或安全门限制，不是全目录积压，也未建立补查任务。'),
                    node('p', 'text-muted mb-0', `图片配额影响：${mapped({none: '本批未触发限制', source: '本货源媒体受限', round: '本轮媒体受限'}, fields.image_quota_scope, '未知')}。图片配额不等于时间预算耗尽；记录不代表完整覆盖或时限保证。`));
            } else {
                block.append(node('p', 'text-muted mb-0', '字段分层：未记录；非图片保存与媒体复查结果未知。'));
            }
            if (selectedUnknown || (Number.isInteger(entry.catalog_unknown) && entry.catalog_unknown > 0 && entry.catalog_unknown <= 10000)) {
                block.append(node('p', 'text-warning mb-0', `目录库存未知 ${count(entry.catalog_unknown)} 项（整份目录）；本批选中未知库存 ${count(entry.planned_held_unknown)} 项。保护计数包含详情转为未知的项；未知库存项保留本地，不清零、不保存商品。`));
            }
            const diagnostic = entry.catalog_diagnostic;
            if (entry.status === 'error' && diagnostic && typeof diagnostic === 'object' && !Array.isArray(diagnostic)) {
                const reasons = {response_size: '目录超过 16 MiB 安全上限；缩小批量不会减少整份目录大小',
                    schema: '目录响应结构不正确', credentials: '目录认证失败',
                    transport: '目录传输失败', http_retryable: '目录服务暂不可用', http_rejected: '目录请求被拒绝',
                    business: '目录业务校验失败', content_type: '目录响应类型不正确', json: '目录 JSON 无效',
                    budget: '目录请求预算已用尽'};
                const reason = Object.hasOwn(reasons, diagnostic.category) ? reasons[diagnostic.category] : '目录错误类别未记录';
                const observation = (key, min, max) => Number.isInteger(diagnostic[key])
                    && diagnostic[key] >= min && diagnostic[key] <= max ? String(diagnostic[key]) : '未取得';
                block.append(node('p', 'text-warning mb-0', `目录请求诊断：${reason}。本货源本轮未执行商品写入。`),
                    node('p', 'text-muted mb-0', `HTTP ${observation('http_status', 100, 599)}；cURL ${observation('curl_code', 0, 999)}；耗时 ${observation('elapsed_ms', 0, 480000)} ms；尝试 ${observation('attempts', 1, 3)} 次。HTTP 状态不代表同步成功。`));
            }
            if (Number.isInteger(entry.selection_held) && entry.selection_held > 0) block.append(node('p', 'text-warning mb-0', `规格或价格变更待确认 ${entry.selection_held} 项：无法精确匹配的部分保留本地，计入部分处理；没有建立自动补查任务。`));
            if (entry.mass_zero_fuse === true) block.append(node('p', 'text-warning mb-0', '批量清零熔断已触发，部分清零动作被拦截。'));
            if (Number.isInteger(applied.held_race) && applied.held_race > 0) block.append(node('p', 'text-warning mb-0', `目录与详情库存不一致，暂缓 ${applied.held_race} 项。`));
            if (entry.failed > 0 || entry.status === 'error' || entry.error_total > 0 || entry.errors_truncated === true
                || (Array.isArray(entry.errors) && entry.errors.length > 0) || object(entry.failure_diagnostic)
                || (object(entry.request_diagnostics) && Object.keys(stages).some(stage => object(entry.request_diagnostics[stage])))) {
                block.append(diagnostics(entry));
            }
            return block;
        };
        const fill = status => {
            content.replaceChildren();
            if (!status || status.availability !== 'available' || !Array.isArray(status.sources)) {
                content.append(node('p', 'text-warning', '运行记录暂不可读；请联系管理员核查，不能据此判断同步已停止或正常。'));
                return;
            }
            content.append(node('p', 'text-muted', '最多展示 100 个货源的最近可读历史，不代表当前启用名单。同步保存次数不是价格、库存等字段的真实变化数；预演会读取上游，但不写商品。'));
            if (status.incomplete) content.append(node('p', 'text-warning', '部分记录缺失、不可读或超出展示范围；下方不是完整运行历史。'));
            if (status.sources.length === 0) content.append(node('p', '', '未找到可读记录，不代表从未执行。请先由管理员核对定时器和日志。'));
            status.sources.slice(0, 100).forEach(source => {
                if (!Number.isInteger(source?.source_id) || source.source_id < 1) return;
                const details = node('details', 'local-sync-source');
                details.append(node('summary', '', `货源 #${source.source_id}`));
                details.append(record('日志中的最近实际模式记录', source.actual));
                if (source.saved_batch) details.append(record('最近持久化批次（与日志分列，不推断先后）', source.saved_batch));
                if (source.preview) details.append(record('最近只读预演记录（不写商品）', source.preview));
                if (source.unknown) details.append(record('历史记录（实际执行／预演类型未记录）', source.unknown));
                content.append(details);
            });
        };
        refresh.addEventListener('click', async () => {
            refresh.disabled = true;
            warning.textContent = '';
            try {
                const payload = await post('/admin/api/localExtensions/listing', {});
                if (!alive) return;
                const list = payload.data?.list;
                fill(Array.isArray(list) ? list.find(extension => extension.id === 'PikaSupplySync')?.sync_status : null);
            } catch (_) {
                if (alive) warning.textContent = '刷新失败，保留上次读取的历史记录；请稍后再试。';
            } finally {
                refresh.disabled = false;
            }
        });
        fill(initial);
        return section;
    };

    const render = extensions => {
        const grid = node('div', 'row g-5');
        extensions.forEach(extension => {
            const column = node('div', 'col-12 col-xl-6');
            const card = node('section', 'local-extension-card');
            const top = node('div', 'd-flex justify-content-between gap-4 align-items-start');
            const heading = node('div');
            heading.append(
                node('h4', 'mb-1', extension.name),
                node('div', 'local-extension-card__meta', `${extension.id} · v${extension.version}`)
            );
            const toggle = node(
                'button',
                extension.enabled ? 'btn btn-sm btn-light-danger' : 'btn btn-sm btn-light-success',
                extension.enabled ? '停止' : '启动'
            );
            toggle.type = 'button';
            toggle.disabled = extension.config_error === true && !extension.enabled;
            toggle.addEventListener('click', async () => {
                toggle.disabled = true;
                try {
                    await post('/admin/api/localExtensions/setStatus', {
                        id: extension.id,
                        enabled: extension.enabled ? '0' : '1'
                    });
                    await load();
                } catch (error) {
                    announceError(error);
                } finally {
                    toggle.disabled = false;
                }
            });
            top.append(heading, toggle);
            card.append(top, node('p', 'local-extension-card__description mt-4 mb-0', extension.description || ''));
            if (extension.config_error === true) {
                card.append(node('p', 'alert alert-danger mt-4 mb-0', '准入配置无法读取；启用时共享接入保持拒绝。可先停止本扩展，再由管理员修复站外配置。未读取到的配置不会作为空表单覆盖保存。'));
            } else if ((extension.settings || []).length > 0) card.append(settingsForm(extension));
            if (extension.id === 'PikaSupplySync') card.append(syncStatusCard(extension.sync_status));
            column.append(card);
            grid.append(column);
        });
        if (extensions.length === 0) {
            grid.append(node('div', 'alert alert-secondary', '尚未安装任何受信本地扩展。'));
        }
        root.replaceChildren(grid);
    };

    const load = async () => {
        const payload = await post('/admin/api/localExtensions/listing', {});
        if (alive) render(Array.isArray(payload.data?.list) ? payload.data.list : []);
    };

    $(document).one('pjax:beforeReplace.localExtensions', () => { alive = false; });
    load().catch(announceError);
}();
