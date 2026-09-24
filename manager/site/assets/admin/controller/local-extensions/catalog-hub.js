!function () {
    'use strict';

    const root = document.getElementById('catalog-hub-root');
    if (!root) return;

    let csrf = String(root.dataset.csrf || '');
    const PROTOCOL_OPTIONS = [
        ['0', '异次元(V3.1.2 重构后全新版)'],
        ['2', '异次元(V3.1.1 之前旧版)'],
        ['1', '萌次元(V4.0)']
    ];
    const CURRENCY_OPTIONS = [
        'CNY', 'USD', 'EUR', 'JPY', 'GBP', 'HKD', 'TWD', 'KRW', 'SGD', 'MYR',
        'THB', 'VND', 'IDR', 'PHP', 'RUB', 'AUD', 'CAD', 'BRL', 'INR', 'TRY'
    ];
    const ACTIVE_TASK_STATES = new Set([
        'queued_analysis', 'analyzing', 'queued_import', 'importing',
        'pause_requested', 'cancel_requested'
    ]);
    const FINAL_TASK_STATES = new Set(['cancelled', 'completed', 'failed']);
    const TASK_STATE_LABELS = {
        queued_analysis: '等待分析',
        analyzing: '正在分析',
        awaiting_confirmation: '等待确认分类',
        queued_import: '等待入库',
        importing: '正在后台入库',
        pause_requested: '正在安全暂停',
        paused: '已暂停',
        cancel_requested: '正在安全取消',
        cancelled: '已取消',
        completed: '已完成',
        failed: '失败'
    };

    let alive = true;
    let connecting = false;
    let editingSourceId = 0;
    let tasksLoading = false;
    let pollingSuspended = false;
    let taskPollFailures = 0;
    let csrfRenewal = null;
    let pollTimer = null;
    const analyzingSources = new Set();
    const controllingTasks = new Set();
    const confirmingTasks = new Set();
    const sourceDrafts = new Map();
    const categoryModeDrafts = new Map();
    const CATEGORY_MODE_OPTIONS = [['smart', '智能分类（默认）'], ['mirror', '保留上游分类结构（首次入库）']];
    const taskDrafts = new Map();
    const taskPremiumSeeds = new Map();
    const notices = new Map();
    const DRAFT_TTL_MS = 12 * 60 * 60 * 1000;
    const DRAFT_MAX_ENTRIES = 32;
    const DRAFT_MAX_BYTES = 524288;
    let draftStorage = null;
    let draftPrefix = '';
    let selectedConfirmation = '';
    let renderedConfirmationKey = '';
    const requestControllers = new Set();
    let sourceFormControls = null;
    let model = {
        sources: [],
        settings: {schema: 1, aliases: [], rules: []},
        tasks: []
    };

    const node = (tag, className, text) => {
        const element = document.createElement(tag);
        if (className) element.className = className;
        if (text !== undefined) element.textContent = String(text);
        return element;
    };

    const responsePayload = async response => {
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
            const error = new Error(String(payload.msg || '请求失败'));
            error.serverRejected = (typeof payload.code === 'number' || typeof payload.code === 'string')
                && Number.isFinite(Number(payload.code)) && Number(payload.code) !== 200
                && typeof payload.msg === 'string' && payload.msg.trim() !== '';
            error.safeCode = String(payload.error_code || payload.data?.error_code || '');
            error.traceId = String(payload.trace_id || payload.data?.trace_id || '');
            throw error;
        }
        return payload;
    };

    const request = async (url, data, includeCsrf, timeoutMs = 0) => {
        const controller = new AbortController();
        requestControllers.add(controller);
        const timeout = timeoutMs > 0 ? setTimeout(() => controller.abort(), timeoutMs) : null;
        const values = includeCsrf ? {...data, csrf_token: csrf} : {...data};
        const body = new URLSearchParams(values);
        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                body: body.toString(),
                signal: controller.signal
            });
            return await responsePayload(response);
        } finally {
            if (timeout !== null) clearTimeout(timeout);
            requestControllers.delete(controller);
        }
    };

    const post = (url, data) => request(url, data, true);

    const renewPollCsrf = () => {
        if (csrfRenewal === null) {
            csrfRenewal = (async () => {
                try {
                    const payload = await request('/admin/api/localExtensions/catalogHubRefreshCsrf', {csrf_token: csrf}, false, 15000);
                    const token = payload.data?.csrf_token;
                    if (!alive || typeof token !== 'string' || !/^\d{1,12}\.[a-f0-9]{64}$/.test(token)) {
                        throw new Error('Invalid renewal receipt');
                    }
                    csrf = token;
                } catch (_) {
                    pollingSuspended = true;
                    throw new Error('页面凭证更新失败或会话已变化');
                } finally {
                    csrfRenewal = null;
                }
            })();
        }
        return csrfRenewal;
    };

    // Only this read-only endpoint may renew and retry once. All explicit write
    // actions keep using post(), which never renews or replays a request.
    const readTaskProgress = async () => {
        try {
            return await request('/admin/api/localExtensions/catalogHubTasks', {}, true, 30000);
        } catch (error) {
            if (!alive || error.safeCode !== 'LOCAL_EXTENSIONS_CSRF_INVALID') throw error;
            await renewPollCsrf();
            if (!alive) throw new Error('Page no longer active');
            return request('/admin/api/localExtensions/catalogHubTasks', {}, true, 30000);
        }
    };

    const stopRequests = () => {
        if (pollTimer !== null) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }
        requestControllers.forEach(controller => controller.abort());
        requestControllers.clear();
    };

    const renderNotice = (output, saved) => {
        const message = node('div', `alert alert-${saved.type} mb-0`, saved.text);
        if (saved.traceId || saved.errorCode) {
            const details = node('details', 'mt-2');
            details.append(node('summary', '', '诊断详情'));
            if (saved.errorCode) details.append(node('div', '', `错误代码：${saved.errorCode}`));
            if (saved.traceId) details.append(node('div', '', `诊断编号：${saved.traceId}`));
            message.append(details);
        }
        output.replaceChildren(message);
    };
    const notice = scope => {
        const output = node('div');
        output.id = `catalog-hub-message-${scope}`;
        const saved = notices.get(scope);
        if (saved) renderNotice(output, saved);
        return output;
    };

    const notify = (messageText, type = 'danger', scope = 'source-form', error = null) => {
        if (!alive) return;
        const saved = {
            text: String(messageText), type,
            errorCode: /^[A-Z][A-Z0-9_]{3,79}$/.test(String(error?.safeCode || '')) ? error.safeCode : '',
            traceId: /^[A-Za-z0-9_-]{8,80}$/.test(String(error?.traceId || '')) ? error.traceId : ''
        };
        notices.set(scope, saved);
        const status = document.getElementById(`catalog-hub-message-${scope}`);
        if (status) renderNotice(status, saved);
    };

    const inputField = (labelText, options = {}) => {
        const wrapper = node('label', options.className || '');
        wrapper.append(node('span', 'form-label', labelText));
        const input = node('input', 'form-control');
        input.type = options.type || 'text';
        input.value = String(options.value ?? '');
        if (options.name) input.name = options.name;
        if (options.placeholder) input.placeholder = options.placeholder;
        if (options.maxLength) input.maxLength = options.maxLength;
        if (options.required) input.required = true;
        if (options.autocomplete) input.autocomplete = options.autocomplete;
        if (options.dataset) Object.assign(input.dataset, options.dataset);
        wrapper.append(input);
        return {wrapper, input};
    };

    const selectField = (labelText, options, selectedValue, dataset = {}) => {
        const wrapper = node('label');
        wrapper.append(node('span', 'form-label', labelText));
        const select = node('select', 'form-select');
        Object.assign(select.dataset, dataset);
        options.forEach(([value, text]) => {
            const option = node('option', '', text);
            option.value = String(value);
            option.selected = String(value) === String(selectedValue);
            select.append(option);
        });
        select.value = String(selectedValue);
        wrapper.append(select);
        return {wrapper, select};
    };

    const aliasMap = () => new Map(
        (model.settings.aliases || []).map(entry => [Number(entry.source_id), String(entry.alias || '')])
    );

    const sourceAlias = source => aliasMap().get(Number(source.id)) || String(source.alias || source.name || `货源${source.id}`);
    const sourceCategoryMode = source => categoryModeDrafts.get(Number(source.id))
        || model.settings.aliases?.find(entry => Number(entry.source_id) === Number(source.id))?.category_mode
        || source.category_mode || 'smart';
    const mirrorTask = task => task?.category_mode === 'mirror';
    const mirrorCategoriesValid = categories => categories.length > 0 && categories.every(category => {
        const path = category?.target?.path;
        if (category?.target?.mode !== 'mirror' || !Array.isArray(path) || !path.length || path.length > 100) return false;
        const seen = new Set();
        return path.every((part, index) => {
            if (!Number.isInteger(part?.id) || part.id < 1 || seen.has(part.id)
                || part.pid !== (index ? path[index - 1].id : 0) || typeof part.name !== 'string' || !part.name) return false;
            seen.add(part.id);
            return true;
        }) && path[path.length - 1].name === category.name;
    });

    const setSourceAlias = (sourceId, alias) => {
        const sourceNumber = Number(sourceId);
        const entries = Array.isArray(model.settings.aliases) ? model.settings.aliases : [];
        const existing = entries.find(entry => Number(entry.source_id) === sourceNumber);
        if (existing) existing.alias = alias;
        else entries.push({source_id: sourceNumber, alias});
        model.settings.aliases = entries;
    };

    const mergeSource = (sourceId, alias, officialData = {}) => {
        const id = Number(sourceId);
        let source = model.sources.find(item => Number(item.id) === id);
        if (!source) {
            source = {
                id,
                name: alias,
                alias
            };
            model.sources.push(source);
        }
        ['name', 'domain', 'app_id', 'currency', 'currency_rate'].forEach(field => {
            if (Object.prototype.hasOwnProperty.call(officialData, field)) {
                source[field] = String(officialData[field] ?? '');
            }
        });
        if (Object.prototype.hasOwnProperty.call(officialData, 'type')) {
            source.type = Number(officialData.type);
        }
        source.alias = alias;
        setSourceAlias(id, alias);
        return source;
    };


    const taskState = task => String(task?.state || task?.status || '');
    const taskId = task => String(task?.task_id || task?.id || '');
    const taskPlanHash = task => String(task?.plan_hash || task?.snapshot?.plan_hash || '');
    const taskIsActive = task => task?.active === true || ACTIVE_TASK_STATES.has(taskState(task));
    const taskFinishedWithIssues = task => {
        const progress = task?.progress;
        return taskState(task) === 'failed' && task.phase === 'import'
            && task.error_code === 'IMPORT_FINISHED_WITH_ISSUES'
            && progress && ['total', 'processed', 'succeeded', 'failed', 'skipped'].every(key =>
                Number.isInteger(progress[key]) && progress[key] >= 0 && progress[key] <= 10000)
            && progress.failed > 0 && progress.processed === progress.total
            && progress.succeeded + progress.failed + progress.skipped === progress.processed;
    };
    const taskStateLabel = task => taskFinishedWithIssues(task)
        ? '处理结束，有异常' : TASK_STATE_LABELS[taskState(task)] || '未知状态';
    const ITEM_FAILURE_MESSAGES = {
        ITEM_DETAIL_UNAVAILABLE: '未取得有效商品详情，不能据此认定下架',
        ITEM_REMOTE_DATA_INVALID: '远端商品数据未通过校验',
        ITEM_DETAIL_JSON_INVALID: '详情 JSON 解析失败，该商品未入库',
        ITEM_DETAIL_TRANSPORT_FAILED: '商品详情连接失败，有限尝试已结束',
        ITEM_DETAIL_HTTP_RETRYABLE: '货源暂时不可用或限流，有限尝试已结束'
    };
    const TASK_ERROR_MESSAGES = {
        MIRROR_TREE_UNAVAILABLE: '上游未支持或未通过完整分类权限检查，镜像已停止；不会压平或回退智能分类。',
        MIRROR_TREE_INVALID: '完整分类缺失、冲突或超限，镜像已停止；请检查上游祖先结构。',
        MIRROR_CATEGORY_CAPACITY_FAILED: '镜像映射容量、模式或已建立结构不一致，已阻止入库。',
        ANALYSIS_FETCH_FAILED: '读取货源商品目录时中断，请检查货源状态后重新分析。',
        ANALYSIS_CATALOG_INVALID: '货源返回的商品目录无法分析，请检查货源后重新分析。',
        ITEM_DETAIL_FETCH_FAILED: '读取某件商品详情时中断，原因尚未确定；已完成进度保留，请先检查后再决定是否继续。',
        ITEM_DETAIL_UNKNOWN_FAILED: '读取商品详情时发生未分类错误，已停止入库；请先检查原因，不会自动继续。',
        ITEM_DETAIL_TRANSPORT_FAILED: '读取商品详情时发生临时连接故障；进度已保留，恢复后可手动继续。',
        ITEM_DETAIL_HTTP_RETRYABLE: '货源暂时不可用或限流，有限尝试已结束；进度已保留，恢复后可手动继续。',
        ITEM_DETAIL_HTTP_REJECTED: '货源 HTTP 请求被拒绝，已停止入库；请先核查货源，不会自动继续。',
        ITEM_DETAIL_CREDENTIALS_INVALID: '货源凭据格式无效，已停止入库；请联系管理员核查。',
        ITEM_DETAIL_BUSINESS_REJECTED: '货源业务接口拒绝了详情请求，已停止入库；请先核查货源。',
        ITEM_DETAIL_RESPONSE_INVALID: '货源详情响应格式不正确，已停止入库；请先核查接口兼容性。',
        ITEM_DETAIL_BUDGET_EXCEEDED: '本轮详情读取超过安全预算，已停止入库；请联系管理员核查。',
        ITEM_DETAIL_NORMALIZATION_FAILED: '某件商品的详情格式无法处理，请先检查货源数据。',
        ITEM_SOURCE_POLICY_FAILED: '货源地址未通过安全检查，已停止入库。',
        ITEM_STOCK_VALIDATION_FAILED: '某件商品的库存数据未通过检查，已停止入库。',
        ITEM_PRICE_ADJUSTMENT_FAILED: '某件商品的价格计算未通过检查，已停止入库。',
        ITEM_CONFIG_EXTRACTION_FAILED: '某件商品的下单配置无法处理，已停止入库。',
        ITEM_CATEGORY_TRANSACTION_FAILED: '分类与确认方案不一致或保存失败，已停止入库。',
        ITEM_PERSISTENCE_FAILED: '某件商品保存失败，已停止入库，请先检查后台记录。',
        SOURCE_IDENTITY_CHANGED: '货源资料在任务期间发生变化，请检查货源后重新分析。',
        SOURCE_UNAVAILABLE: '当前货源已不可用，请先检查货源资料。',
        IMPORT_SNAPSHOT_INVALID: '已确认的分类方案无法核验，请检查后重新分析。',
        IMPORT_CHECKPOINT_FAILED: '处理进度保存失败，请先检查后台记录。',
        IMPORT_FINISHED_WITH_ISSUES: '入库结果包含异常，请核对未导入清单；不会自动重试。',
        IMPORT_ITEM_FAILURE_LIMIT: '单件异常已达到安全上限，已停止该货源入库；请联系管理员核查货源接口，刷新不会自动重试。',
        WORKER_START_FAILED: '后台任务暂时无法启动，请联系管理员检查运行状态。'
    };
    const errorText = (error, fallback) => TASK_ERROR_MESSAGES[error?.safeCode] || error?.message || fallback;
    const uncertainMutationText = error => error?.serverRejected === true
        ? errorText(error, '结果尚未核实，请先刷新任务列表；不要重复提交。')
        : '结果尚未核实，请先刷新任务列表；不要重复提交。';
    const taskBinding = task => [taskId(task), Number(task.source_id), taskPlanHash(task), String(task.snapshot?.sha256 || '')].join(':');
    const premiumValue = value => value === null || value === undefined || value === '' ? '0' : String(value);
    const renderDraftStorageNotice = () => {
        const output = document.getElementById('catalog-hub-draft-status');
        if (output) output.replaceChildren(node('p', 'catalog-hub-muted mb-0', draftStorage
            ? '未确认的加价与分类会作为草稿保留在本次登录会话内，12 小时后过期。刷新恢复后仍须核对并确认，不会自动入库。'
            : '当前无法跨刷新保存草稿。切换货源和自动更新仍会保留输入；刷新或离开本页会清除尚未确认的设置。'));
    };
    const stopDraftStorage = () => { draftStorage = null; renderDraftStorageNotice(); };
    const validDraftKey = key => /^source:\d{1,10}$/.test(key)
        || /^task:[A-Za-z0-9_-]{1,80}:\d{1,10}:(?:[a-f0-9]{64})?:(?:[a-f0-9]{64})?$/.test(key);
    const draftRecord = draft => {
        if (!draft || typeof draft !== 'object' || Array.isArray(draft)
            || !['number', 'string'].includes(typeof draft.premium)) return null;
        const premium = Number(draft?.premium);
        const mappings = draft?.mappings || [];
        if (!Number.isFinite(premium) || premium < 0 || premium > 1000 || !Array.isArray(mappings) || mappings.length > 200) return null;
        if (!mappings.every(item => item && ['name', 'group', 'family'].every(key =>
            typeof item[key] === 'string' && item[key].length <= (key === 'name' ? 128 : 64)))) return null;
        return {premium, mappings: mappings.map(item => ({name: item.name, group: item.group, family: item.family}))};
    };
    const dropStoredDraft = key => {
        if (!draftStorage || !validDraftKey(key)) return;
        try { draftStorage.removeItem(draftPrefix + key); } catch (_) { stopDraftStorage(); }
    };
    const readStoredDraft = key => {
        if (!draftStorage || !validDraftKey(key)) return null;
        let raw;
        try { raw = draftStorage.getItem(draftPrefix + key); } catch (_) { stopDraftStorage(); return null; }
        if (!raw) return null;
        try {
            if (raw.length > 65536) throw new Error('draft size');
            const value = JSON.parse(raw);
            const record = draftRecord(value);
            if (!record || typeof value.premium !== 'number' || !Array.isArray(value.mappings)
                || Object.keys(value).some(key => !['premium', 'mappings', 'expiresAt'].includes(key))
                || !Number.isSafeInteger(value.expiresAt) || value.expiresAt <= Date.now()
                || value.expiresAt > Date.now() + DRAFT_TTL_MS) throw new Error('draft expired');
            return {...record, premium: String(record.premium), restored: true};
        } catch (_) {
            dropStoredDraft(key);
            return null;
        }
    };
    const saveStoredDraft = (key, draft) => {
        if (!draftStorage || !validDraftKey(key)) return;
        const record = draftRecord(draft);
        if (!record) { dropStoredDraft(key); return; }
        try {
            const entries = [];
            if (draftStorage.length > 512) throw new Error('storage limit');
            for (let index = 0; index < draftStorage.length; index += 1) {
                const storedKey = draftStorage.key(index);
                if (!storedKey?.startsWith(draftPrefix)) continue;
                const raw = draftStorage.getItem(storedKey) || '';
                let expiresAt = 0;
                try { if (raw.length <= 65536) expiresAt = Number(JSON.parse(raw).expiresAt || 0); } catch (_) {}
                entries.push({key: storedKey, size: raw.length * 2, expiresAt});
            }
            const serialized = JSON.stringify({...record, expiresAt: Date.now() + DRAFT_TTL_MS});
            if (serialized.length > 65536) throw new Error('draft size');
            let bytes = entries.reduce((sum, item) => sum + item.size, 0) + serialized.length * 2;
            let count = entries.length + 1;
            for (const item of entries.sort((left, right) => left.expiresAt - right.expiresAt)) {
                if (item.key === draftPrefix + key || item.expiresAt <= Date.now()
                    || count > DRAFT_MAX_ENTRIES || bytes > DRAFT_MAX_BYTES) {
                    draftStorage.removeItem(item.key);
                    bytes -= item.size;
                    count -= 1;
                }
            }
            draftStorage.setItem(draftPrefix + key, serialized);
        } catch (_) { stopDraftStorage(); }
    };
    const initializeDraftStorage = scope => {
        if (!/^[a-f0-9]{64}$/.test(String(scope || ''))) return;
        draftPrefix = `pika-catalog-draft:v1:${scope}:`;
        try {
            draftStorage = window.sessionStorage || null;
            if (draftStorage) draftStorage.getItem(draftPrefix + 'source:0');
        } catch (_) { draftStorage = null; }
    };
    const sourceDraft = sourceId => {
        const id = Number(sourceId);
        if (!sourceDrafts.has(id)) sourceDrafts.set(id, readStoredDraft(`source:${id}`) || {premium: '0', origin: 'default'});
        const draft = sourceDrafts.get(id);
        if (draft.origin === 'default') {
            const confirmed = model.tasks.filter(task => Number(task.source_id) === id && task.phase === 'import'
                && task.premium_percent !== null && task.premium_percent !== undefined
                && Number.isFinite(Number(task.premium_percent)) && Number(task.premium_percent) >= 0 && Number(task.premium_percent) <= 1000)
                .sort((left, right) => String(right.updated_at || right.created_at || '').localeCompare(String(left.updated_at || left.created_at || '')))[0];
            if (confirmed) {
                draft.premium = String(confirmed.premium_percent);
                draft.origin = 'last-confirmed';
            }
        }
        return draft;
    };
    const setSourcePremium = (sourceId, value) => {
        const draft = sourceDraft(sourceId);
        draft.premium = value;
        draft.origin = 'draft';
        draft.restored = false;
        saveStoredDraft(`source:${Number(sourceId)}`, {premium: value, mappings: []});
    };
    const confirmationDraft = task => {
        const id = taskId(task);
        const binding = taskBinding(task);
        let draft = taskDrafts.get(id);
        if (!draft || draft.binding !== binding) {
            if (draft && draft.binding !== binding) dropStoredDraft(`task:${draft.binding}`);
            const stored = readStoredDraft(`task:${binding}`);
            const pendingKey = `task:${[id, Number(task.source_id), '', ''].join(':')}`;
            const seed = !draft && !stored ? readStoredDraft(pendingKey) : null;
            const categories = categorySuggestions(task);
            const usable = stored && stored.mappings.length === categories.length
                && stored.mappings.every((item, index) => item.name === String(categories[index]?.name || '')) ? stored : null;
            draft = {
                binding,
                sourceId: Number(task.source_id),
                premium: usable?.premium ?? premiumValue(task.premium_percent ?? (draft ? '0' : taskPremiumSeeds.get(id) ?? seed?.premium)),
                restored: Boolean(usable || seed),
                mappings: usable?.mappings || categories.map(category => ({
                    name: String(category?.name || ''),
                    group: String(category?.target?.group || ''),
                    family: String(category?.target?.family || '')
                }))
            };
            taskDrafts.set(id, draft);
            taskPremiumSeeds.delete(id);
            if (pendingKey !== `task:${binding}`) dropStoredDraft(pendingKey);
            saveStoredDraft(`task:${binding}`, draft);
        }
        return draft;
    };
    const captureConfirmationDraft = () => {
        const container = document.querySelector?.('[data-confirmation-draft]')
            || root.querySelector('[data-confirmation-draft]');
        if (!container) return;
        const draft = taskDrafts.get(container.dataset.confirmationDraft);
        if (!draft || draft.binding !== container.dataset.draftBinding) return;
        const premium = container.querySelector('[data-premium-percent]');
        if (premium) draft.premium = premium.value;
        const rows = container.querySelectorAll('[data-mapping-row]');
        if (rows.length === draft.mappings.length) rows.forEach((row, index) => {
            const group = row.querySelector('[data-mapping-group]');
            const family = row.querySelector('[data-mapping-family]');
            if (group && family) {
                draft.mappings[index].group = group.value;
                draft.mappings[index].family = family.value;
            }
        });
        saveStoredDraft(`task:${draft.binding}`, draft);
        setSourcePremium(draft.sourceId, draft.premium);
    };
    const latestSourceTask = sourceId => {
        const tasks = model.tasks.filter(task => Number(task.source_id) === Number(sourceId));
        tasks.sort((left, right) => String(right.updated_at || right.created_at || '').localeCompare(String(left.updated_at || left.created_at || '')));
        return tasks.find(task => taskHasOpenWork(task)) || tasks[0];
    };
    const taskGuidance = task => {
        const state = taskState(task);
        const retry = retryProgress(task);
        if (retry?.active === true) {
            return state === 'paused'
                ? '本次补处理已暂停。继续只处理本轮尚未完成的异常索引，不重跑原目录；故障计数保留。'
                : state === 'failed' ? '本次补处理已中断，原总账与未导入清单保留。请先处理提示原因，再按可用操作继续；刷新不会自动重试。'
                    : state === 'cancelled' ? '本次补处理已取消，原总账与未导入清单保留，不会撤销已入库商品。'
                        : '后台只处理本轮异常索引，不重跑成功商品或推进原目录；可在当前商品安全边界暂停或取消。';
        }
        if (retry && retry.cursor === retry.indices.length && state === 'paused') {
            return '本次补处理已结束，原任务仍有尚未处理的商品。核对本轮结果后，可明确点击「继续原任务」；不会自动接着扫描。';
        }
        if (taskFinishedWithIssues(task)) return `正常商品已处理，${task.progress.failed} 件异常商品未导入。请核对下方清单并处理货源异常；${canRetryFailed(task) ? '确认原因已处理后，可明确点击「只重试未入库项」，不重跑成功商品；' : ''}本任务不会自动补入，刷新不会自动重试。`;
        if (state === 'failed' && task.error_code === 'IMPORT_FINISHED_WITH_ISSUES') return '处理结果与进度信息尚未核实，请联系管理员检查；不要重复提交入库。';
        if (state === 'failed' && task.error_code === 'IMPORT_ITEM_FAILURE_LIMIT') return `已入库商品会保留，安全上限阻止继续请求。请先核查货源异常；${canRetryFailed(task) ? '原因处理后可明确启动「只重试未入库项」，不会清零或伪装原任务故障计数；' : ''}刷新不会自动重试。`;
        if (state === 'queued_analysis') return '分析任务已排队；完成后在下方确认分类与加价。';
        if (state === 'analyzing') return '正在分析商品目录；此时不会创建分类或导入商品。';
        if (state === 'awaiting_confirmation') return '请检查分类并确认本次新商品加价，再开始后台入库。';
        if (state === 'queued_import') return '入库任务已排队，将按已确认的分类和加价处理。';
        if (state === 'importing') return '后台正在分批入库，可安全暂停或取消。';
        if (state === 'pause_requested') return '正在完成当前安全步骤，随后暂停。';
        if (state === 'cancel_requested') return '正在完成当前安全步骤，随后取消；已入库商品会保留。';
        if (state === 'paused') return '进度已保留，点击继续恢复当前任务，或取消后重新分析。';
        if (state === 'failed') return canResumeDetailFetch(task)
            ? '已保留检查点。货源恢复后可继续入库，沿用本次分类与加价；或取消后结束此任务。取消不会撤销已入库商品。'
            : canCancelFailedImport(task) ? '此任务不能直接继续，可取消后结束此任务；取消不会撤销已入库商品。请先处理货源问题，再决定是否重新分析。'
            : '此任务不能直接继续。请先处理提示的问题，再从货源卡片重新分析。';
        if (state === 'completed') return '本次任务已完成。请到商品管理核对结果；需要新一轮入库时重新分析。';
        if (state === 'cancelled') return '本次任务已取消，已入库商品会保留；需要继续处理时请重新分析。';
        return '正在读取任务状态。';
    };
    const showTask = task => {
        if (taskState(task) === 'awaiting_confirmation') {
            captureConfirmationDraft();
            selectedConfirmation = taskId(task);
            renderSuggestions();
            document.getElementById('catalog-hub-suggestions')?.scrollIntoView?.({behavior: 'smooth', block: 'start'});
        } else {
            document.getElementById(`catalog-hub-task-${taskId(task)}`)?.scrollIntoView?.({behavior: 'smooth', block: 'start'});
        }
    };

    const normalizeTaskList = data => {
        const result = [];
        const append = task => {
            if (!task || typeof task !== 'object' || Array.isArray(task) || !taskId(task)) return;
            const index = result.findIndex(existing => taskId(existing) === taskId(task));
            if (index >= 0) result[index] = task;
            else result.push(task);
        };
        const candidates = Array.isArray(data?.tasks)
            ? data.tasks
            : Array.isArray(data?.list) ? data.list : [];
        candidates.forEach(append);
        append(data?.active_task);
        append(data?.detail);
        append(data?.task);
        return result;
    };

    const mergeTask = task => {
        if (!task || typeof task !== 'object' || !taskId(task)) return;
        const index = model.tasks.findIndex(existing => taskId(existing) === taskId(task));
        if (index >= 0) model.tasks[index] = task;
        else model.tasks.unshift(task);
    };

    const scheduleTaskPoll = () => {
        if (pollTimer !== null) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }
        if (!alive || pollingSuspended || !model.tasks.some(taskIsActive)) return;
        pollTimer = setTimeout(() => {
            pollTimer = null;
            loadTasks().catch(() => {});
        }, 3000);
    };

    const taskProgressText = task => {
        const progress = task?.progress && typeof task.progress === 'object' ? task.progress : {};
        const processed = Number(progress.processed ?? progress.completed ?? 0);
        const total = Number(progress.total ?? task?.snapshot?.item_count ?? 0);
        const success = Number(progress.success ?? progress.succeeded ?? 0);
        const failed = Number(progress.failed ?? 0);
        const skipped = Number(progress.skipped ?? 0);
        const remaining = Math.max(0, total - processed);
        if (task.phase === 'analysis') {
            if (taskState(task) === 'failed') return '分析已中断，尚未开始入库。';
            if (taskState(task) === 'awaiting_confirmation') return `分析完成，待核对 ${total} 件商品；尚未开始入库。`;
            return '尚未开始入库，正在准备分类方案。';
        }
        return `已处理 ${processed}/${total}；成功 ${success}；跳过 ${skipped}；剩余 ${remaining}${failed > 0 ? `；未导入 ${failed}` : ''}${taskFinishedWithIssues(task) ? '；处理结束' : taskState(task) === 'failed' ? '；任务已中断' : ''}`;
    };

    const controlTask = async (task, action, button) => {
        const id = taskId(task);
        if (!id || controllingTasks.has(id)) return;
        controllingTasks.add(id);
        button.disabled = true;
        renderTasks();
        try {
            const payload = await post('/admin/api/localExtensions/catalogHubTaskControl', {
                task_id: id,
                action,
                revision: String(Number(task.revision || 0))
            });
            const updated = payload.data?.task || payload.data?.detail;
            if (taskId(updated) !== id) throw new Error('task receipt unavailable');
            mergeTask(updated);
            renderTasks();
            renderSuggestions();
            renderSourceRows();
            notify('后台任务状态已更新。', 'success', `task-${id}`);
        } catch (error) {
            notify(uncertainMutationText(error), 'danger', `task-${id}`, error);
        } finally {
            controllingTasks.delete(id);
            if (alive) renderTasks();
            scheduleTaskPoll();
        }
    };

    const taskActionButton = (task, action, label, className) => {
        const button = node('button', `btn btn-sm ${className}`, label);
        button.type = 'button';
        button.dataset.taskAction = action;
        button.dataset.taskFocus = JSON.stringify([taskId(task), 'action', action]);
        button.disabled = controllingTasks.has(taskId(task));
        button.addEventListener('click', () => controlTask(task, action, button));
        return button;
    };

    const canResumeDetailFetch = task => taskState(task) === 'failed' && task?.can_resume === true
        && !['IMPORT_FINISHED_WITH_ISSUES', 'IMPORT_ITEM_FAILURE_LIMIT'].includes(task.error_code);
    const canResumeTask = task => (taskState(task) === 'paused' && task.can_resume !== false) || canResumeDetailFetch(task);
    const canCancelFailedImport = task => taskState(task) === 'failed'
        && ((canRetryFailed(task) && task.can_cancel === true)
            || (!['IMPORT_FINISHED_WITH_ISSUES', 'IMPORT_ITEM_FAILURE_LIMIT'].includes(task.error_code)
                && (task?.can_cancel === true || canResumeDetailFetch(task))));
    const canRetryFailed = task => ['failed', 'paused'].includes(taskState(task)) && task.phase === 'import'
        && task.can_retry_failed === true;
    const taskHasOpenWork = task => !FINAL_TASK_STATES.has(taskState(task))
        || canCancelFailedImport(task) || canRetryFailed(task);

    const retryProgress = task => {
        const retry = task.retry;
        const total = task.progress?.total;
        if (!retry || typeof retry !== 'object' || Array.isArray(retry)
            || !Number.isInteger(total) || total < 1 || total > 10000
            || !Array.isArray(retry.indices) || retry.indices.length < 1 || retry.indices.length > 100
            || retry.indices.some((index, offset) => !Number.isInteger(index) || index < 0 || index >= total
                || (offset > 0 && index <= retry.indices[offset - 1]))
            || !['cursor', 'succeeded', 'skipped', 'failed', 'consecutive_failed'].every(key =>
                Number.isInteger(retry[key]) && retry[key] >= 0 && retry[key] <= retry.indices.length)
            || retry.succeeded + retry.skipped + retry.failed !== retry.cursor
            || typeof retry.halted !== 'boolean' || typeof retry.active !== 'boolean') return null;
        return retry;
    };

    const renderRetryProgress = task => {
        if (task.retry === undefined || task.retry === null) return null;
        const retry = retryProgress(task);
        if (!retry) return node('p', 'text-warning mb-0', '本次补处理进度未核实，请刷新或联系管理员核查，不要重复提交。');
        const pending = retry.indices.length - retry.cursor;
        const state = retry.halted ? (pending > 0 ? '旧规则停止，继续将从未补查项接续' : '旧规则停止，本轮已处理完')
            : pending === 0 ? '本轮已结束' : retry.active ? '本轮尚未结束' : '本轮已中断';
        const output = node('div', 'alert alert-secondary py-2 mb-0', `本次补处理 ${retry.cursor}/${retry.indices.length}；已处理 ${retry.cursor}；未补查 ${pending}；补入成功 ${retry.succeeded}；已存在 ${retry.skipped}；本轮仍失败 ${retry.failed}；${state}。原任务已处理/总计不会重复累计；本轮不自动重做已处理项，也不自动开始下一轮。`);
        output.dataset.retryProgress = '';
        return output;
    };

    const DETAIL_CATEGORY_LABELS = {
        none: '请求与解析未报错', transport: '网络传输', http_retryable: '暂态 HTTP', http_rejected: 'HTTP 拒绝',
        credentials: '凭据', business: '业务拒绝', content_type: '响应标签', json: 'JSON 校验', schema: '协议结构',
        response_size: '响应大小', budget: '运行预算', unknown: '未分类', item_unavailable: '未取得有效详情', item_invalid: '单件数据'
    };
    const DETAIL_MIME_LABELS = {
        application_json: '标准 JSON', text_json: '文本 JSON', json_suffix: 'JSON 后缀标签', text_html: 'HTML 标签',
        text_plain: '纯文本标签', missing: '未提供', empty: '空标签', other: '其他标签', malformed: '格式异常',
        conflicting: '冲突标签', unknown: '未核实'
    };
    const DETAIL_JSON_ERROR_LABELS = {
        depth: '本地解码深度超限', utf8: 'UTF-8 编码无效', syntax: '语法无效', unknown: '未分类'
    };
    const DETAIL_JSON_ERROR_KINDS = {1: 'depth', 2: 'syntax', 3: 'syntax', 4: 'syntax', 5: 'utf8', 9: 'syntax', 10: 'syntax'};
    const DETAIL_STRUCTURE_TYPES = {
        missing: '缺少字段/位置', null: 'null', boolean: '布尔', number: '数值', string: '字符串',
        list: '非空列表', object: '非空对象', empty_array_or_object: '空数组或对象（解码后无法区分）'
    };
    const taskDisclosure = (task, kind, className, label) => {
        const details = node('details', className);
        details.dataset.taskDisclosure = JSON.stringify([taskId(task), kind]);
        const summary = node('summary', '', label);
        summary.dataset.taskFocus = JSON.stringify([taskId(task), 'summary', kind]);
        details.append(summary);
        return details;
    };
    const renderDetailDiagnostic = task => {
        const record = task.last_detail_diagnostic;
        if (!record || typeof record !== 'object' || Array.isArray(record)) return null;
        const diagnostic = record.diagnostics;
        if (!diagnostic || typeof diagnostic !== 'object' || Array.isArray(diagnostic)) return null;
        const output = taskDisclosure(task, 'diagnostic', 'catalog-hub-muted', '最近详情诊断（脱敏）');
        const category = typeof diagnostic.category === 'string' && Object.prototype.hasOwnProperty.call(DETAIL_CATEGORY_LABELS, diagnostic.category)
            ? DETAIL_CATEGORY_LABELS[diagnostic.category] : '未分类';
        output.append(node('div', '', `类别：${category}`));
        if (Number.isInteger(record.index) && Number.isInteger(task.progress?.total)
            && record.index >= 0 && record.index < task.progress.total && task.progress.total <= 10000) {
            output.append(node('div', '', `快照序号：第 ${record.index + 1} 件`));
        }
        [['http_status', 'HTTP 状态', 599, 100], ['curl_code', '传输状态码', 999, 0], ['elapsed_ms', '耗时（毫秒）', 480000, 0], ['attempts', '请求次数', 3, 1]].forEach(([key, label, max, min]) => {
            const value = diagnostic[key];
            output.append(node('div', '', `${label}：${Number.isInteger(value) && value >= min && value <= max ? value : '未记录'}`));
        });
        if (Object.prototype.hasOwnProperty.call(diagnostic, 'mime_category')) {
            const mime = typeof diagnostic.mime_category === 'string' && Object.prototype.hasOwnProperty.call(DETAIL_MIME_LABELS, diagnostic.mime_category)
                ? DETAIL_MIME_LABELS[diagnostic.mime_category] : '未核实';
            output.append(node('div', '', `MIME 分类：${mime}`));
        }
        if (Number.isInteger(diagnostic.mime_count) && diagnostic.mime_count >= 0 && diagnostic.mime_count <= 65535) {
            output.append(node('div', '', `响应标签数量：${diagnostic.mime_count}`));
        }
        if (Object.prototype.hasOwnProperty.call(diagnostic, 'json_valid')) {
            output.append(node('div', '', `严格 JSON：${diagnostic.json_valid === true ? '有效' : diagnostic.json_valid === false ? '无效' : '未核实'}`));
        }
        const jsonCode = diagnostic.json_error_code;
        const jsonKind = Number.isInteger(jsonCode)
            ? DETAIL_JSON_ERROR_KINDS[jsonCode] || (jsonCode >= 12 && jsonCode <= 255 ? 'unknown' : null) : null;
        const jsonRecorded = jsonKind !== null && jsonKind === diagnostic.json_error;
        output.append(node('div', '', `JSON 原因：${jsonRecorded ? DETAIL_JSON_ERROR_LABELS[jsonKind] : '未记录'}`));
        output.append(node('div', '', `JSON 错误码：${jsonRecorded ? jsonCode : '未记录'}`));
        if (diagnostic.mime_compatibility === true) {
            output.append(node('div', '', '本次响应标签不标准；这只是请求观测，是否入库仍由完整商品校验决定。'));
        }
        const structure = diagnostic.response_structure;
        if (structure && typeof structure === 'object' && !Array.isArray(structure)) {
            [['data_type', 'data 类型'], ['first_children_type', '首分类 children 类型'], ['first_item_type', '首商品位置类型']].forEach(([key, label]) => {
                const value = structure[key];
                if (typeof value === 'string' && Object.prototype.hasOwnProperty.call(DETAIL_STRUCTURE_TYPES, value)) {
                    output.append(node('div', '', `${label}：${DETAIL_STRUCTURE_TYPES[value]}`));
                }
            });
            [['business_code', '业务码', -999999, 999999], ['data_count', 'data 元素数', 0, 10000], ['first_children_count', '首分类 children 元素数', 0, 10000]].forEach(([key, label, min, max]) => {
                const value = structure[key];
                if (Number.isInteger(value) && value >= min && value <= max) output.append(node('div', '', `${label}：${value}`));
            });
            output.append(node('div', '', '结构仅反映本次已取得响应，不代表商品已下架。'));
        }
        return output;
    };

    const renderItemFailures = task => {
        const failures = task.item_failures;
        if (failures === null || (failures === undefined && Number(task.progress?.failed) > 0)) {
            return node('p', 'text-warning mb-0', '旧任务未保存逐件异常明细。');
        }
        if (failures === undefined) return null;
        if (!Array.isArray(failures)) return node('p', 'text-warning mb-0', '未导入清单格式不正确，请联系管理员核查。');
        if (failures.length === 0 && Number(task.progress?.failed) === 0) return null;
        const details = taskDisclosure(task, 'failures', 'alert alert-warning py-2 mb-0', `未导入清单（${Math.min(failures.length, 100)} 条）`);
        if (failures.length !== task.progress?.failed) details.append(node('p', 'mb-0', '未导入数量与清单不一致，请联系管理员核查。'));
        if (failures.length > 100) details.append(node('p', 'mb-0', '清单超过显示上限，仅展示前 100 条，请联系管理员核查。'));
        const list = node('ul', 'mb-0 mt-2');
        const total = task.progress?.total;
        failures.slice(0, 100).forEach(failure => {
            if (!failure || typeof failure !== 'object' || Array.isArray(failure)
                || !Number.isInteger(total) || total < 1 || total > 10000
                || !Number.isInteger(failure.index) || failure.index < 0 || failure.index >= total) {
                list.append(node('li', '', '商品序号未核实，异常记录格式不正确，请联系管理员核查。'));
                return;
            }
            const reason = typeof failure.code === 'string' && Object.prototype.hasOwnProperty.call(ITEM_FAILURE_MESSAGES, failure.code)
                ? ITEM_FAILURE_MESSAGES[failure.code] : '异常原因未核实';
            const attempts = Number.isInteger(failure.attempts) && failure.attempts >= 0 && failure.attempts <= 3
                ? failure.attempts === 0 ? '请求次数未记录' : `请求次数 ${failure.attempts}` : '请求次数未核实';
            const row = node('li', '', `第 ${failure.index + 1} 件：${reason}；${attempts}`);
            row.dataset.itemFailureIndex = String(failure.index);
            list.append(row);
        });
        details.append(list);
        return details;
    };

    const renderTasks = () => {
        const output = document.getElementById('catalog-hub-tasks');
        if (!output || !alive) return;
        if (!model.tasks.length) {
            output.replaceChildren(node('div', 'alert alert-secondary mb-0', '暂无后台任务。接入货源后，分析与入库会在这里显示。'));
            return;
        }
        const list = node('div', 'catalog-hub-stack');
        model.tasks.forEach(task => {
            const state = taskState(task);
            const card = node('article', 'catalog-hub-task catalog-hub-stack');
            card.id = `catalog-hub-task-${taskId(task)}`;
            const heading = node('div', 'catalog-hub-task__heading');
            heading.append(
                node('strong', '', task.source_alias || `货源 ${Number(task.source_id || 0)}`),
                node('span', `badge ${FINAL_TASK_STATES.has(state) ? 'badge-light' : 'badge-light-primary'}`, taskStateLabel(task))
            );
            card.append(
                heading,
                node('div', 'catalog-hub-muted', `阶段：${task.phase === 'analysis' ? '智能分析' : task.phase === 'import' ? '后台入库' : '等待准备'}`),
                node('div', '', taskProgressText(task))
            );
            if (task.premium_percent !== null && task.premium_percent !== undefined) {
                card.append(node('div', '', `本次已确认新商品加价：${premiumValue(task.premium_percent)}%`));
            }
            const retry = renderRetryProgress(task);
            if (retry) card.append(retry);
            card.append(node('p', 'mb-0', taskGuidance(task)));
            if (Number.isInteger(task.detail_compatibility_count) && task.detail_compatibility_count > 0 && task.detail_compatibility_count <= 10000) {
                card.append(node('div', 'alert alert-info py-2 mb-0', `兼容提示：${task.detail_compatibility_count} 次非标准详情标签已通过商品校验并完成处理；此提示不计入未导入项。`));
            }
            if (task.error_code) {
                const message = canCancelFailedImport(task) && !canResumeDetailFetch(task) && !canRetryFailed(task)
                    ? '该任务不能继续入库，可显式取消；已入库商品会保留。'
                    : typeof task.error_code === 'string' && Object.prototype.hasOwnProperty.call(TASK_ERROR_MESSAGES, task.error_code)
                        ? TASK_ERROR_MESSAGES[task.error_code] : '任务在处理过程中中断，请按当前状态指引处理；必要时联系管理员。';
                card.append(node('div', `alert ${taskFinishedWithIssues(task) ? 'alert-warning' : 'alert-danger'} py-2 mb-0`, message));
            }
            const itemFailures = renderItemFailures(task);
            if (itemFailures) card.append(itemFailures);
            card.append(notice(`task-${taskId(task)}`));
            const actions = node('div', 'catalog-hub-actions');
            if (['queued_analysis', 'analyzing', 'queued_import', 'importing'].includes(state)) {
                actions.append(taskActionButton(task, 'pause', '暂停', 'btn-light-warning'));
            }
            if (canResumeTask(task)) {
                const retry = retryProgress(task);
                actions.append(taskActionButton(task, 'resume', retry?.active === true ? '继续本次补处理' : '继续原任务', 'btn-light-primary'));
            }
            if (canRetryFailed(task)) {
                const retry = retryProgress(task);
                const label = retry?.halted && retry.cursor < retry.indices.length ? '继续本次补处理'
                    : retry && retry.cursor === retry.indices.length ? '开始新一轮未入库项补查' : '只重试未入库项';
                actions.append(taskActionButton(task, 'retry_failed', label, 'btn-light-primary'));
            }
            if ((!FINAL_TASK_STATES.has(state) && state !== 'cancel_requested') || canCancelFailedImport(task)) {
                actions.append(taskActionButton(task, 'cancel', state === 'failed' ? '取消后结束此任务' : '取消', 'btn-light-danger'));
            }
            if (actions.children.length) card.append(actions);
            const details = taskDisclosure(task, 'task', 'catalog-hub-muted', '任务详情');
            details.append(node('div', '', `任务编号：${taskId(task)}`));
            if (taskPlanHash(task)) details.append(node('code', 'catalog-hub-plan-hash', `方案编号：${taskPlanHash(task)}`));
            if (task.error_code) details.append(node('div', '', `错误代码：${typeof task.error_code === 'string' && Object.prototype.hasOwnProperty.call(TASK_ERROR_MESSAGES, task.error_code) ? task.error_code : '未分类'}`));
            if (/^[A-Za-z0-9_-]{8,80}$/.test(String(task.trace_id || ''))) details.append(node('div', '', `诊断编号：${task.trace_id}`));
            card.append(details);
            const diagnostic = renderDetailDiagnostic(task);
            if (diagnostic) card.append(diagnostic);
            list.append(card);
        });
        // Capture at the synchronous replacement boundary, not when a request starts.
        const disclosureState = new Map(Array.from(output.querySelectorAll('[data-task-disclosure]'), details =>
            [details.dataset.taskDisclosure, details.open]));
        const focused = document.activeElement;
        const focusKey = output.contains(focused) ? focused?.dataset.taskFocus : null;
        list.querySelectorAll('[data-task-disclosure]').forEach(details => {
            details.open = disclosureState.get(details.dataset.taskDisclosure) === true;
        });
        output.replaceChildren(list);
        if (focusKey) {
            // Only stable summaries and still-enabled top-level actions are eligible.
            const target = Array.from(list.querySelectorAll('[data-task-focus]'))
                .find(element => element.dataset.taskFocus === focusKey);
            if (target && !target.disabled) target.focus({preventScroll: true});
        }
    };

    const categorySuggestions = task => Array.isArray(task?.categories) ? task.categories : [];

    const confirmTask = async (task, container, button) => {
        const id = taskId(task);
        const scope = `confirm-${id}`;
        if (confirmingTasks.has(id)) return;
        captureConfirmationDraft();
        const categories = categorySuggestions(task);
        const rows = container.querySelectorAll('[data-mapping-row]');
        if (rows.length !== categories.length) {
            notify('分类建议已变化，请重新载入后再确认。', 'danger', scope);
            return;
        }
        const mappings = [];
        if (mirrorTask(task) && !mirrorCategoriesValid(categories)) {
            notify('镜像方案不完整，已阻止确认，请重新分析。', 'danger', scope);
            return;
        }
        for (let index = 0; index < rows.length; index += 1) {
            if (mirrorTask(task)) {
                mappings.push({source_category: categories[index].name,
                    target: categories[index].target, confidence: categories[index].confidence});
                continue;
            }
            const group = rows[index].querySelector('[data-mapping-group]').value.trim();
            const family = rows[index].querySelector('[data-mapping-family]').value.trim();
            if (!group) {
                notify(`第 ${index + 1} 条分类缺少一级分类。`, 'danger', scope);
                return;
            }
            mappings.push({
                source_category: String(categories[index]?.name || ''),
                target: {group, family},
                confidence: categories[index]?.confidence === 'high' ? 'high' : 'low'
            });
        }
        const premiumInput = container.querySelector('[data-premium-percent]');
        const premium = Number(premiumInput?.value ?? 0);
        if (!Number.isFinite(premium) || premium < 0 || premium > 1000) {
            notify('加价百分比必须是 0–1000 之间的数字。', 'danger', scope);
            return;
        }
        button.disabled = true;
        confirmingTasks.add(id);
        renderSuggestions();
        try {
            const payload = await post('/admin/api/localExtensions/catalogHubConfirm', {
                task_id: taskId(task),
                revision: String(Number(task.revision || 0)),
                plan_hash: taskPlanHash(task),
                premium_percent: String(premium),
                mappings_json: JSON.stringify(mappings)
            });
            const updated = payload.data?.task || payload.data?.detail;
            if (taskId(updated) !== id || taskState(updated) === 'awaiting_confirmation') throw new Error('task receipt unavailable');
            mergeTask(updated);
            dropStoredDraft(`task:${taskBinding(task)}`);
            dropStoredDraft(`task:${[id, Number(task.source_id), '', ''].join(':')}`);
            dropStoredDraft(`source:${Number(task.source_id)}`);
            sourceDrafts.delete(Number(task.source_id));
            taskDrafts.delete(id);
            taskPremiumSeeds.delete(id);
            renderSuggestions();
            renderTasks();
            renderSourceRows();
            notify('分类与本次新商品加价已确认，商品将在后台分批入库。', 'success', `task-${id}`);
        } catch (error) {
            notify(uncertainMutationText(error), 'danger', scope, error);
            if (alive) button.disabled = false;
        } finally {
            confirmingTasks.delete(id);
            if (alive) renderSuggestions();
            scheduleTaskPoll();
        }
    };

    const renderSuggestions = () => {
        const output = document.getElementById('catalog-hub-suggestions');
        if (!output) return;
        captureConfirmationDraft();
        const pending = model.tasks.filter(item => taskState(item) === 'awaiting_confirmation');
        const task = pending.find(item => taskId(item) === selectedConfirmation) || pending[0];
        if (!task) {
            renderedConfirmationKey = '';
            output.replaceChildren(node('div', 'alert alert-secondary mb-0', '分析完成后，在这里检查分类与本次新商品加价；确认前不会入库。'));
            return;
        }
        selectedConfirmation = taskId(task);
        const draft = confirmationDraft(task);
        const renderKey = [draft.binding, task.revision, pending.map(taskId).join(','), confirmingTasks.has(taskId(task))].join('|');
        if (renderedConfirmationKey === renderKey) return;
        renderedConfirmationKey = renderKey;
        const categories = categorySuggestions(task);
        const container = node('div', 'catalog-hub-stack');
        container.dataset.confirmationDraft = taskId(task);
        container.dataset.draftBinding = draft.binding;
        if (pending.length > 1) {
            const chooser = selectField('选择待确认货源', pending.map(item => [taskId(item), item.source_alias || `货源 ${item.source_id}`]), taskId(task), {confirmationTask: '1'});
            chooser.select.addEventListener('change', () => {
                captureConfirmationDraft();
                selectedConfirmation = chooser.select.value;
                renderSuggestions();
            });
            container.append(chooser.wrapper);
        }
        const counts = task.counts && typeof task.counts === 'object' ? task.counts : {};
        container.append(
            node('div', 'alert alert-info mb-0', `${task.source_alias || '货源'}：共 ${Number(counts.categories || categories.length)} 个上游分类，需要重点检查 ${Number(counts.low || 0)} 个。请先核对分类，再确认本次新商品加价。`),
            notice(`confirm-${taskId(task)}`)
        );
        if (draft.restored) container.append(node('p', 'text-warning mb-0', '已恢复本会话草稿，尚未确认。请重新核对分类与加价后再提交。'));
        const mappings = node('div', 'catalog-hub-mappings');
        if (mirrorTask(task)) container.append(node('p', 'alert alert-warning mb-0',
            '保留上游分类结构：以下路径来自同一份冻结方案，不插入后台货源名，也不重新归类。仅用于首次入库；后续上游改名、移动或删除不会自动同步。'));
        categories.forEach((category, index) => {
            const row = node('div', 'catalog-hub-mapping');
            row.dataset.mappingRow = '1';
            const source = node('div', 'catalog-hub-mapping__source');
            if (mirrorTask(task)) {
                row.className += ' catalog-hub-mapping--mirror';
                const path = Array.isArray(category?.target?.path) ? category.target.path : [];
                source.append(node('strong', '', path.map(part => part.name).join(' → ')),
                    node('span', 'catalog-hub-muted', `上游分类 ID ${path[path.length - 1]?.id || '?'} · ${Number(category?.count || 0)} 个商品`));
                row.append(source);
                mappings.append(row);
                return;
            }
            source.append(
                node('strong', '', category?.name || ''),
                node('span', category?.confidence === 'high' ? 'badge badge-light-success' : 'badge badge-light-warning', category?.confidence === 'high' ? '高信心' : '待确认'),
                node('span', 'catalog-hub-muted', `${Number(category?.count || 0)} 个商品`)
            );
            const group = inputField('一级分类', {
                value: draft.mappings[index]?.group || '',
                required: true,
                maxLength: 64,
                dataset: {mappingGroup: '1'}
            });
            const family = inputField('二级分类（可留空）', {
                value: draft.mappings[index]?.family || '',
                maxLength: 64,
                dataset: {mappingFamily: '1'}
            });
            group.input.addEventListener('input', captureConfirmationDraft);
            family.input.addEventListener('input', captureConfirmationDraft);
            group.input.disabled = confirmingTasks.has(taskId(task));
            family.input.disabled = confirmingTasks.has(taskId(task));
            row.append(source, group.wrapper, family.wrapper);
            mappings.append(row);
        });
        if (!categories.length) mappings.append(node('div', 'alert alert-warning mb-0', '任务没有返回可确认的分类建议。'));
        container.append(mappings);
        const footer = node('div', 'catalog-hub-confirm');
        const premium = inputField('本次新商品加价（%，默认 0）', {
            type: 'number',
            value: draft.premium,
            dataset: {premiumPercent: '1'}
        });
        premium.input.min = '0';
        premium.input.max = '1000';
        premium.input.step = '0.01';
        premium.input.disabled = confirmingTasks.has(taskId(task));
        premium.input.addEventListener('input', captureConfirmationDraft);
        const confirm = node('button', 'btn btn-primary', '确认并后台入库');
        confirm.type = 'button';
        confirm.disabled = categories.length === 0 || confirmingTasks.has(taskId(task))
            || (mirrorTask(task) && !mirrorCategoriesValid(categories));
        confirm.addEventListener('click', () => confirmTask(task, container, confirm));
        footer.append(premium.wrapper, confirm);
        container.append(footer);
        container.append(node('p', 'catalog-hub-muted mb-0', '只对本次新导入商品加价，不改变已存在商品价格。确认后沿用此值；继续任务不会重新加价。'));
        output.replaceChildren(container);
    };

    const loadTasks = async () => {
        if (!alive || tasksLoading || pollingSuspended) return;
        tasksLoading = true;
        const output = document.getElementById('catalog-hub-tasks');
        if (output && !model.tasks.length) output.replaceChildren(node('div', 'alert alert-secondary mb-0', '正在读取后台任务…'));
        try {
            const payload = await readTaskProgress();
            model.tasks = normalizeTaskList(payload.data || {});
            taskPollFailures = 0;
            if (alive) {
                renderTasks();
                renderSuggestions();
                renderSourceRows();
            }
        } catch (error) {
            taskPollFailures++;
            if (error.safeCode === 'LOCAL_EXTENSIONS_CSRF_INVALID' || taskPollFailures >= 3) pollingSuspended = true;
            if (alive && output) {
                renderTasks();
                const guidance = pollingSuspended
                    ? '自动更新已停止，请刷新页面或重新登录。'
                    : '暂时无法更新，将稍后重试。';
                output.append(node('div', 'alert alert-danger mb-0', `${error.message || '后台任务读取失败'}；${guidance}显示的是最后一次已获取的进度，草稿已保留。`));
            }
        } finally {
            tasksLoading = false;
            scheduleTaskPoll();
        }
    };

    const runAnalyze = async (sourceId, alias, button = null) => {
        const id = Number(sourceId);
        if (!Number.isInteger(id) || id < 1 || analyzingSources.has(id)) return;
        if (editingSourceId === id) {
            notify('此货源正在编辑。请先保存修改或取消编辑，再开始智能分析。', 'warning', `source-${id}`);
            return;
        }
        const normalizedAlias = String(alias || '').trim();
        if (!normalizedAlias) {
            notify('请先填写货源名称。', 'danger', `source-${id}`);
            return;
        }
        const premium = Number(sourceDraft(id).premium);
        const categoryMode = sourceCategoryMode(model.sources.find(source => Number(source.id) === id) || {id});
        if (!['smart', 'mirror'].includes(categoryMode)) {
            notify('分类模式不正确，请重新选择。', 'danger', `source-${id}`);
            return;
        }
        if (!Number.isFinite(premium) || premium < 0 || premium > 1000) {
            notify('本次新商品加价必须是 0–1000 之间的数字。', 'danger', `source-${id}`);
            return;
        }
        analyzingSources.add(id);
        if (button) button.disabled = true;
        if (alive) renderSourceRows();
        try {
            const payload = await post('/admin/api/localExtensions/catalogHubAnalyze', {
                source_id: String(id),
                alias: normalizedAlias,
                category_mode: categoryMode
            });
            const task = payload.data?.task || payload.data?.detail;
            const source = model.sources.find(item => Number(item.id) === id);
            if (source) source.category_mode = categoryMode;
            const savedAlias = model.settings.aliases?.find(entry => Number(entry.source_id) === id);
            if (savedAlias) savedAlias.category_mode = categoryMode;
            if (taskId(task)) {
                taskPremiumSeeds.set(taskId(task), String(premium));
                saveStoredDraft(`task:${taskBinding(task)}`, {premium: String(premium), mappings: []});
            }
            mergeTask(task);
            renderTasks();
            renderSuggestions();
            renderSourceRows();
            notify('分析任务已提交。完成后请检查分类与本次新商品加价，再确认入库。', 'success', `source-${id}`);
        } finally {
            analyzingSources.delete(id);
            if (alive && button) button.disabled = false;
            if (alive) renderSourceRows();
            scheduleTaskPoll();
        }
    };

    const sourceHasOpenTask = sourceId => model.tasks.some(task =>
        Number(task?.source_id) === Number(sourceId) && taskHasOpenWork(task)
    );

    const resetSourceEditor = () => {
        editingSourceId = 0;
        if (!sourceFormControls) return;
        const {protocol, alias, domain, appId, appKey, currency, rate, connect, cancel, premium} = sourceFormControls;
        protocol.value = '0';
        protocol.disabled = false;
        alias.value = '';
        alias.disabled = false;
        domain.value = '';
        domain.disabled = false;
        appId.value = '';
        appId.disabled = false;
        appKey.value = '';
        appKey.required = true;
        appKey.placeholder = '';
        currency.value = 'CNY';
        rate.value = '';
        connect.textContent = '测试并接入';
        cancel.hidden = true;
        cancel.disabled = false;
        premium.wrapper.hidden = false;
        sourceFormControls.categoryMode.wrapper.hidden = false;
        sourceFormControls.categoryMode.select.value = 'smart';
    };

    const startSourceEditor = source => {
        if (!sourceFormControls || connecting || sourceHasOpenTask(source.id)) return;
        if (editingSourceId > 0) {
            notify('请先保存当前修改或取消编辑，再切换货源。', 'warning');
            return;
        }
        editingSourceId = Number(source.id);
        const {protocol, alias, domain, appId, appKey, currency, rate, connect, cancel, premium} = sourceFormControls;
        protocol.value = String(Number(source.type));
        protocol.disabled = true;
        alias.value = sourceAlias(source);
        alias.disabled = false;
        domain.value = String(source.domain || '');
        domain.disabled = true;
        appId.value = String(source.app_id || '');
        appId.disabled = true;
        appKey.value = '';
        appKey.required = false;
        appKey.placeholder = '留空则保留现有密钥';
        currency.value = String(source.currency || 'CNY');
        const numericRate = Number(source.currency_rate || 0);
        rate.value = numericRate > 0 ? String(numericRate) : '';
        connect.textContent = '保存修改';
        cancel.hidden = false;
        premium.wrapper.hidden = true;
        sourceFormControls.categoryMode.wrapper.hidden = true;
        notify(sourceCategoryMode(source) === 'mirror'
            ? `正在编辑 ${sourceAlias(source)}；镜像模式的名称仅用于后台识别，不改变已导入分类。协议、地址和商户 ID 为绑定身份，密钥留空即保留。`
            : `正在编辑 ${sourceAlias(source)}；显示名称会联动更新此货源的受管货源层分类名称，不改变分类层级、商品归属或上游原始分类。协议、地址和商户 ID 为绑定身份，密钥留空即保留。`, 'info');
    };

    const renderSourceRows = () => {
        const output = document.getElementById('catalog-hub-sources');
        if (!output) return;
        if (!model.sources.length) {
            output.replaceChildren(node('div', 'alert alert-secondary mb-0', '尚未接入货源。请填写上方资料开始。'));
            return;
        }
        const list = node('div', 'catalog-hub-stack');
        model.sources.forEach(source => {
            const row = node('div', 'catalog-hub-source-row');
            const summary = node('div');
            const task = latestSourceTask(source.id);
            const protocolLabel = PROTOCOL_OPTIONS.find(option => option[0] === String(Number(source.type)))?.[1] || '未知协议';
            summary.append(
                node('strong', '', sourceAlias(source)),
                node('div', 'catalog-hub-muted', `${source.name || '共享店铺'} · ${protocolLabel} · ID ${Number(source.id)}`)
            );
            summary.append(node('p', 'mb-0 mt-2', task ? taskGuidance(task) : '已连接。设置本次新商品加价后点击智能分析，检查分类并确认后才会入库。'));
            if (task) summary.append(node('span', 'badge badge-light-primary', taskStateLabel(task)));
            const pendingConfirmation = taskState(task) === 'awaiting_confirmation';
            const openTask = task && taskHasOpenWork(task);
            const categoryMode = selectField('首次入库分类方式', CATEGORY_MODE_OPTIONS,
                openTask ? (task.category_mode || 'smart') : sourceCategoryMode(source), {sourceCategoryMode: String(source.id)});
            categoryMode.select.disabled = connecting || Boolean(openTask) || analyzingSources.has(Number(source.id));
            categoryMode.select.addEventListener('change', () => categoryModeDrafts.set(Number(source.id), categoryMode.select.value));
            summary.append(categoryMode.wrapper);
            const price = inputField('本次新商品加价（%，默认 0）', {
                type: 'number',
                value: pendingConfirmation ? confirmationDraft(task).premium
                    : openTask ? premiumValue(task.premium_percent ?? taskPremiumSeeds.get(taskId(task)) ?? readStoredDraft(`task:${taskBinding(task)}`)?.premium) : sourceDraft(source.id).premium,
                dataset: {sourcePremium: String(source.id)}
            });
            price.input.min = '0';
            price.input.max = '1000';
            price.input.step = '0.01';
            price.input.disabled = connecting || Boolean(openTask);
            price.input.addEventListener('input', () => setSourcePremium(source.id, price.input.value));
            summary.append(price.wrapper, notice(`source-${source.id}`));
            if (!openTask && sourceDraft(source.id).restored) summary.append(node('p', 'text-warning mb-0', '已恢复加价草稿；仅本次确认后生效。'));
            else if (!openTask && sourceDraft(source.id).origin === 'last-confirmed') summary.append(node('p', 'catalog-hub-muted mb-0', '沿用上次确认的加价，仅本次确认后生效；可在分析前修改。'));
            const actions = node('div', 'catalog-hub-actions');
            const busy = connecting
                || analyzingSources.has(Number(source.id))
                || sourceHasOpenTask(source.id);
            const edit = node('button', 'btn btn-sm btn-light-secondary', '编辑');
            edit.type = 'button';
            edit.dataset.editSource = String(source.id);
            edit.disabled = busy;
            edit.addEventListener('click', () => startSourceEditor(source));
            const inspectTask = task && (openTask || canCancelFailedImport(task));
            const analyze = node('button', 'btn btn-sm btn-light-primary', inspectTask
                ? pendingConfirmation ? '检查分类与加价' : canResumeDetailFetch(task) ? '查看并继续任务' : '查看任务'
                : task ? '重新分析' : '智能分析');
            analyze.type = 'button';
            analyze.dataset.analyzeSource = String(source.id);
            analyze.disabled = connecting || analyzingSources.has(Number(source.id));
            analyze.addEventListener('click', () => {
                if (inspectTask) return showTask(task);
                runAnalyze(source.id, sourceAlias(source), analyze).catch(error => notify(errorText(error, '智能分析启动失败'), 'danger', `source-${source.id}`, error));
            });
            actions.append(edit, analyze);
            row.append(summary, actions);
            list.append(row);
        });
        output.replaceChildren(list);
    };

    const renderSourcePanel = () => {
        const panel = node('section', 'catalog-hub-panel catalog-hub-stack');
        panel.append(
            node('h4', 'mb-0', '1. 货源管理'),
            node('p', 'catalog-hub-muted mb-0', '填写货源方提供的连接信息，先测试，再确认分类与加价。')
        );
        panel.append(node('p', 'catalog-hub-muted mb-0', '按上游说明选择版本；不确定先询问货源方。'));
        const form = node('div', 'catalog-hub-source-form');
        form.id = 'catalog-hub-source-form';
        const protocol = selectField('协议版本', PROTOCOL_OPTIONS, '0', {sourceProtocol: '1'});
        const categoryMode = selectField('首次入库分类方式', CATEGORY_MODE_OPTIONS, 'smart', {sourceFormCategoryMode: '1'});
        categoryMode.wrapper.append(node('span', 'catalog-hub-muted', '镜像需上游配套扩展；只保留首次入库结构，显示名称不进入分类。已有映射不能切换模式。'));
        protocol.select.id = 'catalog-hub-protocol';
        const alias = inputField('货源显示名称', {
            placeholder: '例如：货源A',
            maxLength: 64,
            required: true,
            dataset: {sourceFormAlias: '1'}
        });
        const domain = inputField('店铺地址', {
            placeholder: 'https://example.com',
            maxLength: 128,
            required: true,
            autocomplete: 'url',
            dataset: {sourceFormDomain: '1'}
        });
        const appId = inputField('商户 ID', {
            maxLength: 32,
            required: true,
            autocomplete: 'off',
            dataset: {sourceFormAppId: '1'}
        });
        const appKey = inputField('商户密钥', {
            type: 'password',
            maxLength: 64,
            required: true,
            autocomplete: 'off',
            dataset: {sourceFormAppKey: '1'}
        });
        const currency = selectField('对方货币', CURRENCY_OPTIONS.map(value => [value, value]), 'CNY', {sourceCurrency: '1'});
        const rate = inputField('结算汇率（可留空）', {
            placeholder: '1 对方货币 = ? 本站货币',
            maxLength: 16,
            dataset: {sourceCurrencyRate: '1'}
        });
        const premium = inputField('本次新商品加价（%，默认 0）', {
            type: 'number', value: sourceDraft(0).premium, dataset: {sourceFormPremium: '1'}
        });
        premium.input.min = '0';
        premium.input.max = '1000';
        premium.input.step = '0.01';
        premium.input.addEventListener('input', () => setSourcePremium(0, premium.input.value));
        if (sourceDraft(0).restored) premium.wrapper.append(node('span', 'text-warning', '已恢复加价草稿，尚未确认。'));
        const connect = node('button', 'btn btn-primary catalog-hub-source-form__submit', '测试并接入');
        connect.type = 'button';
        connect.dataset.connectSource = '1';
        const cancel = node('button', 'btn btn-light-secondary', '取消编辑');
        cancel.type = 'button';
        cancel.dataset.cancelSourceEdit = '1';
        cancel.hidden = true;
        cancel.addEventListener('click', () => {
            if (!connecting) resetSourceEditor();
        });
        sourceFormControls = {
            categoryMode,
            protocol: protocol.select,
            alias: alias.input,
            domain: domain.input,
            appId: appId.input,
            appKey: appKey.input,
            currency: currency.select,
            rate: rate.input,
            connect,
            cancel,
            premium
        };
        connect.addEventListener('click', async () => {
            if (connecting) return;
            const editing = editingSourceId > 0;
            const targetSourceId = editing ? Number(editingSourceId) : 0;
            const nextPremium = Number(premium.input.value);
            if (!editing && (!Number.isFinite(nextPremium) || nextPremium < 0 || nextPremium > 1000)) {
                notify('本次新商品加价必须是 0–1000 之间的数字。');
                return;
            }
            const aliasValue = alias.input.value.trim();
            const domainValue = domain.input.value.trim();
            const appIdValue = appId.input.value.trim();
            const appKeyValue = appKey.input.value;
            const rateValue = rate.input.value.trim();
            let parsedDomain;
            try {
                parsedDomain = new URL(domainValue);
            } catch (_) {
                notify('店铺地址必须是完整的 HTTPS 地址。');
                return;
            }
            if (!aliasValue || aliasValue.length > 64) {
                notify('货源显示名称必须是 1–64 个字符。');
                return;
            }
            if (parsedDomain.protocol !== 'https:'
                || parsedDomain.username
                || parsedDomain.password
                || (parsedDomain.port && parsedDomain.port !== '443')
                || parsedDomain.pathname !== '/'
                || parsedDomain.search
                || parsedDomain.hash
                || parsedDomain.origin.length > 128) {
                notify('店铺地址只允许不含路径、参数或账号密码的 HTTPS 标准 443 根地址。');
                return;
            }
            if (!/^[A-Za-z0-9._:@-]{1,32}$/.test(appIdValue)) {
                notify('商户 ID 必须是 1–32 位字母、数字或 . _ : @ -。');
                return;
            }
            if ((!editing || appKeyValue !== '') && !/^[^\s\x00-\x1F\x7F]{8,64}$/.test(appKeyValue)) {
                notify('商户密钥必须是 8–64 位且不能包含空白字符。');
                return;
            }
            if (rateValue && (!/^\d{1,9}(\.\d{1,6})?$/.test(rateValue) || Number(rateValue) > 999999999 || Number(rateValue) < 0.000001)) {
                notify('结算汇率必须是有效数字，最多 6 位小数。');
                return;
            }
            connecting = true;
            connect.disabled = true;
            cancel.disabled = true;
            renderSourceRows();
            const sourceData = {
                type: protocol.select.value,
                domain: parsedDomain.origin,
                app_id: appIdValue,
                app_key: appKeyValue,
                currency: currency.select.value,
                currency_rate: rateValue
            };
            try {
                notify(editing ? '正在安全修改货源…' : '正在安全验证并保存货源…', 'info');
                const saved = await post(
                    editing
                        ? '/admin/api/localExtensions/catalogHubSourceUpdate'
                        : '/admin/api/localExtensions/catalogHubConnect',
                    editing ? {source_id: String(targetSourceId), alias: aliasValue, ...sourceData} : sourceData
                );
                appKey.input.value = '';
                sourceData.app_key = '';
                if (editing) {
                    const updated = saved.data?.source;
                    if (!updated || Number(updated.id) !== targetSourceId) {
                        notify('货源已修改，但服务没有返回完整记录，请刷新页面确认。', 'warning');
                        return;
                    }
                    mergeSource(targetSourceId, aliasValue, updated);
                    resetSourceEditor();
                    renderSourceRows();
                    notify('货源修改成功。', 'success');
                    return;
                }
                const savedId = Number(saved.data?.source_id || 0);
                if (!Number.isInteger(savedId) || savedId < 1) {
                    notify('货源已保存，但没有返回货源编号。请刷新页面后重试智能分析。', 'warning');
                    return;
                }
                mergeSource(savedId, aliasValue, sourceData);
                categoryModeDrafts.set(savedId, categoryMode.select.value);
                setSourcePremium(savedId, String(nextPremium));
                sourceDrafts.delete(0);
                dropStoredDraft('source:0');
                premium.input.value = '0';
                notify('货源接入成功，正在提交智能分析；进度请查看对应货源卡片。', 'success');
                renderSourceRows();
                try {
                    await runAnalyze(savedId, aliasValue);
                } catch (error) {
                    notify('货源已保存，智能分析结果尚未核实。请先刷新任务列表，确认没有新任务后再操作。', 'warning', 'source-form', error);
                }
            } catch (error) {
                notify(error.message || (editing ? '货源修改失败' : '货源测试或保存失败'));
            } finally {
                sourceData.app_key = '';
                appKey.input.value = '';
                connecting = false;
                if (alive) {
                    connect.disabled = false;
                    cancel.disabled = false;
                    renderSourceRows();
                }
            }
        });
        form.append(
            protocol.wrapper, alias.wrapper, domain.wrapper, appId.wrapper,
            appKey.wrapper, currency.wrapper, rate.wrapper, categoryMode.wrapper, premium.wrapper, connect, cancel
        );
        const sourceList = node('div', 'catalog-hub-stack');
        sourceList.id = 'catalog-hub-sources';
        const nativeManagement = node('a', 'btn btn-sm btn-light-secondary align-self-start', '高级：打开异次元原生店铺共享（仅管理，不入库）');
        nativeManagement.href = '/admin/store/index';
        nativeManagement.dataset.nativeSourceManagement = '1';
        const managementDetails = node('details', 'catalog-hub-advanced');
        managementDetails.append(
            node('summary', '', '底层共享记录管理'),
            node('p', 'catalog-hub-muted mt-2', '原生页面只用于查看底层共享记录，或在停止任务、停止同步、完成备份和引用盘点后处理绑定身份或删除。不要在那里再次执行商品入库。'),
            nativeManagement
        );
        panel.append(
            notice('source-form'),
            form,
            node('p', 'catalog-hub-muted mb-0', '加价仅用于本次新导入商品，不是货源长期价格设置；默认 0%，不会改变已存在商品。'),
            node('h5', 'mb-0 mt-2', '已接入货源'),
            sourceList,
            managementDetails
        );
        return panel;
    };


    const renderClassificationPanel = () => {
        const panel = node('section', 'catalog-hub-panel catalog-hub-stack');
        panel.append(
            node('h4', 'mb-0', '2. 确认分类与本次加价'),
            node('p', 'catalog-hub-muted mb-0', '插件会优先使用精确上游分类，再使用平台别名建议分类。低信心项目可在确认前直接修改；确认前不会创建分类或写入商品。')
        );
        const suggestions = node('div', 'catalog-hub-stack');
        suggestions.id = 'catalog-hub-suggestions';
        panel.append(suggestions);
        return panel;
    };

    const renderTaskPanel = () => {
        const panel = node('section', 'catalog-hub-panel catalog-hub-stack');
        panel.append(
            node('h4', 'mb-0', '3. 后台任务'),
            node('p', 'catalog-hub-muted mb-0', '大型货源会分批在后台处理。暂停或取消会在当前商品安全完成后生效，不会强制中断数据库交易。')
        );
        const tasks = node('div', 'catalog-hub-stack');
        tasks.id = 'catalog-hub-tasks';
        panel.append(tasks);
        return panel;
    };

    const render = () => {
        const shell = node('div', 'catalog-hub-stack');
        const draftStatus = node('div');
        draftStatus.id = 'catalog-hub-draft-status';
        shell.append(draftStatus, renderSourcePanel(), renderClassificationPanel(), renderTaskPanel());
        root.replaceChildren(shell);
        renderSourceRows();
        renderSuggestions();
        renderTasks();
        renderDraftStorageNotice();
    };

    const load = async () => {
        const payload = await post('/admin/api/localExtensions/catalogHubBootstrap', {});
        const data = payload.data || {};
        model = {
            sources: Array.isArray(data.sources) ? data.sources : [],
            settings: data.settings && typeof data.settings === 'object'
                ? data.settings
                : {schema: 1, aliases: [], rules: []},
            tasks: []
        };
        if (!alive) return;
        initializeDraftStorage(data.draft_scope);
        render();
        await loadTasks();
    };

    $(document).one('pjax:beforeReplace.catalogHub', () => {
        alive = false;
        stopRequests();
    });
    load().catch(error => {
        if (alive) root.replaceChildren(node('div', 'alert alert-danger', error.message || '智能货源中心读取失败'));
    });
}();
