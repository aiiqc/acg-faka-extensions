!function () {
    const safeDom = window.PikaSafeDom;

    function _Text(value) {
        return safeDom.plainText(value);
    }

    function _Translate(value) {
        return _Text(i18n(_Text(value)));
    }

    function _Element(tagName, className = "", text) {
        const element = document.createElement(tagName);
        if (className) {
            element.className = className;
        }
        if (text !== undefined) {
            safeDom.setText(element, text);
        }
        return element;
    }

    function _Icon(className) {
        const icon = _Element("i", className);
        icon.setAttribute("aria-hidden", "true");
        return icon;
    }

    function _QueryOrders(keywords) {
        util.post({
            url: "/user/api/index/query",
            data: {
                keywords: keywords,
                page: 1,
                limit: 10
            },
            loader: false,
            done: res => {
                _HideLoading();
                if (res?.data?.total == 0) {
                    _ShowNoResults();
                    return;
                }
                _ShowResults(res?.data?.list ?? []);
            },
            error: () => {
                _HideLoading();
                _ShowNoResults();
            },
            fail: () => {
                _HideLoading();
                _ShowNoResults();
            }
        });
    }

    function _ShowLoading() {
        $('.order-results').hide();
        $('.no-results').hide();
        $('.loading-state').show();
    }

    function _HideLoading() {
        $('.loading-state').hide();
    }

    function _ShowNoResults() {
        $('.order-results').hide();
        $('.no-results').show();
    }

    function _GetStatusClass(status) {
        const classMap = {
            0: 'pending',
            1: 'paid',
            2: 'shipped',
            3: 'waiting-shipment'
        };
        return classMap[status] || 'pending';
    }

    function _CreateStatusBadge(status) {
        const badge = _Element("span", `status-badge status-${_GetStatusClass(status)}`);
        const statusMap = {
            0: ["fa-duotone fa-regular fa-clock me-1", "待付款"],
            1: ["fa-duotone fa-regular fa-circle-check me-1", "已付款"]
        };
        const definition = statusMap[status];
        if (definition) {
            badge.append(_Icon(definition[0]), document.createTextNode(_Translate(definition[1])));
        } else {
            safeDom.setText(badge, _Translate("未知状态"));
        }
        return badge;
    }

    function _CreateShipmentBadge(status) {
        const paid = status == 1;
        return _Element(
            "span",
            `shipment-badge ${paid ? "shipment-paid" : "shipment-waiting"}`,
            _Translate(paid ? "已发货" : "等待发货")
        );
    }

    function _AppendInfoLine(parent, className, label, value) {
        const line = _Element("div", className, _Translate(label));
        line.appendChild(_Element("span", `${className}-text`, _Text(value)));
        parent.appendChild(line);
    }

    function _AppendSku(parent, className, label, value) {
        parent.appendChild(
            _Element("span", `goods-sku a-badge ${className}`, `${_Translate(label)}: ${_Translate(value)}`)
        );
    }

    function _AppendPlainCardContent(parent, secret, leaveMessage) {
        const wrapper = _Element("div", "card-content-no-password");
        wrapper.appendChild(_Element("div", "card-display", _Text(secret)));
        parent.appendChild(wrapper);
        if (_Text(leaveMessage)) {
            parent.appendChild(_Element("div", "mt-3", _Text(leaveMessage)));
        }
    }

    function _CreatePasswordSection(tradeNo) {
        const fragment = document.createDocumentFragment();
        const section = _Element("div", "card-password-section");
        const passwordForm = _Element("div", "password-form");
        const inputGroup = _Element("div", "input-group");
        const input = _Element("input", "form-control card-password-input");
        input.type = "password";
        input.placeholder = _Translate("请输入查询密码");

        const button = _Element("button", "btn btn-primary view-card-btn");
        button.type = "button";
        button.dataset.no = _Text(tradeNo);
        button.append(
            _Icon("fa-duotone fa-regular fa-eye me-2"),
            document.createTextNode(_Translate("查看卡密"))
        );
        inputGroup.append(input, button);
        passwordForm.appendChild(inputGroup);
        section.appendChild(passwordForm);

        const loading = _Element("div", "card-loading");
        loading.hidden = true;
        const loadingContent = _Element("div", "loading-content");
        loadingContent.append(
            _Icon("fa-duotone fa-regular fa-spinner-third icon-spin"),
            _Element("span", "", `${_Translate("正在解密数据")}...`)
        );
        loading.appendChild(loadingContent);
        fragment.append(section, loading);
        return fragment;
    }

    function _CreateCardSection(order) {
        if (order.status != 1) {
            return null;
        }

        const section = _Element("div", "card-section");
        const header = _Element("div", "card-header");
        const title = _Element("div", "card-title shipment-content");
        title.style.fontSize = "1.2rem";
        const shipmentTitle = _Element("div", "shipment-title");
        shipmentTitle.append(
            _Icon("fa-duotone fa-regular fa-gift me-1"),
            document.createTextNode(_Translate("宝贝内容"))
        );
        const shipmentStatus = _Element("div", "shipment-status");
        shipmentStatus.appendChild(_CreateShipmentBadge(order.delivery_status));
        title.append(shipmentTitle, shipmentStatus);
        header.appendChild(title);
        section.appendChild(header);

        if (order.password === true) {
            section.appendChild(_CreatePasswordSection(order.trade_no));
        } else {
            _AppendPlainCardContent(section, order.secret, order?.commodity?.leave_message);
        }
        return section;
    }

    function _CreateOrderItem(order) {
        const item = _Element("div", "order-item");
        item.dataset.tradeNo = _Text(order.trade_no);

        const header = _Element("div", "order-header");
        const left = _Element("div", "order-left");
        const status = _Element("div", "order-status");
        status.appendChild(_CreateStatusBadge(order.status));
        const basic = _Element("div", "order-basic");

        const orderNo = _Element("div", "order-no", "#");
        orderNo.appendChild(_Element("span", "order-no-text", _Text(order.trade_no)));
        basic.appendChild(orderNo);
        _AppendInfoLine(basic, "order-time", "下单时间：", order.create_time);
        _AppendInfoLine(basic, "payment-time", "付款时间：", order.pay_time ?? "-");

        const paymentDestination = _Element("div", "payment-dst", _Translate("支付方式："));
        const paymentMethod = _Element("span", "payment-method");
        const paymentIcon = _Element("img", "payment-icon");
        paymentIcon.src = safeDom.safeResourceUrl(order?.pay?.icon, "/favicon.ico");
        paymentIcon.alt = _Translate("支付方式");
        paymentMethod.append(paymentIcon, _Element("span", "payment-name", _Text(order?.pay?.name)));
        paymentDestination.appendChild(paymentMethod);
        basic.appendChild(paymentDestination);
        left.append(status, basic);

        const right = _Element("div", "order-right");
        const amount = _Element("div", "order-amount");
        amount.appendChild(_Element("span", "amount-label", _Translate("订单金额")));
        const amountValue = _Element("span", "amount-value", format.currencySymbol());
        amountValue.appendChild(_Element("span", "amount-number", _Text(order.amount)));
        amount.appendChild(amountValue);
        right.appendChild(amount);
        header.append(left, right);
        item.appendChild(header);

        const goods = _Element("div", "goods-section");
        const thumb = _Element("div", "goods-thumb");
        const goodsImage = _Element("img", "goods-image");
        goodsImage.src = safeDom.safeResourceUrl(order?.commodity?.cover, "/favicon.ico");
        goodsImage.alt = _Translate("商品图片");
        thumb.appendChild(goodsImage);

        const details = _Element("div", "goods-details");
        details.appendChild(_Element("h6", "goods-name", _Text(order?.commodity?.name)));
        const meta = _Element("div", "goods-meta");
        if (_Text(order.race)) {
            _AppendSku(meta, "a-badge-success", "商品类型", order.race);
        }
        if (!util.isEmptyOrNotJson(order?.sku)) {
            Object.keys(order.sku).forEach(skuKey => {
                _AppendSku(meta, "a-badge-primary", skuKey, order.sku[skuKey]);
            });
        }
        _AppendSku(meta, "a-badge-warning", "数量", order.card_num);
        details.appendChild(meta);
        goods.append(thumb, details);
        item.appendChild(goods);

        const cardSection = _CreateCardSection(order);
        if (cardSection) {
            item.appendChild(cardSection);
        }
        return item;
    }

    function _ShowResults(orders) {
        $('.order-results').show();
        $('.no-results').hide();
        const orderList = $('.order-list');
        orderList.empty();
        (Array.isArray(orders) ? orders : []).forEach(order => {
            orderList.append(_CreateOrderItem(order || {}));
        });
    }

    function _ShowPasswordLoading($order) {
        $order.find('.card-loading').prop('hidden', false);
    }

    function _ShowPasswordInput($order) {
        $order.find('.card-password-section').show();
    }

    function _HidePasswordInput($order) {
        $order.find('.card-password-section').hide();
    }

    function _HidePasswordLoading($order) {
        $order.find('.card-loading').prop('hidden', true);
    }

    function _ShowCardContent($order, content, leaveMessage = null) {
        const target = $order.find('.card-password-section').get(0);
        if (!target) {
            return;
        }
        target.replaceChildren();
        const cardContent = _Element("div", "card-content");
        cardContent.appendChild(_Element("div", "card-display", _Text(content)));
        target.appendChild(cardContent);
        if (_Text(leaveMessage)) {
            target.appendChild(_Element("div", "mt-3", _Text(leaveMessage)));
        }
        $(target).show();
    }

    $(document).off('click', '.view-card-btn').on('click', '.view-card-btn', function () {
        const tradeNo = _Text(this.dataset.no);
        const $order = $(this).closest('.order-item');
        const pass = _Text($order.find('.card-password-input').val()).trim();

        if (!/^\d{18}$/.test(tradeNo)) {
            message.error(_Translate("订单号格式错误"));
            return;
        }
        if (!pass) {
            message.error(_Translate("请输入密码"));
            return;
        }

        _ShowPasswordLoading($order);
        _HidePasswordInput($order);
        util.post({
            url: "/user/api/index/secret",
            data: {
                tradeNo: tradeNo,
                password: pass
            },
            loader: false,
            done: res => {
                _HidePasswordLoading($order);
                _ShowCardContent($order, res?.data?.secret, res?.data?.leave_message);
            },
            error: res => {
                message.error(_Text(res?.msg) || _Translate("未知错误"));
                _HidePasswordLoading($order);
                _ShowPasswordInput($order);
            },
            fail: () => {
                message.error(_Translate("网络错误"));
                _HidePasswordLoading($order);
                _ShowPasswordInput($order);
            }
        });
    });

    $('.order-query-form').on('submit', function (event) {
        event.preventDefault();
        const formData = new FormData($('.order-query-form')[0]);
        const data = Object.fromEntries(formData.entries());
        const keywords = _Text(data?.keywords).trim();
        if (!keywords) {
            message.error(_Translate("请输入联系方式或订单号再查询"));
            return;
        }
        _ShowLoading();
        _QueryOrders(keywords);
    });

    if (/^\d{18}$/.test(_Text(util.getParam("tradeNo")))) {
        $('.btn-search-query').click();
    }
}();
