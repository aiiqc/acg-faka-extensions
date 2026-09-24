(function (root, factory) {
    'use strict';

    const api = factory(root, root && root.document ? root.document : null);
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.PikaOrderReturnWait = api;
        api.mount();
    }
}(typeof window !== 'undefined' ? window : globalThis, function (window, document) {
    'use strict';

    const TRADE_NO_PATTERN = /^\d{18}$/;
    const GUEST_ENDPOINT = '/user/api/index/query';
    const MEMBER_ENDPOINT = '/user/api/purchaseRecord/data';
    const BALANCE_PAY_ID = 1;
    const MAX_AUTOMATIC_RELOADS = 15;

    function exactOrder(rows, tradeNo) {
        if (!TRADE_NO_PATTERN.test(String(tradeNo || '')) || !Array.isArray(rows)) {
            return null;
        }
        return rows.find(order => String(order && order.trade_no || '') === tradeNo) || null;
    }

    function payId(order) {
        return Number(order && (order.pay_id ?? order.pay?.id) || 0);
    }

    function isRemotePending(order, tradeNo) {
        return Boolean(
            order
            && String(order.trade_no || '') === tradeNo
            && Number(order.status) === 0
            && payId(order) > 0
            && payId(order) !== BALANCE_PAY_ID
        );
    }

    function isResolved(order, tradeNo) {
        return Boolean(
            order
            && String(order.trade_no || '') === tradeNo
            && Number(order.status) === 1
        );
    }

    function responseRows(payload) {
        return Array.isArray(payload?.data?.list) ? payload.data.list : [];
    }

    function normalizedSchedule(waitSeconds, refreshSeconds) {
        const waitMilliseconds = Math.max(5, Math.min(120, Number(waitSeconds) || 15)) * 1000;
        const requestedMilliseconds = Math.max(1, Math.min(10, Number(refreshSeconds) || 2)) * 1000;
        return {
            waitMilliseconds,
            refreshMilliseconds: Math.max(
                requestedMilliseconds,
                Math.ceil(waitMilliseconds / MAX_AUTOMATIC_RELOADS)
            ),
            maxReloads: MAX_AUTOMATIC_RELOADS,
        };
    }

    function parseWaitState(raw, now, waitMilliseconds) {
        if (!raw) {
            return {deadline: now + waitMilliseconds, reloads: 0};
        }
        let state;
        try {
            state = JSON.parse(raw);
        } catch (error) {
            return null;
        }
        const deadline = Number(state?.deadline);
        const reloads = Number(state?.reloads);
        if (!Number.isSafeInteger(deadline) || deadline <= 0
            || !Number.isSafeInteger(reloads) || reloads < 0 || reloads > MAX_AUTOMATIC_RELOADS) {
            return null;
        }
        return {deadline, reloads};
    }

    function endpointPath(url) {
        try {
            return new URL(String(url || ''), window.location.origin).pathname;
        } catch (error) {
            return '';
        }
    }

    function mount() {
        if (!document) {
            return false;
        }

        const rootElement = document.querySelector('[data-pika-order-wait]');
        if (!rootElement || rootElement.dataset.mounted === '1') {
            return false;
        }

        const tradeNo = String(rootElement.dataset.tradeNo || '');
        const mode = rootElement.dataset.mode === 'member' ? 'member' : 'guest';
        if (!TRADE_NO_PATTERN.test(tradeNo)) {
            return false;
        }

        rootElement.dataset.mounted = '1';
        const schedule = normalizedSchedule(rootElement.dataset.waitSeconds, rootElement.dataset.refreshSeconds);
        const messageElement = rootElement.querySelector('[data-pika-order-wait-message]');
        const cancelButton = rootElement.querySelector('[data-pika-order-wait-cancel]');
        const queryLink = rootElement.querySelector('[data-pika-order-wait-query]');
        const storageKey = `pika-order-wait:${tradeNo}`;
        let waiting = false;
        let terminal = false;
        let refreshTimer = 0;
        let deadlineTimer = 0;
        let observer = null;
        let scanTimer = 0;
        let waitState = null;

        const clearTimers = () => {
            window.clearTimeout(refreshTimer);
            window.clearTimeout(deadlineTimer);
            refreshTimer = 0;
            deadlineTimer = 0;
        };

        const clearStoredState = () => {
            try {
                window.sessionStorage.removeItem(storageKey);
            } catch (error) {
                // Storage can be unavailable in hardened/private browser modes.
            }
        };

        const disconnect = () => {
            observer?.disconnect();
            observer = null;
            window.clearTimeout(scanTimer);
            scanTimer = 0;
            const jquery = window.jQuery;
            if (jquery) {
                jquery(document).off('.pikaOrderReturnWait');
                jquery('#bill-table').off('.pikaOrderReturnWait');
            }
        };

        const finish = (hide, removeStoredState = true) => {
            terminal = true;
            clearTimers();
            disconnect();
            waiting = false;
            rootElement.hidden = hide;
            if (removeStoredState) {
                clearStoredState();
            }
        };

        const stop = () => {
            finish(true);
        };

        const timeout = () => {
            finish(false);
            rootElement.classList.add('pika-order-wait--timeout');
            if (messageElement) {
                messageElement.textContent = '暂时还没有取得发货结果。请稍后在订单查询页重新查询，或联系客服。';
            }
        };

        const storageUnavailable = () => {
            finish(false, false);
            rootElement.classList.add('pika-order-wait--timeout');
            if (messageElement) {
                messageElement.textContent = '当前浏览器无法安全地自动刷新，请点击“查询订单”手动查看结果。';
            }
        };

        const loadState = () => {
            try {
                const state = parseWaitState(
                    window.sessionStorage.getItem(storageKey),
                    Date.now(),
                    schedule.waitMilliseconds
                );
                if (state === null) {
                    return null;
                }
                window.sessionStorage.setItem(storageKey, JSON.stringify(state));
                return state;
            } catch (error) {
                return null;
            }
        };

        const saveState = state => {
            try {
                window.sessionStorage.setItem(storageKey, JSON.stringify(state));
                return true;
            } catch (error) {
                return false;
            }
        };

        const start = () => {
            if (waiting || terminal) {
                return;
            }
            waiting = true;
            rootElement.classList.remove('pika-order-wait--timeout');
            rootElement.hidden = false;

            waitState = loadState();
            if (waitState === null) {
                storageUnavailable();
                return;
            }
            const remaining = waitState.deadline - Date.now();
            if (remaining <= 0 || waitState.reloads >= schedule.maxReloads) {
                timeout();
                return;
            }
            if (messageElement) {
                messageElement.textContent = '请稍候，页面会自动刷新。';
            }

            deadlineTimer = window.setTimeout(timeout, remaining);
            refreshTimer = window.setTimeout(() => {
                waitState.reloads += 1;
                if (!saveState(waitState)) {
                    storageUnavailable();
                    return;
                }
                window.location.reload();
            }, Math.min(schedule.refreshMilliseconds, remaining));
        };

        const inspectRows = rows => {
            if (terminal) {
                return false;
            }
            const order = exactOrder(rows, tradeNo);
            if (isResolved(order, tradeNo)) {
                stop();
                return true;
            }
            if (isRemotePending(order, tradeNo)) {
                start();
                return true;
            }
            return false;
        };

        const inspectMemberTable = () => {
            if (terminal) {
                return false;
            }
            const jquery = window.jQuery;
            const table = jquery && jquery('#bill-table').data('adminTable');
            return Boolean(table && typeof table.getRows === 'function' && inspectRows(table.getRows()));
        };

        // Guest query results are rendered by the official query controller. A pending
        // order can only come from the remote-payment branch: free and balance orders
        // are completed before their result URL is returned.
        const inspectGuestDom = () => {
            if (terminal) {
                return false;
            }
            const items = Array.from(document.querySelectorAll('.order-item'));
            const item = items.find(candidate =>
                String(candidate.querySelector('.order-no-text')?.textContent || '').trim() === tradeNo
            );
            if (!item) {
                return false;
            }
            if (item.querySelector('.status-paid')) {
                stop();
                return true;
            }
            if (item.querySelector('.status-pending') && item.querySelector('.payment-method')) {
                start();
                return true;
            }
            return false;
        };

        const inspectPage = () => mode === 'member' ? inspectMemberTable() : inspectGuestDom();

        if (window.jQuery) {
            window.jQuery(document)
                .off('ajaxSuccess.pikaOrderReturnWait')
                .on('ajaxSuccess.pikaOrderReturnWait', (event, xhr, settings) => {
                    const path = endpointPath(settings && settings.url);
                    if ((mode === 'guest' && path !== GUEST_ENDPOINT)
                        || (mode === 'member' && path !== MEMBER_ENDPOINT)) {
                        return;
                    }
                    let payload = xhr && xhr.responseJSON;
                    if (!payload && xhr && typeof xhr.responseText === 'string') {
                        try {
                            payload = JSON.parse(xhr.responseText);
                        } catch (error) {
                            payload = null;
                        }
                    }
                    inspectRows(responseRows(payload));
                })
                .off('pjax:beforeReplace.pikaOrderReturnWait')
                .on('pjax:beforeReplace.pikaOrderReturnWait', () => finish(true, false));

            if (mode === 'member') {
                window.jQuery('#bill-table')
                    .off('.pikaOrderReturnWait')
                    .on('admin:table:ready.pikaOrderReturnWait admin:table:update.pikaOrderReturnWait', inspectMemberTable);
            }
        }

        cancelButton?.addEventListener('click', stop);
        queryLink?.addEventListener('click', event => {
            event.preventDefault();
            const url = String(queryLink.getAttribute('href') || rootElement.dataset.queryUrl || '');
            finish(true);
            if (url) {
                window.location.assign(url);
            }
        });

        if (!inspectPage()) {
            observer = new window.MutationObserver(() => {
                if (terminal || inspectPage()) {
                    observer?.disconnect();
                    observer = null;
                }
            });
            observer.observe(document.body, {childList: true, subtree: true});
            scanTimer = window.setTimeout(() => {
                observer?.disconnect();
                observer = null;
            }, 10000);
        }

        window.addEventListener('pagehide', () => {
            finish(true, false);
        }, {once: true});

        return true;
    }

    return {
        exactOrder,
        isRemotePending,
        isResolved,
        responseRows,
        normalizedSchedule,
        parseWaitState,
        mount,
    };
}));
