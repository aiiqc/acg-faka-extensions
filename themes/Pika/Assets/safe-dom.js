(function (window, document) {
  "use strict";

  var fallbackAsset = "/favicon.ico";

  function plainText(value) {
    if (value === null || value === undefined) {
      return "";
    }
    if (typeof value === "string" || typeof value === "number" || typeof value === "boolean") {
      return String(value);
    }
    return "";
  }

  function hasUnsafeUrlBytes(value) {
    return /[\u0000-\u001f\u007f\s\\]/.test(value) || /%(?:0[0-9a-f]|1[0-9a-f]|7f)/i.test(value);
  }

  function safeResourceUrl(value, fallback) {
    var safeFallback = typeof fallback === "string" && fallback.charAt(0) === "/"
      ? fallback
      : fallbackAsset;

    if (typeof value !== "string" || value.length === 0 || value.length > 2048 || hasUnsafeUrlBytes(value)) {
      return safeFallback;
    }

    try {
      if (value.charAt(0) === "/") {
        if (value.indexOf("//") === 0 || /(?:^|\/)(?:\.{2}|%2e%2e)(?:\/|$)/i.test(value)) {
          return safeFallback;
        }
        var local = new URL(value, "https://pika.invalid");
        return local.origin === "https://pika.invalid" ? value : safeFallback;
      }

      var absolute = new URL(value);
      if (
        (absolute.protocol !== "https:" && absolute.protocol !== "http:") ||
        absolute.username !== "" ||
        absolute.password !== ""
      ) {
        return safeFallback;
      }
      return absolute.href;
    } catch (error) {
      return safeFallback;
    }
  }

  function safeNavigationUrl(value) {
    if (typeof value !== "string" || value.length === 0 || value.length > 2048 || hasUnsafeUrlBytes(value)) {
      return null;
    }

    try {
      if (value.charAt(0) === "/") {
        if (value.indexOf("//") === 0 || /(?:^|\/)(?:\.{2}|%2e%2e)(?:\/|$)/i.test(value)) {
          return null;
        }
        var local = new URL(value, window.location.origin);
        return local.origin === window.location.origin ? value : null;
      }

      var absolute = new URL(value);
      if (
        (absolute.protocol !== "https:" && absolute.protocol !== "http:") ||
        absolute.username !== "" ||
        absolute.password !== "" ||
        (window.location.protocol === "https:" && absolute.protocol !== "https:")
      ) {
        return null;
      }
      return absolute.href;
    } catch (error) {
      return null;
    }
  }

  function setText(element, value) {
    if (element) {
      element.textContent = plainText(value);
    }
    return element;
  }

  function textHtml(value) {
    var holder = document.createElement("span");
    setText(holder, value);
    return holder.innerHTML;
  }

  window.PikaSafeDom = Object.freeze({
    plainText: plainText,
    safeResourceUrl: safeResourceUrl,
    safeNavigationUrl: safeNavigationUrl,
    setText: setText,
    textHtml: textHtml
  });
})(window, document);
