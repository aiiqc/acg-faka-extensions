(function (window, document) {
  "use strict";

  var categoryLinks = Array.prototype.slice.call(
    document.querySelectorAll(".switch-category")
  );
  var categoryParents = Array.prototype.slice.call(
    document.querySelectorAll(".category-parent")
  );
  var previousRuntime = window.__pikaCategoryRuntime;
  var itemList = document.querySelector(".item-list");
  if (!itemList) {
    if (previousRuntime && typeof previousRuntime.destroy === "function") {
      previousRuntime.destroy();
    }
    return;
  }

  if (previousRuntime && previousRuntime.itemList === itemList) {
    if (
      typeof previousRuntime.isUsable === "function" &&
      previousRuntime.isUsable()
    ) {
      return;
    }
    if (typeof previousRuntime.destroy === "function") {
      previousRuntime.destroy();
    }
  }
  if (
    previousRuntime &&
    previousRuntime.itemList !== itemList &&
    typeof previousRuntime.destroy === "function"
  ) {
    previousRuntime.destroy();
  }

  var tradeApi = null;
  try {
    if (typeof trade !== "undefined") {
      tradeApi = trade;
    }
  } catch (error) {
    tradeApi = null;
  }
  if (!tradeApi) {
    tradeApi = window.trade;
  }

  var allowedTagColors = new Set([
    "red",
    "orange",
    "green",
    "cyan",
    "blue",
    "purple",
    "pink",
    "gray"
  ]);
  var requestSequence = 0;
  var activeRequest = 0;
  var watchdogId = null;
  var alive = true;
  var listenerDisposers = [];
  var requestTimeoutMs = 12000;
  var runtimeHandle = null;
  var rulesScrollPending = Boolean(
    window.location && window.location.hash === "#fbfaka-rules"
  );
  var rulesScrollScheduled = false;
  var rulesScrollFrame = null;

  function plainText(value) {
    if (value === null || value === undefined) {
      return "";
    }
    if (typeof value === "string" || typeof value === "number" || typeof value === "boolean") {
      return String(value);
    }
    return "";
  }

  function translate(value) {
    try {
      var translated = typeof window.i18n === "function" ? window.i18n(value) : value;
      return plainText(translated);
    } catch (error) {
      return plainText(value);
    }
  }

  function createElement(tagName, className, text) {
    var element = document.createElement(tagName);
    if (className) {
      element.className = className;
    }
    if (text !== undefined) {
      element.textContent = text;
    }
    return element;
  }

  function positiveInteger(value) {
    var number;
    if (typeof value === "number") {
      number = value;
    } else if (typeof value === "string" && /^[1-9][0-9]*$/.test(value)) {
      number = Number(value);
    } else {
      return null;
    }
    return Number.isSafeInteger(number) && number > 0 ? number : null;
  }

  function nonNegativeInteger(value) {
    var number;
    if (typeof value === "number") {
      number = value;
    } else if (typeof value === "string" && /^(?:0|[1-9][0-9]*)$/.test(value)) {
      number = Number(value);
    } else {
      return 0;
    }
    return Number.isSafeInteger(number) && number >= 0 ? number : 0;
  }

  function safeCover(value) {
    return window.PikaSafeDom.safeResourceUrl(value, "/favicon.ico");
  }

  function appendBadge(parent, className, text) {
    parent.appendChild(createElement("span", "badge-soft " + className, text));
  }

  function appendTags(parent, item) {
    var tags = item && Array.isArray(item.tags) ? item.tags : [];
    tags.forEach(function (tag) {
      var text = plainText(tag && tag.text).trim();
      if (!text) {
        return;
      }
      var requestedColor = plainText(tag && tag.color).toLowerCase();
      var color = allowedTagColors.has(requestedColor) ? requestedColor : "red";
      parent.appendChild(createElement("span", "acg-tag acg-tag--" + color, text));
    });
  }

  function buildCommodity(item) {
    var itemId = positiveInteger(item && item.id);
    if (itemId === null) {
      return null;
    }

    var stockState = nonNegativeInteger(item && item.stock_state);
    if (stockState <= 0) {
      return null;
    }

    var itemName = translate(item.name);
    var link = createElement("a", "col-12 col-md-6 col-lg-3 mb-3");
    link.dataset.id = String(itemId);
    link.href = "/item/" + itemId;

    var card = createElement("div", "acg-card h-100");
    var thumb = createElement("div", "acg-thumb");
    var cover = createElement("img", "item-cover");
    cover.src = safeCover(item.cover);
    cover.alt = itemName;
    cover.loading = "lazy";
    cover.decoding = "async";
    thumb.appendChild(cover);

    var body = createElement("div", "p-3");
    var tags = createElement("div", "tags");
    appendTags(tags, item);
    appendBadge(
      tags,
      "badge-soft-success",
      item.delivery_way === 0 ? translate("自动发货") : translate("在线发货")
    );
    if (Number(item.recommend) === 1) {
      appendBadge(tags, "badge-soft-primary", translate("推荐"));
    }

    var title = createElement("p", "goods-title", itemName);
    var statRow = createElement("div", "stat-row mb-1");
    var price = createElement("div", "price");
    price.appendChild(createElement("span", "unit", format.currencySymbol()));
    price.appendChild(document.createTextNode(plainText(item.price)));
    statRow.appendChild(price);

    var statBottom = createElement("div", "stat-bottom");
    statBottom.appendChild(
      createElement("span", "", translate("库存：") + plainText(item.stock))
    );

    body.appendChild(tags);
    body.appendChild(title);
    body.appendChild(statRow);
    body.appendChild(statBottom);
    card.appendChild(thumb);
    card.appendChild(body);

    link.appendChild(card);
    return link;
  }

  function showMessage(message, kind) {
    var messageElement = createElement(
      "div",
      "item-message" + (kind === "error" ? " item-message--error" : ""),
      message
    );
    messageElement.setAttribute("role", kind === "error" ? "alert" : "status");
    itemList.replaceChildren(messageElement);
  }

  function setLoading(loading) {
    if (loading) {
      itemList.setAttribute("aria-busy", "true");
    } else {
      itemList.setAttribute("aria-busy", "false");
    }
  }

  function cancelWatchdog() {
    if (watchdogId !== null && typeof window.clearTimeout === "function") {
      window.clearTimeout(watchdogId);
    }
    watchdogId = null;
  }

  function isStorefrontRoute() {
    var pathname = window.location && typeof window.location.pathname === "string"
      ? window.location.pathname
      : "";
    return pathname === "/" || /^\/cat\/[^/?#]+\/?$/.test(pathname);
  }

  function destroyRuntime() {
    if (!alive) {
      return;
    }
    alive = false;
    activeRequest = 0;
    requestSequence += 1;
    cancelWatchdog();
    listenerDisposers.splice(0).forEach(function (dispose) {
      dispose();
    });
    if (
      rulesScrollFrame !== null &&
      typeof window.cancelAnimationFrame === "function"
    ) {
      window.cancelAnimationFrame(rulesScrollFrame);
    }
    rulesScrollFrame = null;
    rulesScrollScheduled = false;
    rulesScrollPending = false;
    if (window.__pikaCategoryRuntime === runtimeHandle) {
      window.__pikaCategoryRuntime = null;
    }
  }

  function ensureRuntime() {
    if (!alive) {
      return false;
    }
    if (itemList.isConnected === false || !isStorefrontRoute()) {
      destroyRuntime();
      return false;
    }
    return true;
  }

  function pushCommodityList(data) {
    var items = Array.isArray(data) ? data : [];
    var fragment = document.createDocumentFragment();
    var rendered = 0;

    items.forEach(function (item) {
      var commodity = buildCommodity(item);
      if (commodity) {
        fragment.appendChild(commodity);
        rendered += 1;
      }
    });

    if (rendered === 0) {
      var emptyText = translate("没有商品");
      if (window.layer && typeof window.layer.msg === "function") {
        window.layer.msg(emptyText);
      }
      showMessage(emptyText);
      return;
    }

    itemList.replaceChildren(fragment);
  }

  function settleRequestedRulesAnchor() {
    if (!rulesScrollPending || rulesScrollScheduled) {
      return;
    }
    if (!window.location || window.location.hash !== "#fbfaka-rules") {
      rulesScrollPending = false;
      return;
    }
    if (activeRequest !== 0) {
      return;
    }
    var rules = document.getElementById("fbfaka-rules");
    if (!rules || typeof rules.scrollIntoView !== "function") {
      rulesScrollPending = false;
      return;
    }

    rulesScrollScheduled = true;
    var scroll = function () {
      rulesScrollFrame = null;
      rulesScrollScheduled = false;
      if (
        !alive ||
        window.__pikaCategoryRuntime !== runtimeHandle ||
        rules.isConnected === false ||
        !rulesScrollPending ||
        window.location.hash !== "#fbfaka-rules"
      ) {
        rulesScrollPending = false;
        return;
      }
      rulesScrollPending = false;
      rules.scrollIntoView({ block: "start", behavior: "auto" });
    };
    if (typeof window.requestAnimationFrame === "function") {
      rulesScrollFrame = window.requestAnimationFrame(scroll);
    } else {
      scroll();
    }
  }

  function finishRequest(token, data) {
    if (!alive || token !== activeRequest || !ensureRuntime()) {
      return;
    }
    activeRequest = 0;
    cancelWatchdog();
    setLoading(false);
    pushCommodityList(data);
    settleRequestedRulesAnchor();
  }

  function failRequest(token, message) {
    if (!alive || token !== activeRequest || !ensureRuntime()) {
      return;
    }
    activeRequest = 0;
    cancelWatchdog();
    setLoading(false);
    showMessage(message, "error");
    settleRequestedRulesAnchor();
  }

  function requestCommodity(params) {
    if (!ensureRuntime()) {
      return;
    }
    var token = ++requestSequence;
    activeRequest = token;
    cancelWatchdog();
    setLoading(true);
    showMessage(translate("努力加载中.."));

    if (typeof window.setTimeout === "function") {
      watchdogId = window.setTimeout(function () {
        failRequest(token, translate("商品加载超时，请稍后重试"));
      }, requestTimeoutMs);
    }

    var options = {};
    Object.keys(params).forEach(function (key) {
      options[key] = params[key];
    });
    options.done = function (data) {
      finishRequest(token, data);
    };
    options.error = function () {
      failRequest(token, translate("商品加载失败，请稍后重试"));
    };
    options.fail = options.error;

    try {
      var result = tradeApi.getCommodityList(options);
      if (result && typeof result.catch === "function") {
        result.catch(options.error);
      }
    } catch (error) {
      options.error();
    }
  }

  function setActiveCategory(id) {
    var requested = plainText(id);
    categoryLinks.forEach(function (link) {
      var active = link.dataset.id === requested;
      link.classList.toggle("is-primary", active);
      if (active) {
        link.setAttribute("aria-current", "page");
      } else {
        link.removeAttribute("aria-current");
      }
    });
  }

  function setParentOpen(parent, open) {
    var group = parent.parentElement;
    var children = group ? group.querySelector(".category-children") : null;
    if (!children) {
      parent.setAttribute("aria-expanded", "false");
      return;
    }
    parent.classList.toggle("is-open", open);
    parent.setAttribute("aria-expanded", open ? "true" : "false");
    children.hidden = !open;
  }

  function toggleParent(selected) {
    var opening = selected.getAttribute("aria-expanded") !== "true";
    setParentOpen(selected, opening);
  }

  function updateParentTotals() {
    categoryParents.forEach(function (parent) {
      var group = parent.parentElement;
      var total = parent.querySelector("[data-category-total]");
      if (!group || !total) {
        return;
      }
      var sum = 0;
      group.querySelectorAll(".switch-category[data-count]").forEach(function (link) {
        var count = nonNegativeInteger(link.dataset.count);
        sum = Number.isSafeInteger(sum + count) ? sum + count : Number.MAX_SAFE_INTEGER;
      });
      total.textContent = String(sum);
      total.setAttribute("aria-label", translate("商品数量") + "：" + sum);
      group.hidden = sum === 0;
      parent.setAttribute("aria-hidden", sum === 0 ? "true" : "false");
      if (sum === 0) {
        setParentOpen(parent, false);
      }
    });
  }

  function openActiveParent() {
    var active = categoryLinks.find(function (link) {
      return link.classList.contains("is-primary");
    });
    categoryParents.forEach(function (candidate) {
      setParentOpen(candidate, false);
    });
    var group = active && active.closest
      ? active.closest(".fbfaka-category-group")
      : null;
    while (group) {
      var parent = group.querySelector(".category-parent");
      if (parent) {
        setParentOpen(parent, true);
      }
      var ancestor = group.parentElement;
      group = ancestor && ancestor.closest
        ? ancestor.closest(".fbfaka-category-group")
        : null;
    }
  }

  function switchCategory(id, updateUrl) {
    var requested = plainText(id);
    if (!requested || !ensureRuntime()) {
      return;
    }
    setActiveCategory(requested);
    openActiveParent();
    if (updateUrl && window.history && typeof window.history.pushState === "function") {
      window.history.pushState(null, "", "/cat/" + encodeURIComponent(requested));
    }
    requestCommodity({ categoryId: requested });
  }

  function search(keywords) {
    if (!ensureRuntime()) {
      return;
    }
    var requested = plainText(keywords).trim();
    if (!requested) {
      if (window.layer && typeof window.layer.msg === "function") {
        window.layer.msg(translate("请输入要搜索的商品名称关键词"));
      }
      return;
    }
    setActiveCategory("");
    requestCommodity({ keywords: requested });
  }

  function categoryFromPath() {
    var pathname = window.location && typeof window.location.pathname === "string"
      ? window.location.pathname
      : "";
    var match = pathname.match(/^\/cat\/([^/?#]+)\/?$/);
    if (!match) {
      return "";
    }
    try {
      return decodeURIComponent(match[1]);
    } catch (error) {
      return "";
    }
  }

  function hasCategory(id) {
    return categoryLinks.some(function (link) {
      return link.dataset.id === id;
    });
  }

  function firstChildCategory(parentId) {
    var requested = plainText(parentId);
    var parent = categoryParents.find(function (candidate) {
      return candidate.dataset.id === requested;
    });
    var group = parent ? parent.parentElement : null;
    var firstChild = group ? group.querySelector(".switch-category") : null;
    return firstChild ? plainText(firstChild.dataset.id) : "";
  }

  function resolveRouteCategory(routeCategory, fallbackCategory) {
    if (hasCategory(routeCategory)) {
      return routeCategory;
    }
    return firstChildCategory(routeCategory) || fallbackCategory;
  }

  function replaceCategoryUrl(id) {
    if (!window.history || typeof window.history.replaceState !== "function") {
      return;
    }
    try {
      window.history.replaceState(null, "", "/cat/" + encodeURIComponent(id));
    } catch (error) {
      return;
    }
  }

  function bind(element, type, listener) {
    element.addEventListener(type, listener);
    listenerDisposers.push(function () {
      if (typeof element.removeEventListener === "function") {
        element.removeEventListener(type, listener);
      }
    });
  }

  document.querySelectorAll("[data-pika-rules-navigation]").forEach(function (link) {
    bind(link, "click", function (event) {
      if (
        event.defaultPrevented ||
        event.button !== 0 ||
        event.metaKey ||
        event.ctrlKey ||
        event.shiftKey ||
        event.altKey ||
        link.getAttribute("href") !== "/#fbfaka-rules"
      ) {
        return;
      }
      var rules = document.getElementById("fbfaka-rules");
      if (!rules) {
        return;
      }

      if (window.location.hash !== "#fbfaka-rules") {
        if (!window.history || typeof window.history.pushState !== "function") {
          return;
        }
        try {
          window.history.pushState(window.history.state, "", "#fbfaka-rules");
        } catch (error) {
          return;
        }
      }
      event.preventDefault();
      event.stopPropagation();
      rulesScrollPending = true;
      settleRequestedRulesAnchor();
    });
  });

  if (typeof window.addEventListener === "function") {
    bind(window, "hashchange", function () {
      rulesScrollPending = window.location.hash === "#fbfaka-rules";
      settleRequestedRulesAnchor();
    });
  }

  categoryLinks.forEach(function (link) {
    bind(link, "click", function (event) {
      event.preventDefault();
      if (!ensureRuntime()) {
        return;
      }
      var isActive = link.classList.contains("is-primary");
      if (!isActive || activeRequest === 0) {
        switchCategory(link.dataset.id, !isActive);
      }
    });
  });

  categoryParents.forEach(function (parent) {
    bind(parent, "click", function () {
      if (!ensureRuntime()) {
        return;
      }
      toggleParent(parent);
    });
  });

  document.querySelectorAll(".item-search-input").forEach(function (input) {
    bind(input, "keypress", function (event) {
      if (event.key === "Enter" || event.which === 13) {
        event.preventDefault();
        search(input.value);
      }
    });
  });

  var configuredCategory = "";
  try {
    configuredCategory = plainText(typeof window.getVar === "function" ? window.getVar("CAT_ID") : "");
  } catch (error) {
    configuredCategory = "";
  }
  updateParentTotals();
  var configuredLink = categoryLinks.find(function (link) {
    return link.dataset.id === configuredCategory;
  });
  var firstCategory = categoryLinks.length > 0 ? categoryLinks[0].dataset.id : "";

  runtimeHandle = {
    itemList: itemList,
    isUsable: function () {
      return alive && itemList.isConnected !== false && isStorefrontRoute();
    },
    destroy: destroyRuntime
  };
  window.__pikaCategoryRuntime = runtimeHandle;

  if (typeof document.addEventListener === "function") {
    bind(document, "pjax:complete", function () {
      ensureRuntime();
    });
  }

  if (!firstCategory) {
    setLoading(false);
    showMessage(translate("没有商品"));
    settleRequestedRulesAnchor();
    return;
  }

  function onPopState() {
    if (!ensureRuntime()) {
      return;
    }
    var pathCategory = categoryFromPath();
    var nextCategory = resolveRouteCategory(pathCategory, firstCategory);
    if (pathCategory && pathCategory !== nextCategory) {
      replaceCategoryUrl(nextCategory);
    }
    switchCategory(nextCategory, false);
  }

  if (typeof window.addEventListener === "function") {
    bind(window, "popstate", onPopState);
  }

  var configuredInitialCategory = configuredLink
    ? configuredCategory
    : firstChildCategory(configuredCategory) || firstCategory;
  var initialPathCategory = categoryFromPath();
  var initialCategory = initialPathCategory
    ? resolveRouteCategory(initialPathCategory, configuredInitialCategory)
    : configuredInitialCategory;
  if (initialPathCategory && initialPathCategory !== initialCategory) {
    replaceCategoryUrl(initialCategory);
  }
  switchCategory(initialCategory, false);
})(window, document);
