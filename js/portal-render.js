/* =====================================================================
   portal-render.js — overlay admin-managed content onto the VFI Partner
   console pages (partner-*.html).

   Mirrors js/render.js: it reads the VFI store and, ONLY where content has
   been saved from the admin panel, replaces the matching container on the
   page. An empty store leaves every page exactly as its built-in demo.

   Loads AFTER js/portal.js (which injects the top bar / sidebar shell) and
   BEFORE each page's own inline wiring, so freshly rendered rows that carry
   the page's data-* hooks (e.g. data-res-download) still get wired.

   ES5 on purpose (var / function / string concat) to match the rest of js/.
   ===================================================================== */
(function () {
  "use strict";
  try {
    if (!window.VFI) return;

    var esc = VFI.esc;
    var $ = function (s, c) { return (c || document).querySelector(s); };
    var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };
    function fmt(d) { return VFI.fmtDate ? VFI.fmtDate(d) : (d || ""); }
    function trim(s) { return String(s == null ? "" : s).replace(/^\s+|\s+$/g, ""); }

    /* INITIALS = first letters of the first two words of a name, uppercased */
    function initials(name) {
      var parts = trim(name).split(/\s+/);
      var out = "";
      for (var i = 0; i < parts.length && i < 2; i++) {
        if (parts[i]) out += parts[i].charAt(0);
      }
      return out.toUpperCase();
    }

    var pp = VFI.partnerPortal ? (VFI.partnerPortal() || {}) : {};

    /* -------------------------------- global: top-bar partner name --------
       Phase 6: resolve the greeting from the AUTHENTICATED member, per agency —
       never a shared global string (one agency must not see another's name).
       Falls back to the markup default if not signed in. */
    if (window.VFIApi) {
      window.VFIApi.get("/api/partner/me", { noRedirect: true }).then(function (me) {
        var name = me && me.member ? me.member.name : "";
        if (!name) return;
        var nameEl = $(".pp-user__name");
        var avEl = $(".pp-user__av");
        if (nameEl) nameEl.textContent = "Hello, " + name + "!";
        if (avEl) avEl.textContent = String((me.member.initial || name.charAt(0))).toUpperCase();
      })["catch"](function () { /* not signed in — leave the markup default */ });
    }

    /* --------------------------------------------- per-container renderers -
       Each renderer runs only when its store data is non-empty; otherwise it
       returns and the page's built-in markup is left untouched. Text going
       into innerHTML is always escaped; textContent needs no escaping. */
    var R = {
      /* ---- partnerPortal text fields (textContent, no escaping needed) ---- */
      welcome: function (el) { if (pp.welcome) el.textContent = pp.welcome; },
      tierName: function (el) { if (pp.tierName) el.textContent = pp.tierName; },
      loanText: function (el) { if (pp.loanText) el.textContent = pp.loanText; },
      accomText: function (el) { if (pp.accomText) el.textContent = pp.accomText; },
      testprepText: function (el) { if (pp.testprepText) el.textContent = pp.testprepText; },

      /* ---- benefits: one <li> per non-blank line ---- */
      benefits: function (el) {
        var lines = String(pp.benefits || "").split(/\r?\n/).filter(function (l) { return trim(l) !== ""; });
        if (!lines.length) return;
        el.innerHTML = lines.map(function (t) {
          return '<li><svg class="ic ic--sm"><use href="#pi-check"/></svg> ' + esc(trim(t)) + "</li>";
        }).join("");
      },

      /* ---- quick links ---- */
      quicklinks: function (el) {
        var items = VFI.list("ppQuicklinks");
        if (!items.length) return;
        el.innerHTML = items.map(function (q) {
          return '<a class="pp-quicklink" href="' + esc(q.url || "#") + '">' +
            '<svg class="ic ic--sm"><use href="#pi-arrow"/></svg> ' + esc(q.label || "") + "</a>";
        }).join("");
      },

      /* ---- regional manager cards ---- */
      managers: function (el) {
        var items = VFI.list("ppManagers");
        if (!items.length) return;
        el.innerHTML = items.map(function (m) {
          return '<div class="pp-manager">' +
            '<div class="pp-manager__top">' +
              '<span class="pp-manager__av">' + esc(initials(m.name)) + "</span>" +
              "<div>" +
                '<div class="pp-manager__name">' + esc(m.name || "") + "</div>" +
                '<div class="pp-manager__role">' + esc(m.role || "") + "</div>" +
                '<div class="pp-manager__meta">' + esc(m.phone || "") + " &nbsp;|&nbsp; " + esc(m.city || "") + "</div>" +
              "</div>" +
            "</div>" +
            '<a class="pp-manager__mail" href="mailto:' + esc(m.email || "") + '">' + esc(m.email || "") + "</a>" +
          "</div>";
        }).join("");
      },

      /* ---- important updates rows ---- */
      updates: function (el) {
        var items = VFI.list("ppUpdates");
        if (!items.length) return;

        function draw(filter) {
          var rows = filter
            ? items.filter(function (u) { return String(u.flag || "").toUpperCase() === filter; })
            : items;
          el.innerHTML = rows.length ? rows.map(function (u) {
            return '<div class="pp-update"><span class="pp-update__flag">' + esc(u.flag || "") + "</span>" +
              "<div>" +
                '<div class="pp-update__title">' + esc(u.title || "") + "</div>" +
                '<div class="pp-update__sub">' + esc(u.sub || "") + "</div>" +
                '<div class="pp-update__date">Update date — ' + esc(fmt(u.date)) + "</div>" +
              "</div></div>";
          }).join("") : '<p class="pp-datalist__meta">No updates for that country.</p>';
        }

        /* The country chips only toggled their own highlight and never filtered
           anything. They now actually narrow the list by the update's flag. */
        var chips = Array.prototype.slice.call(document.querySelectorAll("[data-upd-filter]"));
        chips.forEach(function (chip) {
          chip.addEventListener("click", function () {
            chips.forEach(function (c) { c.classList.toggle("is-on", c === chip); });
            draw(chip.getAttribute("data-upd-filter").toUpperCase());
          });
        });

        draw("");
      },

      /* ---- Learning Resources documents (matches partner-resources.html) ---- */
      docs: function (el) {
        var items = VFI.list("ppDocs");
        if (!items.length) return;
        el.innerHTML = items.map(function (d) {
          return '<article class="pg-res__doc">' +
            '<div class="pg-res__doc-ic"><svg class="ic"><use href="#pi-file"/></svg></div>' +
            '<div class="pg-res__doc-title">' + esc(d.title || "") + "</div>" +
            '<div class="pg-res__doc-meta"><span>' + esc(fmt(d.date)) + "</span><span>" + esc(d.size || "") + "</span></div>" +
            '<button type="button" class="pp-btn pp-btn--primary pp-btn--block" data-res-download>' +
              '<svg class="ic ic--sm"><use href="#pi-download"/></svg> Download</button>' +
          "</article>";
        }).join("");
      },

      /* ---- Email Updates table rows (matches partner-email-updates.html) ---- */
      emails: function (el) {
        var items = VFI.list("ppEmails");
        if (!items.length) return;
        el.innerHTML = items.map(function (m, i) {
          return "<tr>" +
            '<td class="pp-table__num">' + (i + 1) + "</td>" +
            '<td class="pp-table__subj">' + esc(m.subject || "") + "</td>" +
            '<td class="pp-table__date">' + esc(fmt(m.date)) + "</td>" +
            '<td class="pg-mail__c-action"><button type="button" class="pp-btn pp-btn--primary pp-btn--sm" data-mail-view>View</button></td>' +
          "</tr>";
        }).join("");
      },

      /* ---- Notifications list (matches partner-notifications.html CSS) ---- */
      notifs: function (el) {
        var items = VFI.list("ppNotifs");
        if (!items.length) return;
        el.innerHTML = items.map(function (n) {
          return '<div class="pg-notif__row pg-notif__row--unread">' +
            '<span class="pg-notif__ic"><svg class="ic"><use href="#pi-bell"/></svg></span>' +
            '<div class="pg-notif__body">' +
              '<div class="pg-notif__title">' + esc(n.title || "") + "</div>" +
              '<div class="pg-notif__text">' + esc(n.text || "") + "</div>" +
              '<div class="pg-notif__time">' + esc(fmt(n.date)) + "</div>" +
            "</div>" +
            '<span class="pg-notif__dot"></span>' +
          "</div>";
        }).join("");
        el.removeAttribute("hidden");
        /* a populated list replaces the "brand-new account" empty state */
        var empty = $(".pg-notif__empty");
        if (empty) empty.setAttribute("hidden", "hidden");
      }
    };

    $$("[data-ppr]").forEach(function (el) {
      var fn = R[el.getAttribute("data-ppr")];
      if (!fn) return;
      try { fn(el); } catch (e) { /* one bad container never breaks the rest */ }
    });
  } catch (e) { /* store missing or DOM shape unexpected — no-op */ }
})();
