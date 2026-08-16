/* =====================================================================
   VFI — partner console data (Phase 7)
   Wires the console's tenant-scoped data surfaces to the Laravel backend
   through window.VFIApi (same-origin cookie session + CSRF):

     partner-students.html      GET  /api/partner/students (paged + filters + archived)
     partner-applications.html  GET  /api/partner/applications (the pipeline)
     partner-enquiries.html     GET  /api/partner/enquiries
     partner-resources.html     GET  /api/partner/resources (a REAL server query)
     partner-notifications.html GET  /api/partner/notifications + POST .../read
     the bell popover (any page)  reads the same notifications source

   Each block is gated on the markup it needs, so a page only runs its own.
   The pages render nothing until the API answers; a 401 makes js/api.js
   redirect to vfi-partner-login.html (the console guard).

   ES5 only: var / function / string concat, to match the rest of js/.
   ===================================================================== */
(function () {
  "use strict";

  if (!window.VFIApi) return;

  var $ = function (s, c) { return (c || document).querySelector(s); };
  var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };

  function esc(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
  }
  function quiet(err) { /* 401 → api.js already redirected; else leave the empty state */ }

  /* show a list container and hide the "nothing here yet" illustration */
  function swap(listEl, emptyEl, hasRows) {
    if (listEl) listEl.hidden = !hasRows;
    if (emptyEl) emptyEl.hidden = !!hasRows;
  }

  /* ==================================================================
     STUDENTS — partner-students.html
     ================================================================== */
  (function students() {
    var search = $("#ppStuSearch");
    var empty = $(".pp-empty");
    if (!search || !document.body.getAttribute("data-pp-page")) return;
    if (document.body.getAttribute("data-pp-page") !== "students") return;

    var host = document.createElement("div");
    host.className = "pp-card pp-datalist";
    host.hidden = true;
    if (empty && empty.parentNode) empty.parentNode.insertBefore(host, empty);

    var archived = false;

    function row(s) {
      return '<tr>' +
        "<td>" + esc(s.public_ref) + "</td>" +
        "<td>" + esc(s.name) + "</td>" +
        "<td>" + esc(s.email) + "</td>" +
        "<td>" + esc(s.destination_country || "—") + "</td>" +
        "<td>" + esc(s.intake || "—") + "</td>" +
        '<td><button type="button" class="pp-btn pp-btn--ghost pp-btn--sm" data-shortlist="' + s.id +
          '" data-name="' + esc(s.name || s.email) + '">Shortlist</button></td>' +
        "</tr>";
    }

    /* Programs saved from Search Programs land against a student. Without this
       they were written correctly and then unreachable — saved, invisible, and
       indistinguishable from lost. */
    function openShortlist(studentId, studentName) {
      var box = $("#ppSlModal");
      if (!box) return;
      $("#ppSlTitle").textContent = "Shortlist — " + studentName;
      var body = $("#ppSlBody");
      body.innerHTML = '<p class="pp-datalist__meta">Loading…</p>';
      box.classList.add("is-open");

      window.VFIApi.get("/api/partner/students/" + studentId + "/shortlist", {})
        .then(function (res) {
          var rows = res.data || [];
          if (!rows.length) {
            body.innerHTML = '<p class="pp-datalist__meta">Nothing saved yet. Use <b>Search Programs</b>, open a program and save it to this student.</p>';
            return;
          }
          body.innerHTML = '<table class="pp-table"><thead><tr><th>Program</th><th>University</th><th>Intake</th><th>Tuition</th><th>Note</th><th></th></tr></thead><tbody>' +
            rows.map(function (r) {
              var t = r.tuition ? (r.tuition.currency + " " + Math.round(r.tuition.minor / 100).toLocaleString()) : "—";
              var intake = r.next_intake ? (r.next_intake.season + " " + r.next_intake.year) : "—";
              return "<tr><td>" + esc(r.title || "—") + "</td><td>" + esc(r.university || "—") + "</td>" +
                "<td>" + esc(intake) + "</td><td>" + esc(t) + "</td><td>" + esc(r.note || "—") + "</td>" +
                '<td><button type="button" class="pp-btn pp-btn--ghost pp-btn--sm" data-unsave="' + r.program_id +
                  '" data-sid="' + studentId + '">Remove</button></td></tr>';
            }).join("") + "</tbody></table>";
        })["catch"](function () {
          body.innerHTML = '<p class="pp-datalist__meta">Could not load the shortlist.</p>';
        });
    }

    function load() {
      var qs = [];
      var kw = $("#ppStuKeyword");
      if (kw && kw.value.trim()) qs.push("q=" + encodeURIComponent(kw.value.trim()));
      if (archived) qs.push("archived=1");
      window.VFIApi.get("/api/partner/students" + (qs.length ? "?" + qs.join("&") : ""), {})
        .then(function (res) {
          var rows = res.data || [];
          host.innerHTML = rows.length
            ? '<table class="pp-table"><thead><tr><th>Ref</th><th>Name</th><th>Email</th><th>Destination</th><th>Intake</th><th>Saved programs</th></tr></thead><tbody>' +
              rows.map(row).join("") + "</tbody></table>" +
              '<p class="pp-datalist__meta">' + rows.length + " of " + res.meta.total + (archived ? " archived" : "") + " student(s)</p>"
            : "";
          swap(host, empty, rows.length > 0);
        })["catch"](quiet);
    }

    /* Modal shell, injected once so partner-students.html stays untouched. */
    (function mountShortlistModal() {
      if ($("#ppSlModal")) return;
      // Uses the console's own modal contract: .pp-modal is visibility-hidden
      // and becomes visible via .is-open, with the panel as .pp-modal__card.
      // Toggling the hidden attribute alone would leave it invisible.
      var el = document.createElement("div");
      el.className = "pp-modal";
      el.id = "ppSlModal";
      el.innerHTML =
        '<div class="pp-modal__backdrop" data-slclose></div>' +
        '<div class="pp-modal__card pp-modal__card--lg" role="dialog" aria-modal="true" aria-labelledby="ppSlTitle">' +
          '<div class="pp-modal__head"><h3 class="pp-modal__title" id="ppSlTitle">Shortlist</h3>' +
            '<button type="button" class="pp-modal__close" data-slclose aria-label="Close">&times;</button></div>' +
          '<div class="pp-modal__body" id="ppSlBody"></div>' +
        "</div>";
      document.body.appendChild(el);

      el.addEventListener("click", function (e) {
        // walk up: the click may land on an icon inside the button
        var n = e.target;
        while (n && n !== el) {
          if (n.getAttribute && n.getAttribute("data-slclose") !== null) { el.classList.remove("is-open"); return; }
          n = n.parentNode;
        }
      });
      document.addEventListener("keydown", function (e) { if (e.key === "Escape") el.classList.remove("is-open"); });

      // remove a saved program
      el.addEventListener("click", function (e) {
        var b = e.target.closest ? e.target.closest("[data-unsave]") : null;
        if (!b) return;
        window.VFIApi.del("/api/partner/students/" + b.getAttribute("data-sid") +
          "/shortlist/" + b.getAttribute("data-unsave"), {})
          .then(function () {
            var tr = b.closest("tr"); if (tr) tr.remove();
            if (window.VFIToast) window.VFIToast("Removed from shortlist.");
          })["catch"](function () { if (window.VFIToast) window.VFIToast("Could not remove it."); });
      });
    })();

    host.addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("[data-shortlist]") : null;
      if (b) openShortlist(b.getAttribute("data-shortlist"), b.getAttribute("data-name"));
    });

    search.addEventListener("click", function () { archived = false; load(); });
    var kwIn = $("#ppStuKeyword");
    if (kwIn) kwIn.addEventListener("keydown", function (e) { if (e.key === "Enter") { archived = false; load(); } });
    var arch = $("#ppArchived");
    if (arch) arch.addEventListener("click", function () { archived = !archived; load(); });
    document.addEventListener("vfi:student-created", function () { archived = false; load(); });
    load();
  })();

  /* ==================================================================
     APPLICATIONS — partner-applications.html (greenfield pipeline table)
     ================================================================== */
  (function applications() {
    if (document.body.getAttribute("data-pp-page") !== "applications") return;
    var main = $(".pp-wrap") || $(".pp-main");
    if (!main) return;

    var host = document.createElement("div");
    host.className = "pp-card pp-datalist";
    main.appendChild(host);

    var LABEL = {
      submitted: "Submitted", review: "Under Review", offer: "Offer", conditional: "Conditional Offer",
      payment: "Payment", visa_received: "Visa Received", visa_rejected: "Visa Rejected",
      non_enrolment: "Non-Enrolment", deferral: "Deferral", pending_from_partner: "Pending from Partner"
    };

    function row(a) {
      return "<tr>" +
        "<td>" + esc(a.student && a.student.name) + "</td>" +
        "<td>" + esc(a.student && a.student.public_ref) + "</td>" +
        '<td><span class="pp-badge">' + esc(LABEL[a.status] || a.status) + "</span></td>" +
        "<td>" + esc(a.intake || "—") + "</td>" +
        "<td>" + esc(a.ack_no || "—") + "</td>" +
        "<td>" + esc(a.deadline_at ? a.deadline_at.slice(0, 10) : "—") + "</td>" +
        "</tr>";
    }

    window.VFIApi.get("/api/partner/applications", {}).then(function (res) {
      var rows = res.data || [];
      host.innerHTML = rows.length
        ? '<table class="pp-table"><thead><tr><th>Student</th><th>Ref</th><th>Status</th><th>Intake</th><th>Ack no.</th><th>Deadline</th></tr></thead><tbody>' +
          rows.map(row).join("") + "</tbody></table>" +
          '<p class="pp-datalist__meta">' + res.meta.total + " application(s)</p>"
        : '<p class="pp-datalist__meta">No applications yet. Register a student, then create their application.</p>';
    })["catch"](quiet);
  })();

  /* ==================================================================
     ENQUIRIES — partner-enquiries.html
     ================================================================== */
  (function enquiries() {
    if (document.body.getAttribute("data-pp-page") !== "enquiries") return;
    var empty = $(".pp-empty");
    var main = $(".pp-wrap") || $(".pp-main");
    if (!main) return;

    var host = document.createElement("div");
    host.className = "pp-card pp-datalist";
    host.hidden = true;
    if (empty && empty.parentNode) empty.parentNode.insertBefore(host, empty); else main.appendChild(host);

    function docChip(d) {
      var cls = d.readable ? "pp-badge" : "pp-badge pp-badge--warn";
      var txt = d.readable ? d.name : d.name + " (scanning/blocked)";
      return '<span class="' + cls + '">' + esc(txt) + "</span>";
    }
    function row(e) {
      return "<tr>" +
        "<td>" + esc(e.name || "—") + "</td>" +
        "<td>" + esc(e.destination || "—") + "</td>" +
        "<td>" + esc(e.preferred_study_area || "—") + "</td>" +
        "<td>" + (e.documents && e.documents.length ? e.documents.map(docChip).join(" ") : "—") + "</td>" +
        "<td>" + esc(e.created_at ? e.created_at.slice(0, 10) : "") + "</td>" +
        "</tr>";
    }

    function load() {
      window.VFIApi.get("/api/partner/enquiries", {}).then(function (res) {
        var rows = res.data || [];
        host.innerHTML = rows.length
          ? '<table class="pp-table"><thead><tr><th>Student</th><th>Destination</th><th>Study area</th><th>Documents</th><th>Created</th></tr></thead><tbody>' +
            rows.map(row).join("") + "</tbody></table>"
          : "";
        swap(host, empty, rows.length > 0);
      })["catch"](quiet);
    }
    document.addEventListener("vfi:enquiry-created", load);
    load();
  })();

  /* ==================================================================
     LEARNING RESOURCES — a REAL server query (was a fake client filter)
     ================================================================== */
  (function resources() {
    var docs = $('[data-ppr="docs"]');
    var countriesBox = $("#resCountries");
    var categoriesBox = $("#resCategories");
    if (!docs || !countriesBox) return;

    var state = { country: "", category: "", q: "" };

    function paintFacets(list, box, key) {
      if (!box) return;
      box.innerHTML = ['<button type="button" class="pg-res__item is-on" data-val="">All</button>']
        .concat(list.map(function (v) {
          return '<button type="button" class="pg-res__item" data-val="' + esc(v) + '">' + esc(v) + "</button>";
        })).join("");
      box.addEventListener("click", function (e) {
        var btn = e.target.closest ? e.target.closest("[data-val]") : null;
        if (!btn) return;
        $$("[data-val]", box).forEach(function (b) { b.classList.toggle("is-on", b === btn); });
        state[key] = btn.getAttribute("data-val");
        load();
      });
    }

    function load(first) {
      var qs = [];
      if (state.country) qs.push("country=" + encodeURIComponent(state.country));
      if (state.category) qs.push("category=" + encodeURIComponent(state.category));
      if (state.q) qs.push("q=" + encodeURIComponent(state.q));
      window.VFIApi.get("/api/partner/resources" + (qs.length ? "?" + qs.join("&") : ""), {})
        .then(function (res) {
          var rows = res.data || [];
          docs.innerHTML = rows.length
            ? rows.map(function (d) {
                var href = d.url ? esc(d.url) : "#";
                return '<a class="pg-res__doc" href="' + href + '" target="_blank" rel="noopener">' +
                  '<span class="pg-res__doc-title">' + esc(d.title) + "</span>" +
                  '<span class="pg-res__doc-meta">' + esc(d.country || "") + (d.category ? " · " + esc(d.category) : "") +
                  (d.size ? " · " + esc(d.size) : "") + "</span></a>";
              }).join("")
            : '<p class="pp-datalist__meta">No documents match this filter.</p>';
          if (first) {
            paintFacets(res.countries || [], countriesBox, "country");
            paintFacets(res.categories || [], categoriesBox, "category");
          }
        })["catch"](quiet);
    }

    var search = $("#resSearch");
    if (search) {
      var t = 0;
      search.addEventListener("input", function () {
        window.clearTimeout(t);
        t = window.setTimeout(function () { state.q = search.value.trim(); load(); }, 250);
      });
    }
    load(true);
  })();

  /* ==================================================================
     NOTIFICATIONS — the page AND the bell popover share this source
     ================================================================== */
  (function notifications() {
    var list = $('[data-ppr="notifs"]');
    var empty = $(".pg-notif__empty");
    var pop = $("#ppNotif");

    function item(n) {
      return '<div class="pg-notif__row' + (n.read ? "" : " is-unread") + '">' +
        '<div class="pg-notif__title">' + esc(n.title) + "</div>" +
        (n.body ? '<div class="pg-notif__text">' + esc(n.body) + "</div>" : "") +
        '<div class="pg-notif__meta">' + esc(n.created_at ? n.created_at.slice(0, 10) : "") + "</div>" +
        "</div>";
    }

    function paint(res) {
      var rows = res.data || [];
      if (list) {
        list.innerHTML = rows.map(item).join("");
        swap(list, empty, rows.length > 0);
      }
      if (pop) {
        var body = pop.querySelector(".pp-pop__body") || pop;
        body.innerHTML = rows.length
          ? rows.slice(0, 5).map(item).join("")
          : '<p class="pp-pop__empty">No notifications found</p>';
      }
      var bell = $("#ppBell");
      if (bell) bell.setAttribute("data-unread", String(res.unread_count || 0));
    }

    function load() {
      if (!list && !pop) return;
      window.VFIApi.get("/api/partner/notifications", {}).then(function (res) {
        paint(res);
        /* opening the page marks everything read */
        if (list && res.unread_count) {
          window.VFIApi.post("/api/partner/notifications/read", {}, {}).then(function () {
            var bell = $("#ppBell");
            if (bell) bell.setAttribute("data-unread", "0");
          })["catch"](quiet);
        }
      })["catch"](quiet);
    }
    load();
  })();
})();
