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
        const count = value => Number.isInteger(value) && value >= 0 && value <= 40000 ? String(value) : '未记录';
        const record = (label, entry) => {
            const block = node('div', 'local-sync-record');
            if (!entry || typeof entry !== 'object') {
                block.append(node('p', 'mb-0', `${label}：未找到可读记录，不代表从未执行。`));
                return block;
            }
            const labels = {ok: '批次完成', partial: '部分完成／有失败或受限', error: '本轮失败',
                locked: '未执行：货源锁被占用', held_empty_catalog: '未执行：空目录安全拦截'};
            let outcome = labels[entry.status] || '结果未核实';
            if (entry.status === 'ok' && entry.planned === 0) outcome = '本批无动作，不代表全部商品已同步';
            const applied = entry.applied || {};
            if (entry.kind === 'actual' && entry.status === 'ok' && entry.planned !== 0
                && ['sync', 'import', 'zero'].every(key => Number.isInteger(applied[key]) && applied[key] === 0)) {
                outcome = '本批未保存商品（可能被单品开关或安全规则跳过）';
            }
            const mode = ['basic', 'full'].includes(entry.mode) ? entry.mode : '模式未记录';
            const time = typeof entry.recorded_at === 'string' && entry.recorded_at.length <= 40
                ? entry.recorded_at : '时间未记录';
            const zone = entry.timezone === 'UTC' ? 'UTC' : '历史站点时间，时区未记录';
            block.append(node('p', 'mb-1', `${label}：${outcome}`),
                node('p', 'text-muted mb-1', `${time}（${zone}） · ${mode}${entry.origin === 'state' ? ' · 来自每源历史状态，日志可能已截断' : ''}`));
            block.append(node('p', 'mb-0', `计划 ${count(entry.planned)}；同步保存 ${count(applied.sync)}；新建 ${count(applied.import)}；库存清零 ${count(applied.zero)}；失败／待确认 ${count(entry.failed)}`));
            const diagnostic = entry.catalog_diagnostic;
            if (entry.status === 'error' && diagnostic && typeof diagnostic === 'object' && !Array.isArray(diagnostic)) {
                const reasons = {response_size: '目录超过 16 MiB 安全上限；缩小批量不会减少整份目录大小',
                    schema: '目录响应结构不正确', credentials: '目录认证失败',
                    transport: '目录传输失败', http_retryable: '目录服务暂不可用', http_rejected: '目录请求被拒绝',
                    business: '目录业务校验失败', content_type: '目录响应类型不正确', json: '目录 JSON 无效',
                    budget: '目录请求预算已用尽'};
                const reason = Object.hasOwn(reasons, diagnostic.category) ? reasons[diagnostic.category] : '目录错误类别未记录';
                const observation = (key, min, max) => Number.isInteger(diagnostic[key])
                    && diagnostic[key] >= min && diagnostic[key] <= max ? String(diagnostic[key]) : '未记录';
                block.append(node('p', 'text-warning mb-0', `目录请求诊断：${reason}。本货源本轮未执行商品写入。`),
                    node('p', 'text-muted mb-0', `HTTP ${observation('http_status', 100, 599)}；cURL ${observation('curl_code', 0, 999)}；耗时 ${observation('elapsed_ms', 0, 480000)} ms；尝试 ${observation('attempts', 1, 3)} 次。HTTP 状态不代表同步成功。`));
            }
            if (Number.isInteger(entry.selection_held) && entry.selection_held > 0) block.append(node('p', 'text-warning mb-0', `规格或价格变更待确认 ${entry.selection_held} 项：无法精确匹配的部分保留本地，计入部分处理；没有建立自动补查任务。`));
            if (entry.mass_zero_fuse === true) block.append(node('p', 'text-warning mb-0', '批量清零熔断已触发，部分清零动作被拦截。'));
            if (Number.isInteger(applied.held_race) && applied.held_race > 0) block.append(node('p', 'text-warning mb-0', `目录与详情库存不一致，暂缓 ${applied.held_race} 项。`));
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
            if ((extension.settings || []).length > 0) card.append(settingsForm(extension));
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
