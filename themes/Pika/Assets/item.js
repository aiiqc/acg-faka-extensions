!function () {
    const _item = getVar("_var_item");
    const safeDom = window.PikaSafeDom;
    let _price = 0, _available = false;
    //抢购结束后付款区被收起，切换SKU重新查库存时不能把它又打开
    let _seckillEnded = false;
    const $vstack = $(`.vstack`), $cashPay = $(`.cash-pay`);

    function _SetPrice($target, amount) {
        $target.empty()
            .append($("<span>", {class: "unit"}).text(format.currencySymbol()))
            .append(document.createTextNode(safeDom.plainText(format.amountRemoveTrailingZeros(amount))));
    }

    function _getPostData() {
        let post = util.arrayToObject($vstack.serializeArray());
        post["item_id"] = _item.id;

        if (!util.isEmptyOrNotJson(_item?.config?.category)) {
            //商品分类
            post["race"] = $(`.switch-race.is-primary`).data("sku");
        }

        //获取SKU
        if (!util.isEmptyOrNotJson(_item?.config?.sku)) {
            for (const name in _item?.config?.sku) {
                const $sku = $(`.switch-sku.is-primary`).filter(function () {
                    return safeDom.plainText($(this).data("sku")) === safeDom.plainText(name);
                });
                post["sku"] = post["sku"] || {};
                post["sku"][name] = $sku.data("value");
            }
        }

        return post;
    }

    //算盘
    function _Abacus(error = null) {
        //商品默认价格
        const $price = $(`.abacus .price`);
        //远程加载价格
        $price.html(`<i class="fa-duotone fa-regular fa-spinner-third icon-spin fs-6"></i>`);

        util.post({
            url: "/user/api/index/valuation",
            data: _getPostData(),
            done: res => {
                _SetPrice($price, res.data.price);
                _price = res.data.price;
                _available = true;
            },
            loader: false,
            error: d => {
                typeof error === "function" && error(d);
                _SetPrice($price, _price);
            }
        });

        _SetWholesaleMsg();
    }

    function _SnapUp() {
        if (_item.seckill_status != 1) {
            return;
        }

        //抢购逻辑
        const $snapUp = $(`.snap-up`);

        const startTime = _item.seckill_start_time;
        const endTime = _item.seckill_end_time;

        util.timer(() => {
            return new Promise(resolve => {
                const now = new Date().getTime();

                if (new Date(startTime).getTime() > now) {
                    //未开始
                    const t = format.expireTime(startTime);
                    t && $snapUp.addClass("badge-soft-info").text(`${i18n('离抢购开始还剩')}${t}`);
                    resolve(true);
                } else if (new Date(endTime).getTime() > now) {
                    //已开始
                    const t = format.expireTime(endTime);
                    t && $snapUp.removeClass("badge-soft-info").addClass("badge-soft-primary").text(`${i18n('抢购结束还剩')}${t}`);
                    resolve(true);
                } else {
                    //已结束
                    $snapUp.removeClass("badge-soft-success").addClass("badge-soft-muted").text(i18n('抢购已结束'));
                    _seckillEnded = true;
                    $cashPay.fadeOut(150);
                    resolve(false);
                }
                $snapUp.show();
            });
        }, 1000, true)
    }

    function _Coupon() {
        $(`.vstack input[name=coupon]`).change(function () {
            _Abacus(d => {
                message.error(d.msg);
                $(this).val("");
            });
        });
    }


    function _SwitchRace() {
        const $switchRace = $(`.switch-race`);

        $switchRace.click(function () {
            $switchRace.removeClass("is-primary");
            $(this).addClass("is-primary");
            _Abacus();
            _GetStock();
        });
    }

    function _SwitchSku() {
        if (!util.isEmptyOrNotJson(_item?.config?.sku)) {
            for (const name in _item?.config?.sku) {
                const $sku = $(`.switch-sku`).filter(function () {
                    return safeDom.plainText($(this).data("sku")) === safeDom.plainText(name);
                });
                $sku.click(function () {
                    $sku.removeClass("is-primary");
                    $(this).addClass("is-primary");
                    _Abacus();
                    _GetStock();
                });
            }
        }
    }

    function _ChangeNum() {
        const $input = $(`input[name=num]`);

        $input.on('change', function () {
            if (_item.minimum > 0 && $(this).val() < _item.minimum) {
                $(this).val(_item.minimum);
            }
            if (_item.maximum > 0 && $(this).val() > _item.maximum) {
                $(this).val(_item.maximum);
            }

            _Abacus();
        });

        $(`.change-num-sub`).click(function () {
            $input.val(Math.max(1, (+$input.val() || 1) - 1)).trigger('change');
        });

        $(`.change-num-add`).click(function () {
            $input.val(Math.max(1, (+$input.val() || 1) + 1)).trigger('change');
        });
    }

    function _SetWholesaleMsg() {
        const $qtyGroup = $(`.qty-group`);
        $(`.wholesale-table`).remove();
        const $table = $("<table>", {class: "table wholesale-table mt-1 mb-0"});
        const $heading = $("<tr>")
            .append($("<th>", {scope: "col"}).text(i18n('批发数量')))
            .append($("<th>", {scope: "col"}).text(i18n('单价')));
        const $body = $("<tbody>");
        $table.append($("<thead>").append($heading), $body);

        const appendPriceRow = (quantity, amount) => {
            $body.append(
                $("<tr>")
                    .append($("<td>").text(safeDom.plainText(quantity)))
                    .append($("<td>").text(`${format.currencySymbol()}${safeDom.plainText(amount)}`))
            );
        };

        if (!util.isEmptyOrNotJson(_item?.config?.category)) {
            const sku = $(`.switch-race.is-primary`).data('sku');
            //分类批发
            if (_item?.config?.category_wholesale?.hasOwnProperty(sku)) {
                for (const k in _item.config.category_wholesale[sku]) {
                    appendPriceRow(k, _item.config.category_wholesale[sku][k]);
                }
                $qtyGroup.after($table);
            }
            return;
        }

        if (!util.isEmptyOrNotJson(_item?.config?.wholesale)) {
            for (const k in _item.config.wholesale) {
                appendPriceRow(k, _item.config.wholesale[k]);
            }
            $qtyGroup.after($table);
        }
    }

    function _GetStock() {
        const $itemStock = $(`.item-stock`);
        util.post({
            url: "/user/api/index/stock",
            data: _getPostData(),
            done: res => {
                if (res.data.stock_state <= 0) {
                    $cashPay.fadeOut(150);
                    $itemStock.removeClass("badge-soft-success").addClass("badge-soft-danger").text(i18n('已售罄'));
                    return;
                }

                $itemStock.removeClass("badge-soft-danger").addClass('badge-soft-success').text(`${i18n('库存')} ${safeDom.plainText(res.data.stock)}`);
                //抢购结束也会把付款区收起来，那是另一码事——不能因为这个SKU有货就把它又打开，
                //否则切一下SKU就能给已经结束的秒杀下单
                if (!_seckillEnded) {
                    $cashPay.fadeIn(150);
                }
            },
            loader: false
        });
    }

    function _CaptchaRefresh() {
        const baseSrc = '/user/captcha/image?action=trade';
        $('.captcha-img').attr('src', baseSrc + '&_t=' + Date.now());
    }

    function _RegisterCaptchaRefresh() {
        $('.captcha-img').click(() => {
            _CaptchaRefresh();
        });
    }


    function _SetPayList() {
        const $payList = $(`.pay-list`);
        const balanceLabels = [];
        // This is a page-load snapshot, never an account cache or a payment input.
        function balanceSuffix() {
            const raw = $payList.attr("data-user-balance");
            if (typeof raw !== "string" || !/^\d+(?:\.\d+)?$/.test(raw)) return "";
            const amount = Number(raw);
            if (!Number.isFinite(amount) || amount > Number.MAX_SAFE_INTEGER / 100) return "";
            try {
                return `（${format.currencySymbol()}${format.amount(amount)}）`;
            } catch (error) {
                return "";
            }
        }
        function clearBalanceSnapshot() {
            $payList.removeAttr("data-user-balance");
            balanceLabels.forEach(({label, name}) => label.text(name).removeAttr("title"));
            balanceLabels.length = 0;
        }
        window.addEventListener("pagehide", clearBalanceSnapshot, {once: true});
        window.addEventListener("pageshow", event => {
            if (event.persisted) clearBalanceSnapshot();
        });
        util.post({
            url: `/user/api/index/pay?itemId=${_item.id}`,
            done: res => {
                res.data.forEach(item => {
                    const $pay = $("<a>", {class: "pay"}).attr("data-id", safeDom.plainText(item.id));
                    const $icon = $("<img>", {
                        src: safeDom.safeResourceUrl(item.icon, "/favicon.ico"),
                        alt: ""
                    });
                    const name = i18n(safeDom.plainText(item.name));
                    const $label = $("<span>").text(name);
                    const suffix = item.handle === "#system" ? balanceSuffix() : "";
                    if (suffix) {
                        $label.text(name + suffix).attr("title", i18n("页面加载时的余额，刷新页面更新"));
                        balanceLabels.push({label: $label, name});
                    }
                    $pay.append($icon, $label);
                    $payList.append($pay);
                });
            },
            loader: false
        });

        $(document).on("click", `.pay-list .pay`, function () {
            let post = _getPostData();
            post["pay_id"] = $(this).data("id");
            util.post("/user/api/order/trade", post, res => {
                if (post["pay_id"] == 1) {
                    //余额购买，直接反馈
                    treasure.show(res.data.tradeNo, res.data.secret, safeDom.textHtml(res.data.leave_message));
                    return;
                }

                //0元单(如100%优惠券抵扣)没有收银台，url为空时直接反馈结果，防止跳转到"null"
                if (!res.data.url) {
                    treasure.show(res.data.tradeNo, res.data.secret, safeDom.textHtml(res.data.leave_message));
                    return;
                }

                const paymentUrl = safeDom.safeNavigationUrl(res.data.url);
                if (!paymentUrl) {
                    message.error(i18n('支付地址无效，请刷新后重试'));
                    _CaptchaRefresh();
                    return;
                }
                window.location.assign(paymentUrl);
            }, error => {
                message.error(error.msg);
                _CaptchaRefresh();
            });
        });
    }

    function _OptionalCard() {
        const $OptionalCard = $(`.optional-card`);
        let table;


        $OptionalCard.click(() => {
            component.popup({
                submit: (data, index) => {
                    const selections = table.getSelections();
                    if (selections.length == 0) {
                        $(`input[name=card_id]`).val("");
                        $OptionalCard.text(`${i18n('未自选')},${i18n('将随机发货')}`);
                    } else {
                        const draftPremium = selections[0].draft_premium > 0 ? selections[0].draft_premium : _item.draft_premium;

                        $OptionalCard.empty();
                        if (draftPremium > 0) {
                            $OptionalCard.append(
                                $("<span>", {class: "text-primary me-1"})
                                    .text(`« ${format.currencySymbol()}${safeDom.plainText(draftPremium)} »`),
                                document.createTextNode(" ")
                            );
                        }
                        $OptionalCard.append(document.createTextNode(safeDom.plainText(selections[0].draft)));
                        $(`input[name=card_id]`).val(selections[0].id);
                    }

                    layer.close(index);
                    _Abacus();
                },
                tab: [
                    {
                        name: `<i class="fa-duotone fa-regular fa-list-radio"></i> ${i18n('自助选号')}`,
                        form: [
                            {
                                name: "sku",
                                type: "custom",
                                complete: (popup, dom) => {
                                    const where = _getPostData();
                                    dom.html(`<div class="mcy-card"><table id="shop-selection-table"></table></div>`);
                                    table = new Table("/user/api/index/card", dom.find('#shop-selection-table'));
                                    table.setPagination(10, [10, 20, 30]);
                                    table.setColumns([
                                        {checkbox: true},
                                        {
                                            field: 'draft', title: '剧透内容', formatter: value => {
                                                return safeDom.textHtml(value);
                                            }
                                        },
                                        {
                                            field: 'draft_premium', title: '溢价', formatter: _ => {
                                                if (_ == 0) {
                                                    if (_item.draft_premium > 0) {
                                                        return format.badge(safeDom.textHtml(format.currencySymbol() + safeDom.plainText(_item.draft_premium)), "a-badge-primary");
                                                    }
                                                    return '-';
                                                }
                                                return format.badge(safeDom.textHtml(format.currencySymbol() + safeDom.plainText(_)), "a-badge-primary");
                                            }
                                        },
                                    ]);

                                    for (const whereKey in where) {
                                        table.setWhere(whereKey, where[whereKey]);
                                    }

                                    table.setSearch([
                                        {
                                            title: "搜索剧透内容",
                                            name: "search-draft",
                                            type: "input",
                                            width: 300
                                        }
                                    ]);

                                    table.enableSingleSelect();
                                    table.render();
                                }
                            },
                        ]
                    },
                ],
                assign: {},
                autoPosition: true,
                confirmText: `<i class="fa-duotone fa-regular fa-badge-check"></i> ${i18n('确认选号')}`,
                width: "620px"
            });
        });
    }

    function _ShareItem() {
        $(`.shared-button`).click(() => {
            util.copyTextToClipboard(_item.share_url, () => {
                layer.msg(i18n("分享链接复制成功，快去分享给朋友吧！"))
            });
        });
    }

    _SnapUp();//抢购
    _SwitchRace();
    _SwitchSku();
    _ChangeNum();
    _SetWholesaleMsg();
    _Coupon();//优惠券
    //自动询价
    _Abacus();
    //自动更新库存
    _GetStock();
    //支付方式
    _SetPayList();
    _RegisterCaptchaRefresh();
    _OptionalCard();

    _ShareItem();
}();
