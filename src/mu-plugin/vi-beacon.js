/* Visitor Insights beacon — cookie-less, first-party only.
 * Fires one pageview to admin-ajax.php (never page-cached).
 * A random id is persisted in localStorage to count unique visitors
 * across days without setting any cookie. */
(function () {
  "use strict";
  try {
    var cfg = window.VI_TRACK || {};
    var ajax = cfg.ajax || "/wp-admin/admin-ajax.php";

    function uid() {
      try {
        var k = "vi_uid";
        var v = window.localStorage.getItem(k);
        if (!v) {
          v = "u" + Date.now().toString(36) + Math.random().toString(36).slice(2, 12);
          try { window.localStorage.setItem(k, v); } catch (e) {}
        }
        return v;
      } catch (e) { return ""; }
    }

    function send() {
      var body = "action=vi_track"
        + "&p=" + encodeURIComponent(location.pathname + location.search)
        + "&r=" + encodeURIComponent(document.referrer || "")
        + "&t=" + encodeURIComponent(document.title || "")
        + "&uid=" + encodeURIComponent(uid());

      var xhr = new XMLHttpRequest();
      xhr.open("POST", ajax, true);
      xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
      xhr.send(body);
    }

    if (document.visibilityState === "prerender") {
      document.addEventListener("visibilitychange", function h() {
        if (document.visibilityState !== "prerender") {
          document.removeEventListener("visibilitychange", h);
          send();
        }
      });
    } else {
      send();
    }
  } catch (e) { /* never break the page */ }
})();
