(function () {
    'use strict';
    const request = async (route, data, signal) => {
        const timeout = new AbortController();
        const cancel = () => timeout.abort();
        signal.addEventListener('abort', cancel, {once: true});
        if (signal.aborted) cancel();
        const timer = setTimeout(cancel, 30000);
        try {
            const response = await fetch('/admin/api/localExtensions/' + route, {
                method: 'POST', credentials: 'same-origin', signal: timeout.signal,
                headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                body: new URLSearchParams(data).toString()
            });
            const result = await response.json();
            if (!response.ok || result?.code !== 200) throw new Error(result?.msg || '货源名称操作失败，请刷新后重试。');
            return result.data;
        } catch (error) {
            if (timeout.signal.aborted && !signal.aborted) throw new Error('请求超时，保存结果尚未确认，请刷新后核对；系统不会自动重试。');
            throw error;
        } finally {
            clearTimeout(timer);
            signal.removeEventListener('abort', cancel);
        }
    };
    window.PikaCategoryRename = {
        mount(form, dom, csrfToken) {
            const categoryId = Number(form.opt?.assign?.id);
            if (!Number.isInteger(categoryId) || categoryId < 1) return null;
            const controller = new AbortController();
            let disposed = false;
            const alive = () => !disposed && !form.isDestroyed;
            const open = info => {
                let saving = false;
                component.popup({
                    title: '修改货源显示名',
                    tab: [{name: '同步修改货源显示名', form: [
                        {name: 'alias', title: '货源显示名', type: 'input', required: true,
                            default: info.alias, preserveLiteral: true},
                        {name: 'scope', type: 'custom', submit: false, complete: (_, target) => {
                            target.text('本次保存将同步该货源的 ' + info.source_node_count + ' 个货源分类名称；商品、分类 ID 和原始子分类名称保持原样。其他分类字段请在原窗口单独保存。');
                        }}
                    ]}],
                    width: '540px', height: 'auto', autoPosition: true,
                    submit: async (data, index) => {
                        if (!alive()) return;
                        if (saving) return;
                        saving = true;
                        try {
                            const result = await request('catalogHubCategoryRename', {
                                csrf_token: csrfToken, category_id: categoryId, alias: data.alias
                            }, controller.signal);
                            if (!alive()) return;
                            const saved = result?.category;
                            if (!saved || saved.category_id !== categoryId || saved.source_id !== info.source_id
                                || typeof saved.alias !== 'string' || saved.alias.trim() === '') {
                                throw new Error('保存响应不完整，结果尚未确认，请刷新后核对。');
                            }
                            const alias = saved.alias;
                            form.setTextarea('name', alias);
                            layer.close(index);
                            const table = $('#category-table').data('adminTable');
                            if (table && typeof table.refresh === 'function') table.refresh();
                            message.success('货源显示名和所属货源分类已同步保存。');
                            info.alias = alias;
                        } catch (error) {
                            if (alive() && error.name !== 'AbortError') message.error(error.message);
                        } finally {
                            saving = false;
                        }
                    }
                });
            };
            request('catalogHubCategoryInfo', {csrf_token: csrfToken, category_id: categoryId}, controller.signal)
                .then(info => {
                    if (!alive() || !info || info.managed === false) return;
                    const managed = info.category;
                    if (!managed || managed.category_id !== categoryId || typeof managed.alias !== 'string') return;
                    if (!Number.isInteger(managed.source_id) || !Number.isInteger(managed.source_node_count)) return;
                    const nameField = form.form?.name;
                    if (nameField) { nameField.submit = false; nameField.required = false; }
                    $('.' + form.getUnique() + ' textarea[name="name"]').prop('readOnly', true);
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'btn btn-sm btn-light-primary';
                    button.textContent = '修改货源显示名（同步所有分类）';
                    button.addEventListener('click', () => open(managed));
                    dom.empty().append(button);
                })
                .catch(error => { if (alive() && error.name !== 'AbortError') dom.text('无法读取联动改名信息，请刷新后重试。'); });
            return {destroy() { disposed = true; controller.abort(); }};
        }
    };
}());
