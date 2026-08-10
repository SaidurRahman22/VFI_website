/* ==========================================================================
   api.js — the single HTTP seam between the static frontend and the Laravel API.
   Phase 0 deliverable (docs/phases/phase-0-...md, step 7.2).

   Everything network-related funnels through window.VFIApi so store.js (and,
   later, auth.js / partner-auth.js at their REAL REQUEST markers) never touch
   fetch() directly. Cookie-mode Sanctum: same-origin, credentials included,
   CSRF double-submit header, HttpOnly session cookie — no tokens in JS.

   ES5 on purpose to match the rest of js/. Loaded BEFORE store.js.
   While the backend is not wired yet, nothing here runs unless a caller opts in
   (store.js still resolves against window.VFI_BOOTSTRAP), so this file is inert
   until Phase 2 flips the switch.
   ========================================================================== */
(function () {
  "use strict";

  /* Same-origin by default (the P0 invariant). An explicit override may be set
     with <script>window.VFI_API_BASE="https://api.example"</script> before this
     file, but same-origin "" is the supported, cookie-safe path. */
  var BASE = (typeof window.VFI_API_BASE === "string") ? window.VFI_API_BASE : "";

  /* Where to send the browser when the server says "not authenticated". Pages
     can override per-surface (student vs partner vs admin) via window.VFI_401_URL. */
  function loginUrl() {
    if (typeof window.VFI_401_URL === "string") return window.VFI_401_URL;
    var p = location.pathname;
    if (p.indexOf("partner") !== -1) return "vfi-partner-login.html";
    if (p.indexOf("admin") !== -1) return "admin.html";
    return "login.html";
  }

  /* Read a cookie by name (used for the XSRF-TOKEN double-submit value). */
  function cookie(name) {
    var m = document.cookie.match("(^|; )" + name.replace(/([.*+?^${}()|[\]\\])/g, "\\$1") + "=([^;]*)");
    return m ? decodeURIComponent(m[2]) : null;
  }

  /* Laravel/Sanctum sets an XSRF-TOKEN cookie; we echo it back in a header on
     every state-changing request. GET first if the cookie is missing. */
  function ensureCsrf() {
    if (cookie("XSRF-TOKEN")) return Promise.resolve();
    return fetch(BASE + "/sanctum/csrf-cookie", { credentials: "include" })
      .then(function () { /* cookie now set */ });
  }

  function isWrite(method) {
    return method !== "GET" && method !== "HEAD" && method !== "OPTIONS";
  }

  /* Core request. Returns a Promise of the parsed JSON body (or null on 204).
     Rejects with an Error carrying .status and .body on HTTP >= 400. On 401 it
     redirects to the appropriate login page and rejects. */
  function request(method, path, body, opts) {
    method = (method || "GET").toUpperCase();
    opts = opts || {};
    var url = BASE + (path.charAt(0) === "/" ? path : "/" + path);

    var pre = isWrite(method) ? ensureCsrf() : Promise.resolve();

    return pre.then(function () {
      var headers = { "Accept": "application/json" };
      var init = { method: method, credentials: "include", headers: headers };

      if (isWrite(method)) {
        var x = cookie("XSRF-TOKEN");
        if (x) headers["X-XSRF-TOKEN"] = x;
      }
      if (body !== undefined && body !== null && !(body instanceof FormData)) {
        headers["Content-Type"] = "application/json";
        init.body = JSON.stringify(body);
      } else if (body instanceof FormData) {
        init.body = body; // browser sets multipart boundary
      }

      return fetch(url, init).then(function (res) {
        if (res.status === 401 && !opts.noRedirect) {
          try { window.location.href = loginUrl(); } catch (e) {}
          var e401 = new Error("unauthenticated");
          e401.status = 401;
          throw e401;
        }
        var ct = res.headers.get("content-type") || "";
        var parse = res.status === 204 ? Promise.resolve(null)
          : (ct.indexOf("application/json") !== -1 ? res.json() : res.text());
        return parse.then(function (data) {
          if (!res.ok) {
            var err = new Error((data && data.message) || ("HTTP " + res.status));
            err.status = res.status;
            err.body = data;
            throw err;
          }
          return data;
        });
      });
    });
  }

  window.VFIApi = {
    base: BASE,
    get:  function (p, o) { return request("GET", p, null, o); },
    post: function (p, b, o) { return request("POST", p, b, o); },
    put:  function (p, b, o) { return request("PUT", p, b, o); },
    patch:function (p, b, o) { return request("PATCH", p, b, o); },
    del:  function (p, o) { return request("DELETE", p, null, o); },
    request: request,
    csrf: ensureCsrf
  };
})();
