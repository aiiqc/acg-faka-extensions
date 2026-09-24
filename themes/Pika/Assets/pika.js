(function (window, document) {
  "use strict";

  var runtime = window.__pikaThemeRuntime;
  if (runtime && typeof runtime.init === "function") {
    runtime.init();
    return;
  }

  runtime = {
    bound: false,
    jqueryBound: false,
    viewMode: "list",
    keyObserver: null,
    keyStates: new WeakMap(),
    init: null
  };
  window.__pikaThemeRuntime = runtime;

  var APP_KEY_MASK = "\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022";

  function translate(text) {
    try {
      return typeof window.i18n === "function" ? window.i18n(text) : text;
    } catch (error) {
      return text;
    }
  }

  function createIcon(className) {
    var icon = document.createElement("i");
    icon.className = className;
    icon.setAttribute("aria-hidden", "true");
    return icon;
  }

  function notify(type, text) {
    if (window.message && typeof window.message[type] === "function") {
      window.message[type](text);
    }
  }

  function findKeyForControl(control) {
    var scope = control.closest
      ? control.closest(".uc-info__row, .uc-card__body, .uc-card")
      : null;
    return (scope && scope.querySelector(".app-key")) || document.querySelector(".app-key");
  }

  function syncKeyControls(keyElement, state) {
    var scope = keyElement.closest
      ? keyElement.closest(".uc-info__row, .uc-card__body, .uc-card")
      : null;
    var selector = ".show-app-key";
    var controls = scope
      ? scope.querySelectorAll(selector)
      : document.querySelectorAll(selector);

    controls.forEach(function (button) {
      var label = state.visible ? translate("隐藏") : translate("显示");
      button.setAttribute("aria-pressed", state.visible ? "true" : "false");
      button.setAttribute("aria-label", label);
      button.setAttribute("title", label);

      var icon = button.querySelector(".material-icons-outlined, .material-icons");
      if (icon) {
        icon.textContent = state.visible ? "visibility_off" : "visibility";
      }
      var visibleLabel = button.querySelector(".app-key-toggle-label");
      if (visibleLabel) {
        visibleLabel.textContent = label;
      }
    });
  }

  function renderAppKey(keyElement, state) {
    var nextText = state.visible ? state.key : APP_KEY_MASK;
    keyElement.setAttribute(
      "aria-label",
      translate("商户密钥") + "：" + translate(state.visible ? "显示" : "隐藏")
    );

    if (keyElement.textContent !== nextText) {
      state.expectedText = nextText;
      keyElement.textContent = nextText;
    }
    syncKeyControls(keyElement, state);
  }

  function ensureAppKey(keyElement) {
    if (!keyElement) {
      return null;
    }

    var state = runtime.keyStates.get(keyElement);
    if (!state) {
      state = {
        key: keyElement.getAttribute("data-key") || "",
        visible: false,
        expectedText: null
      };
      runtime.keyStates.set(keyElement, state);
    } else {
      var storedKey = keyElement.getAttribute("data-key") || "";
      if (storedKey && storedKey !== state.key) {
        state.key = storedKey;
      }
    }

    renderAppKey(keyElement, state);
    return state;
  }

  function acceptResetKeyText(keyElement) {
    var currentText = keyElement.textContent || "";
    var state = runtime.keyStates.get(keyElement);
    if (!state) {
      state = {
        key: keyElement.getAttribute("data-key") || "",
        visible: false,
        expectedText: null
      };
      runtime.keyStates.set(keyElement, state);
    }
    if (!state) {
      return;
    }

    if (state.expectedText !== null && currentText === state.expectedText) {
      state.expectedText = null;
      return;
    }

    if (currentText && currentText !== APP_KEY_MASK && currentText !== state.key) {
      state.key = currentText;
      keyElement.setAttribute("data-key", currentText);
    }
    renderAppKey(keyElement, state);
  }

  function collectAppKeys(node, collection) {
    if (!node || node.nodeType !== 1) {
      return;
    }
    if (node.matches && node.matches(".app-key[data-key]")) {
      collection.add(node);
    }
    if (node.querySelectorAll) {
      node.querySelectorAll(".app-key[data-key]").forEach(function (keyElement) {
        collection.add(keyElement);
      });
    }
  }

  function stopKeyObserver() {
    if (!runtime.keyObserver) {
      return;
    }
    runtime.keyObserver.disconnect();
    runtime.keyObserver = null;
  }

  function startKeyObserver() {
    if (
      runtime.keyObserver ||
      !document.body ||
      !window.MutationObserver ||
      !document.querySelector(".app-key[data-key]")
    ) {
      return;
    }

    runtime.keyObserver = new MutationObserver(function (records) {
      var changedKeys = new Set();
      var addedKeys = new Set();

      records.forEach(function (record) {
        var target = record.target.nodeType === 3 ? record.target.parentElement : record.target;
        var keyElement = target && target.closest ? target.closest(".app-key[data-key]") : null;
        if (keyElement) {
          changedKeys.add(keyElement);
        }

        record.addedNodes.forEach(function (node) {
          collectAppKeys(node, addedKeys);
        });
      });

      addedKeys.forEach(function (keyElement) {
        ensureAppKey(keyElement);
      });
      changedKeys.forEach(function (keyElement) {
        if (!addedKeys.has(keyElement)) {
          acceptResetKeyText(keyElement);
        }
      });

      if (!document.querySelector(".app-key[data-key]")) {
        stopKeyObserver();
      }
    });

    runtime.keyObserver.observe(document.body, {
      childList: true,
      characterData: true,
      subtree: true
    });
  }

  function toggleAppKey(control) {
    var keyElement = findKeyForControl(control);
    var state = ensureAppKey(keyElement);
    if (!state || !state.key) {
      return;
    }
    state.visible = !state.visible;
    renderAppKey(keyElement, state);
  }

  function copyAppKey(control) {
    var keyElement = findKeyForControl(control);
    var state = ensureAppKey(keyElement);
    if (!state || !state.key) {
      notify("error", translate("复制失败"));
      return;
    }

    var onSuccess = function () {
      notify("success", translate("复制成功"));
    };
    var onFailure = function () {
      notify("error", translate("复制失败"));
    };

    if (window.isSecureContext && navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(state.key).then(onSuccess, onFailure);
      return;
    }

    if (window.util && typeof window.util.copyTextToClipboard === "function") {
      window.util.copyTextToClipboard(state.key, onSuccess, onFailure);
      return;
    }
    onFailure();
  }

  function dispatchExistingSearch(source) {
    var target = document.querySelector(".item-search-input");
    if (!target) {
      return;
    }

    if (source && source !== target && typeof source.value === "string") {
      target.value = source.value;
    }

    var event;
    try {
      event = new KeyboardEvent("keypress", {
        bubbles: true,
        cancelable: true,
        key: "Enter",
        code: "Enter",
        keyCode: 13,
        which: 13
      });
      if (event.which !== 13) {
        Object.defineProperty(event, "which", { value: 13 });
      }
    } catch (error) {
      event = document.createEvent("Event");
      event.initEvent("keypress", true, true);
      Object.defineProperty(event, "key", { value: "Enter" });
      Object.defineProperty(event, "keyCode", { value: 13 });
      Object.defineProperty(event, "which", { value: 13 });
    }

    target.dispatchEvent(event);
  }

  function updateViewControls() {
    var isGrid = runtime.viewMode === "grid";

    document.querySelectorAll(".item-list").forEach(function (list) {
      list.classList.toggle("fb-layout-list", !isGrid);
      list.classList.toggle("fb-layout-grid", isGrid);
      list.setAttribute("data-fb-view", runtime.viewMode);
    });

    document.querySelectorAll(".fb-view-toggle").forEach(function (button) {
      var label = translate("商品列表");
      var icon = button.querySelector("i");
      button.setAttribute("aria-label", label);
      button.setAttribute("title", label);
      button.setAttribute("aria-pressed", isGrid ? "true" : "false");
      if (icon) {
        icon.className = isGrid
          ? "fa-duotone fa-regular fa-list"
          : "fa-duotone fa-regular fa-grid-2";
      }
    });
  }

  function buildStoreToolbar(list) {
    if (!list || !list.parentNode) {
      return;
    }

    var existing = list.parentNode.querySelector(":scope > .fb-store-toolbar");
    if (existing) {
      return;
    }

    var sourceInput = document.querySelector(".item-search-input");
    var placeholder = sourceInput
      ? sourceInput.getAttribute("placeholder") || translate("搜索商品关键词..")
      : translate("搜索商品关键词..");

    var toolbar = document.createElement("div");
    toolbar.className = "fb-store-toolbar";
    toolbar.setAttribute("role", "search");

    var title = document.createElement("div");
    title.className = "fb-store-toolbar-title";
    title.appendChild(createIcon("fa-duotone fa-regular fa-list"));
    var titleText = document.createElement("span");
    titleText.textContent = translate("商品列表");
    title.appendChild(titleText);

    var search = document.createElement("label");
    search.className = "fb-store-search";

    var mirrorInput = document.createElement("input");
    mirrorInput.className = "fb-product-search";
    mirrorInput.type = "search";
    mirrorInput.autocomplete = "off";
    mirrorInput.placeholder = placeholder;
    mirrorInput.setAttribute("aria-label", placeholder);

    var searchButton = document.createElement("button");
    searchButton.className = "fb-search-trigger";
    searchButton.type = "button";
    searchButton.setAttribute("aria-label", translate("搜索商品"));
    searchButton.setAttribute("title", translate("搜索商品"));
    searchButton.appendChild(createIcon("fa-duotone fa-regular fa-magnifying-glass"));

    var viewButton = document.createElement("button");
    viewButton.className = "fb-view-toggle";
    viewButton.type = "button";
    viewButton.appendChild(createIcon("fa-duotone fa-regular fa-grid-2"));

    search.appendChild(mirrorInput);
    search.appendChild(searchButton);
    toolbar.appendChild(title);
    toolbar.appendChild(search);
    toolbar.appendChild(viewButton);
    list.parentNode.insertBefore(toolbar, list);
  }

  function syncPublicMenuState() {
    document.querySelectorAll(".navbar-toggler").forEach(function (button) {
      var selector = button.getAttribute("data-bs-target");
      var panel = selector ? document.querySelector(selector) : null;
      if (!panel) {
        return;
      }
      button.setAttribute("aria-expanded", panel.classList.contains("show") ? "true" : "false");
    });
  }

  function hidePublicMenu() {
    var openPanel = document.querySelector(".navbar-collapse.show");
    if (!openPanel || !window.bootstrap || !window.bootstrap.Collapse) {
      return;
    }

    window.bootstrap.Collapse.getOrCreateInstance(openPanel, { toggle: false }).hide();
  }

  function acgRefreshUrl(value) {
    if (
      typeof value !== "string" ||
      !/^\/user\/captcha\/image(?:\?[^#\u0000-\u001f\u007f]*)?$/.test(value)
    ) {
      return "";
    }
    return value;
  }

  function handleAcgCompatClick(event) {
    if (window.__acgBind) {
      return;
    }

    var control = event.target && event.target.closest
      ? event.target.closest("[data-acg-proxy],[data-acg-refresh]")
      : null;
    if (!control) {
      return;
    }

    var proxy = control.getAttribute("data-acg-proxy");
    if (proxy) {
      if (!/^\.[A-Za-z_][A-Za-z0-9_-]*$/.test(proxy)) {
        return;
      }
      var target = document.querySelector(proxy);
      if (target && typeof target.click === "function") {
        target.click();
      }
      return;
    }

    var refresh = acgRefreshUrl(control.getAttribute("data-acg-refresh"));
    if (refresh) {
      control.src = refresh + (refresh.indexOf("?") === -1 ? "?" : "&") + "t=" + Date.now();
    }
  }

  function handleAcgCompatSubmit(event) {
    if (window.__acgBind) {
      return;
    }
    var form = event.target;
    if (form && form.hasAttribute && form.hasAttribute("data-acg-prevent")) {
      event.preventDefault();
    }
  }

  function initPage() {
    document.documentElement.classList.add("fbfaka-theme");
    if (document.body) {
      document.body.classList.add("fbfaka-theme-body");
    }

    document.querySelectorAll(".item-list").forEach(function (list) {
      buildStoreToolbar(list);
    });
    var keyElements = document.querySelectorAll(".app-key[data-key]");
    keyElements.forEach(function (keyElement) {
      ensureAppKey(keyElement);
    });
    if (keyElements.length > 0) {
      startKeyObserver();
    } else {
      stopKeyObserver();
    }
    updateViewControls();
    syncPublicMenuState();

    if (!runtime.jqueryBound && window.jQuery) {
      runtime.jqueryBound = true;
      window.jQuery(document)
        .off("pjax:complete.fbfakaTheme")
        .on("pjax:complete.fbfakaTheme", function () {
          window.setTimeout(initPage, 0);
        });
    }
  }

  runtime.init = initPage;

  if (!runtime.bound) {
    runtime.bound = true;

    // Acg-Faka 3.6.4 has no csp-bind.js, while 3.7.0 only injects it when
    // CSP is enabled. Keep this theme fallback delegated for dynamic content,
    // but defer to the complete official binder whenever it is present.
    if (!window.__acgBind) {
      document.addEventListener("click", handleAcgCompatClick);
      document.addEventListener("submit", handleAcgCompatSubmit);
    }

    document.addEventListener("click", function (event) {
      var link = event.target.closest
        ? event.target.closest("[data-pika-full-navigation]")
        : null;
      var rawHref = link && link.getAttribute
        ? link.getAttribute("href")
        : null;
      if (
        !link ||
        link.nodeName !== "A" ||
        rawHref !== "/" ||
        event.defaultPrevented ||
        event.button !== 0 ||
        event.metaKey ||
        event.ctrlKey ||
        event.shiftKey ||
        event.altKey
      ) {
        return;
      }

      event.preventDefault();
      event.stopPropagation();
      window.location.assign("/");
    }, true);

    document.addEventListener("click", function (event) {
      var showKeyButton = event.target.closest
        ? event.target.closest(".show-app-key")
        : null;
      if (showKeyButton) {
        event.preventDefault();
        toggleAppKey(showKeyButton);
        return;
      }

      var copyKeyButton = event.target.closest
        ? event.target.closest(".copy-app-key")
        : null;
      if (copyKeyButton) {
        event.preventDefault();
        copyAppKey(copyKeyButton);
        return;
      }

      var searchButton = event.target.closest
        ? event.target.closest(".fb-search-trigger")
        : null;
      if (searchButton) {
        event.preventDefault();
        var toolbar = searchButton.closest(".fb-store-toolbar");
        var source = toolbar ? toolbar.querySelector(".fb-product-search") : null;
        dispatchExistingSearch(source);
        return;
      }

      var viewButton = event.target.closest
        ? event.target.closest(".fb-view-toggle")
        : null;
      if (viewButton) {
        event.preventDefault();
        runtime.viewMode = runtime.viewMode === "grid" ? "list" : "grid";
        updateViewControls();
        return;
      }

      var navLink = event.target.closest
        ? event.target.closest(".navbar-collapse.show a[href]")
        : null;
      if (navLink) {
        hidePublicMenu();
        return;
      }

      if (event.target.closest && event.target.closest(".navbar-toggler")) {
        window.requestAnimationFrame(syncPublicMenuState);
      }
    });

    document.addEventListener("keydown", function (event) {
      if (
        event.key === "Enter" &&
        event.target &&
        event.target.matches &&
        event.target.matches(".fb-product-search")
      ) {
        event.preventDefault();
        dispatchExistingSearch(event.target);
        return;
      }

      if (event.key === "Escape") {
        hidePublicMenu();
        if (document.body) {
          document.body.classList.remove("uc-drawer-open");
        }
      }
    });

    document.addEventListener("shown.bs.collapse", syncPublicMenuState);
    document.addEventListener("hidden.bs.collapse", syncPublicMenuState);
    document.addEventListener("pjax:complete", function () {
      window.setTimeout(initPage, 0);
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initPage, { once: true });
  } else {
    initPage();
  }
})(window, document);
