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
                '<td style="white-space:nowrap">' +
                  '<button type="button" class="pp-btn pp-btn--primary pp-btn--sm" data-apply-prog="' + r.program_id +
                    '" data-sid="' + studentId + '"' +
                    ' data-season="' + esc((r.next_intake && r.next_intake.season) || "") + '"' +
                    ' data-year="' + esc((r.next_intake && r.next_intake.year) || "") + '">Apply</button> ' +
                  '<button type="button" class="pp-btn pp-btn--ghost pp-btn--sm" data-unsave="' + r.program_id +
                    '" data-sid="' + studentId + '">Remove</button>' +
                "</td></tr>";
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

      /* Create the application. This is the point of the console: a shortlisted
         program plus the student it was saved for is exactly what the pipeline
         needs, so applying is one click rather than a re-keyed form. */
      el.addEventListener("click", function (e) {
        var b = e.target.closest ? e.target.closest("[data-apply-prog]") : null;
        if (!b) return;
        b.disabled = true;
        var body = {
          student_id: Number(b.getAttribute("data-sid")),
          program_id: Number(b.getAttribute("data-apply-prog"))
        };
        var season = b.getAttribute("data-season");
        var year = b.getAttribute("data-year");
        if (season) body.intake_month = season;
        if (year) body.intake_year = Number(year);

        window.VFIApi.post("/api/partner/applications", body, {})
          .then(function () {
            b.textContent = "Applied";
            if (window.VFIToast) window.VFIToast("Application created — see the Applications page.");
            document.dispatchEvent(new CustomEvent("vfi:application-created"));
          })["catch"](function () {
            b.disabled = false;
            if (window.VFIToast) window.VFIToast("Could not create the application.");
          });
      });

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
     DASHBOARD — partner-dashboard.html
     The KPI tiles ship with a hardcoded 0 and nothing ever replaced it, so the
     dashboard looked broken even once a partner had real applications. The
     counts are computed server-side per tenant; this just paints them.
     ================================================================== */
  (function dashboard() {
    if (document.body.getAttribute("data-pp-page") !== "dashboard") return;

    function paint() {
      window.VFIApi.get("/api/partner/dashboard/kpis", {})
        .then(function (res) {
          var counts = (res && res.counts) || {};
          $$("[data-pp-kpi]").forEach(function (el) {
            var k = el.getAttribute("data-pp-kpi");
            el.textContent = k === "total" ? (res.total || 0) : (counts[k] || 0);
          });
        })["catch"](quiet);

      /* The dashboard has four deadline tabs (d0..d3) over four static panels.
         There were no [data-pp-deadline] elements at all, so an earlier version
         of this wrote its counts into nothing. Map tab -> API bucket instead,
         put the count on the tab and the real wording in the panel. */
      var BUCKET = { d0: "today", d1: "tomorrow", d2: "in_7_days", d3: "in_14_days" };
      var WHEN = { d0: "today", d1: "tomorrow", d2: "in the next 7 days", d3: "in the next 14 days" };

      window.VFIApi.get("/api/partner/dashboard/deadlines", {})
        .then(function (res) {
          var b = (res && res.buckets) ? res.buckets : res;
          if (!b) return;
          Object.keys(BUCKET).forEach(function (tabId) {
            var n = b[BUCKET[tabId]];
            if (n == null) return;

            var tab = document.querySelector('[data-pp-tab="' + tabId + '"]');
            if (tab) {
              var base = tab.getAttribute("data-label") || tab.textContent.trim();
              tab.setAttribute("data-label", base);
              tab.textContent = base + " (" + n + ")";
            }
            /* List the actual cases. A count with a permanent "No upcoming
               deadlines" underneath it is not work a partner can act on. */
            var panel = document.getElementById(tabId);
            if (!panel) return;
            var items = (b.items && b.items[BUCKET[tabId]]) || [];

            if (!items.length) {
              var msg = panel.querySelector(".pp-empty__text");
              if (msg) msg.textContent = "No deadlines " + WHEN[tabId] + ".";
              return;
            }

            panel.innerHTML = '<ul class="pp-dlist">' + items.map(function (it) {
              var st = String(it.status || "").replace(/_/g, " ");
              return "<li><span class=\"pp-dlist__who\">" + esc(it.student) + "</span>" +
                "<span class=\"pp-dlist__when\">" + esc(st) + " · " + esc(it.deadline || "") + "</span></li>";
            }).join("") + "</ul>" +
              (n > items.length
                ? '<p class="pp-datalist__meta">Showing ' + items.length + " of " + n + ".</p>"
                : "");
          });
        })["catch"](quiet);
    }

    paint();
    document.addEventListener("vfi:application-created", paint);
  })();

  /* ==================================================================
     APPLICATIONS — partner-applications.html (pipeline table + case detail)

     The agency files ON THE STUDENT'S BEHALF, so the agency is the one who has
     to supply the paperwork — but the table was six read-only columns with no
     detail and no sign of what was missing, so a case could sit unprocessable
     and look perfectly fine. The Documents column and the panel exist to make
     "what is still outstanding" the first thing on screen.

     What this block reads:
       GET  /api/partner/applications        -> { data: [ ... ], meta }   (no readiness)
       GET  /api/partner/applications/{id}   -> { application, events, readiness }
       GET  /api/partner/students/{sid}/documents -> { data: [ document rows ] }
       POST /api/partner/students/{sid}/documents/{type}  (multipart, field "file")

     readiness is App\Services\ApplicationReadiness's verdict — lists of document
     type KEYS ({ ready, required, present, verified, missing, rejected }) — and
     is never recomputed here: the server is the only thing that knows which
     types are required (a destination-dependent type does not gate a case).
     A document row carries what the verdict cannot: the human name, the
     rejection reason and the file, so both are fetched when a case is opened.
     The list has no readiness, so the column fills in per opened case rather
     than costing one request per row on every page view.
     ================================================================== */
  (function applications() {
    if (document.body.getAttribute("data-pp-page") !== "applications") return;
    var main = $(".pp-wrap") || $(".pp-main");
    if (!main) return;

    /* The page's own "you have no applications" panel, resolved before this
       block puts anything into the wrap so a js-rendered empty state can never
       be mistaken for it. nginx caches the assets for a week and the html not at
       all, so a browser can hold a copy of this page from before the id existed
       next to today's js — and then the notice sat on screen above a table
       listing five applications. The class outlives the id, so match on either. */
    var staticNotice = $("#ppAppsEmpty") || $(".pp-notice", main);

    var host = document.createElement("div");
    host.className = "pp-card pp-datalist";
    main.appendChild(host);

    var modal = $("#ppAppModal");
    var panel = $("#ppAppBody");
    var titleEl = $("#ppAppTitle");

    /* Same cache split, worse symptom: the detail panel this block fills lives
       in partner-applications.html, so a page held from before it was added has
       no #ppAppModal and View did nothing whatsoever — no panel, no error, and
       no way to send a document, because the upload controls only exist inside
       it. Build it instead of trusting the page to carry it. Static markup only:
       every value below is a literal, and it mirrors the page's copy so the
       shared sheet's .pp-modal rules dress it identically. */
    function buildModal() {
      var el = document.createElement("div");
      el.className = "pp-modal";
      el.id = "ppAppModal";
      el.innerHTML =
        '<div class="pp-modal__backdrop" data-appclose></div>' +
        '<div class="pp-modal__card pp-modal__card--lg" role="dialog" aria-modal="true" aria-labelledby="ppAppTitle">' +
          '<div class="pp-modal__head">' +
            '<h3 class="pp-modal__title" id="ppAppTitle">Application</h3>' +
            '<button type="button" class="pp-modal__close" data-appclose aria-label="Close">&times;</button>' +
          "</div>" +
          '<div class="pp-modal__body" id="ppAppBody"></div>' +
        "</div>";
      document.body.appendChild(el);   // outside <main>: .pp-modal is fixed and must not inherit its stacking
      fallbackCss();
      return el;
    }

    /* The .pg-app__* rules sit in a <style> block inside the html, so a page
       stale enough to be missing the panel is missing them too. Nearly all of
       that block is polish the shared sheet already degrades gracefully without
       — .pp-modal, .pp-modal__card, .pp-btn, .pp-badge and [hidden] are all in
       css/partner-portal.css, so a built panel opens, scrolls and closes, and an
       unstyled banner or timeline still reads. One pair is not polish: without
       .pg-app__up the file input renders at its native width inside a small pill
       label and .pp-modal__card clips the overflow, so on a narrow screen the
       partner's only control for sending a document can end up cut off. That
       pair only — the page's block is deliberately not duplicated here. */
    function fallbackCss() {
      if ($("#ppAppFallbackCss")) return;
      var st = document.createElement("style");
      st.id = "ppAppFallbackCss";
      st.textContent =
        ".pg-app__up{position:relative;overflow:hidden;margin:0}" +
        ".pg-app__up input{position:absolute;top:0;right:0;bottom:0;left:0;" +
        "width:100%;height:100%;opacity:0;cursor:pointer}";
      document.head.appendChild(st);
    }

    if (!modal || !panel || !titleEl) {
      // half a panel is no more use than none, and leaving it would duplicate ids
      if (modal && modal.parentNode) modal.parentNode.removeChild(modal);
      modal = buildModal();
      panel = $("#ppAppBody", modal);
      titleEl = $("#ppAppTitle", modal);
    }

    var LABEL = {
      submitted: "Submitted", review: "Under Review", offer: "Offer", conditional: "Conditional Offer",
      payment: "Payment", visa_received: "Visa Received", visa_rejected: "Visa Rejected",
      non_enrolment: "Non-Enrolment", deferral: "Deferral", pending_from_partner: "Pending from Partner"
    };
    var DOC_LABEL = { missing: "Missing", uploaded: "Uploaded", verified: "Verified", rejected: "Rejected" };
    var DOC_TONE = {
      missing: "pp-badge--warn", uploaded: "pp-badge--info",
      verified: "pp-badge--ok", rejected: "pg-app__badge--bad"
    };
    var ACTOR = { partner: "your agency", staff: "VFI staff", system: "the system", institution: "the institution" };

    /* Mirrors documents.max_bytes so a 40MB phone scan is refused before it is
       pushed up a slow link. The server still enforces the real limit. */
    var MAX_BYTES = 15 * 1024 * 1024;

    /* the case currently in the panel; uploads are addressed to its student */
    var openCase = { id: null, studentId: null, readiness: null, names: {} };

    function day(v) { return v ? String(v).slice(0, 10) : "—"; }
    function statusLabel(s) { return LABEL[s] || String(s == null ? "—" : s).replace(/_/g, " "); }

    /* ---- readiness ---------------------------------------------------- */

    /* The student's checklist covers both packs; only the 'application' one is
       in front of a case. Visa paperwork is collected after an offer and must
       not make a submitted case read as unready. */
    function appPack(docs) {
      var out = [];
      (docs || []).forEach(function (d) { if (!d.pack || d.pack === "application") out.push(d); });
      return out;
    }

    /* The server's verdict, counted — not re-decided. `required`/`present` etc.
       are lists of type keys, and a type the schema marks destination-dependent
       is deliberately absent from `required`, so counting rows on screen instead
       would call a case unready over paperwork nobody asked for. */
    function readinessFrom(given) {
      if (!given || !given.required) return null;
      var required = given.required || [];
      var present = given.present || [];
      var have = 0;
      for (var i = 0; i < required.length; i++) {
        if (present.indexOf(required[i]) !== -1) have++;
      }
      return {
        have: have,
        required: required.length,
        rejected: (given.rejected || []).length,
        // what the agency still has to do something about, in the server's words
        outstanding: (given.missing || []).concat(given.rejected || []),
        ready: !!given.ready
      };
    }

    /* Type keys come back from the readiness verdict; their human names only
       exist on the document rows. The "k:" prefix keeps a key like "constructor"
       out of Object.prototype. */
    function nameOf(key) {
      return openCase.names["k:" + key] || key;
    }

    function readyChip(r) {
      if (!r) return '<span class="pp-datalist__meta" title="Open the case to check">—</span>';
      var tone = r.rejected ? "pg-app__badge--bad" : (r.ready ? "pp-badge--ok" : "pp-badge--warn");
      var hint = r.rejected ? (r.rejected + " rejected")
        : (r.ready ? "Ready to process" : (r.required - r.have) + " still outstanding");
      return '<span class="pp-badge ' + tone + '" title="' + esc(hint) + '">' +
        esc(r.have + "/" + r.required) + "</span>";
    }

    /* Readiness learned by opening a case is written back into its row, so the
       column fills in as cases are reviewed. Fetching it per row on load would
       be one request per application on every page view. */
    function paintCell(id, r) {
      if (id == null || !r) return;
      $$("[data-docs-for]", host).forEach(function (cell) {
        if (cell.getAttribute("data-docs-for") === String(id)) cell.innerHTML = readyChip(r);
      });
    }

    /* ---- the list ----------------------------------------------------- */

    function row(a) {
      return "<tr>" +
        "<td>" + esc(a.student && a.student.name) + "</td>" +
        "<td>" + esc(a.student && a.student.public_ref) + "</td>" +
        '<td><span class="pp-badge">' + esc(statusLabel(a.status)) + "</span></td>" +
        "<td>" + esc(a.intake || "—") + "</td>" +
        "<td>" + esc(a.ack_no || "—") + "</td>" +
        "<td>" + esc(day(a.deadline_at)) + "</td>" +
        '<td data-docs-for="' + esc(a.id) + '">' + readyChip(readinessFrom(a.readiness)) + "</td>" +
        '<td><button type="button" class="pp-btn pp-btn--ghost pp-btn--sm" data-view="' + esc(a.id) + '">View</button></td>' +
        "</tr>";
    }

    /* Only ever used when the page carries no notice of its own. Rendering the
       empty state here too is what stops this block depending on the html for
       it at all: whichever copy of the page a browser is holding, "none yet"
       gets said instead of a blank wrap. Static markup. */
    var NO_ROWS =
      '<p class="pp-datalist__meta">You currently have no active applications for any students. ' +
      'Go to <a href="partner-students.html">Manage Students</a> to create a student and their application.</p>';

    function paint() {
      window.VFIApi.get("/api/partner/applications", {}).then(function (res) {
        var rows = res.data || [];
        // the static "you have no applications" panel must not stay on screen
        // above a table that is listing them
        if (staticNotice) staticNotice.hidden = rows.length > 0;

        host.innerHTML = rows.length
          ? '<div class="pp-tablewrap"><table class="pp-table"><thead><tr><th>Student</th><th>Ref</th><th>Status</th>' +
            "<th>Intake</th><th>Ack no.</th><th>Deadline</th><th>Documents</th><th></th></tr></thead><tbody>" +
            rows.map(row).join("") + "</tbody></table></div>" +
            '<p class="pp-datalist__meta">' + esc((res.meta && res.meta.total) || rows.length) + " application(s)</p>"
          : (staticNotice ? "" : NO_ROWS);   // when the page has a notice it already says this
      })["catch"](quiet);
    }

    /* ---- the detail panel --------------------------------------------- */

    function pair(k, v) {
      return '<dt class="pg-app__k">' + esc(k) + '</dt><dd class="pg-app__v">' + esc(v || "—") + "</dd>";
    }

    function summary(a, s) {
      var prog = a.program || {};
      return pair("Student", s.name) + pair("Email", s.email) +
        pair("Reference", a.public_ref || s.public_ref) +
        pair("Programme", prog.title) + pair("University", prog.university) +
        pair("Intake", a.intake) + pair("Status", statusLabel(a.status)) +
        pair("Deadline", day(a.deadline_at)) + pair("Ack no.", a.ack_no);
    }

    /* Rendered in payload order: the endpoint returns the append-only events by
       id, which is the order they happened even when two moves share a second.
       Re-sorting them here by timestamp would undo that. */
    function history(events) {
      if (!events || !events.length) return '<p class="pp-datalist__meta">No status changes recorded yet.</p>';

      return '<ol class="pg-app__hist">' + events.map(function (e) {
        var from = e.from != null ? e.from : e.from_status;
        var to = e.to != null ? e.to : e.to_status;
        var who = ACTOR[e.actor_type] || e.actor_type || "";
        var move = from ? statusLabel(from) + " → " + statusLabel(to) : statusLabel(to);

        return '<li class="pg-app__ev">' +
          '<div class="pg-app__ev-head">' + esc(move) + "</div>" +
          '<div class="pg-app__ev-meta">' + esc(day(e.occurred_at || e.created_at)) +
            (who ? " · moved by " + esc(who) : "") + "</div>" +
          (e.note ? '<div class="pg-app__ev-note">' + esc(e.note) + "</div>" : "") +
          "</li>";
      }).join("") + "</ol>";
    }

    /* The whole point of this screen: what is stopping this case, in one line,
       above the checklist rather than buried in it. */
    function banner(r) {
      if (!r) return "";
      if (r.ready && !r.rejected) {
        return '<div class="pg-app__banner pg-app__banner--ok">All ' + esc(r.required) +
          " required document" + (r.required === 1 ? " is" : "s are") +
          " in — VFI can process this application.</div>";
      }
      var lead = r.rejected
        ? "VFI rejected " + r.rejected + " document(s): replace them before this case can move."
        : "Not ready to process — " + (r.required - r.have) + " of " + r.required + " documents still outstanding.";
      var names = "";
      if (r.outstanding && r.outstanding.length) {
        names = " Outstanding: " + r.outstanding.map(nameOf).join(", ") + ".";
      }
      return '<div class="pg-app__banner' + (r.rejected ? " pg-app__banner--bad" : "") + '">' +
        esc(lead + names) + "</div>";
    }

    function docRow(d) {
      var st = d.status || "missing";
      var f = d.file || null;
      // a blob is on disk but unreadable until the scanner clears it, so nothing
      // offers to fetch it before then
      var scanning = !!(f && f.scan_status && f.scan_status !== "clean");
      var locked = st === "verified";

      return '<div class="pg-app__doc">' +
        '<div class="pg-app__doc-main">' +
          '<div class="pg-app__doc-name">' + esc(d.name || d.type) + "</div>" +
          '<div class="pg-app__doc-meta">' +
            esc(f ? (f.original_name || "Supplied") + (scanning ? " · being scanned" : "") : "Not supplied yet.") +
          "</div>" +
          (d.rejection_reason
            ? '<div class="pg-app__doc-why">Rejected: ' + esc(d.rejection_reason) + "</div>"
            : "") +
          '<div class="pg-app__doc-msg" data-msg hidden></div>' +
        "</div>" +
        '<span class="pp-badge ' + (DOC_TONE[st] || "pp-badge--warn") + '">' + esc(DOC_LABEL[st] || st) + "</span>" +
        '<div class="pg-app__doc-act">' +
          (locked
            ? '<span class="pp-datalist__meta">Locked</span>'
            : '<label class="pp-btn pp-btn--ghost pp-btn--sm pg-app__up">' + (f ? "Replace" : "Upload") +
              '<input type="file" data-up="' + esc(d.type) + '" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" />' +
              "</label>") +
          (f && !scanning
            ? '<button type="button" class="pp-btn pp-btn--soft pp-btn--sm" data-dl="' + esc(d.type) + '">Download</button>'
            : "") +
        "</div>" +
      "</div>";
    }

    /* The checklist, its banner and the list's Documents cell all come out of
       one paint, so they can never drift apart on screen. */
    function paintDocs(docs) {
      var box = $("#ppAppDocs", panel);
      if (!box) return;

      var pack = appPack(docs);
      openCase.names = {};
      pack.forEach(function (d) { openCase.names["k:" + d.type] = d.name || d.type; });

      var r = readinessFrom(openCase.readiness);
      paintCell(openCase.id, r);

      box.innerHTML = (r ? banner(r) : "") + (pack.length
        ? pack.map(docRow).join("")
        : '<p class="pp-datalist__meta">No document checklist is configured.</p>');
    }

    function paintDetail(res) {
      var a = res.application || res;
      var s = a.student || {};

      openCase.studentId = (s.id != null) ? s.id : (a.student_id != null ? a.student_id : null);
      openCase.readiness = res.readiness || a.readiness || null;
      titleEl.textContent = "Application — " + (s.name || a.public_ref || ("#" + (a.id || "")));

      panel.innerHTML =
        '<div class="pp-modal__group">Summary</div>' +
        '<dl class="pg-app__grid">' + summary(a, s) + "</dl>" +
        '<div class="pp-modal__group">Status history</div>' +
        history(res.events || a.events || []) +
        '<div class="pp-modal__group">Documents</div>' +
        '<div id="ppAppDocs"><p class="pp-datalist__meta">Loading the checklist…</p></div>';
    }

    /* The checklist lives on the STUDENT, not on the case, so it is a second
       read — the case answers what is outstanding, the student answers what each
       document actually is. Both are fetched only when a case is opened. */
    function loadDocs() {
      var want = openCase.id;

      /* Without the rows there are no names and no upload controls, but the
         verdict is still the headline — so the banner is painted anyway, naming
         the outstanding types by their keys. */
      function degraded(note) {
        var r = readinessFrom(openCase.readiness);
        paintCell(openCase.id, r);
        var box = $("#ppAppDocs", panel);
        if (box) box.innerHTML = (r ? banner(r) : "") + '<p class="pp-datalist__meta">' + note + "</p>";
      }

      if (openCase.studentId == null) { degraded("No student is attached to this case."); return; }

      window.VFIApi.get("/api/partner/students/" + encodeURIComponent(openCase.studentId) + "/documents", {})
        .then(function (res) {
          if (openCase.id !== want) return;    // a different case was opened meanwhile
          paintDocs((res && res.data) || []);
        })["catch"](function () {
          if (openCase.id !== want) return;
          degraded("Could not load the checklist — close and reopen the case to try again.");
        });
    }

    function loadCase() {
      var want = openCase.id;
      window.VFIApi.get("/api/partner/applications/" + encodeURIComponent(want), {})
        .then(function (res) {
          if (openCase.id !== want) return;
          paintDetail(res);
          loadDocs();
        })["catch"](function () {
          if (openCase.id !== want) return;
          panel.innerHTML = '<p class="pp-datalist__meta">Could not load this application.</p>';
        });
    }

    function openDetail(id) {
      if (!modal || !panel || !titleEl) {
        // the panel could not even be built — say something, because a View that
        // does nothing at all is the failure the client reported
        var dead = "Could not open this application. Please reload the page.";
        if (window.VFIToast) window.VFIToast(dead, "bad"); else window.alert(dead);
        return;
      }
      openCase.id = id;
      openCase.studentId = null;
      openCase.readiness = null;
      openCase.names = {};
      titleEl.textContent = "Application";
      panel.innerHTML = '<p class="pp-datalist__meta">Loading…</p>';
      modal.classList.add("is-open");
      loadCase();
    }

    /* ---- uploads ------------------------------------------------------- */

    function uploadError(err) {
      var b = err && err.body;
      // Laravel's 422 puts the real sentence under errors.file; 409/422/503 from
      // the controller put it in message. Either way the server's own words are
      // the only ones that know why.
      if (b && b.errors && b.errors.file && b.errors.file.length) return b.errors.file[0];
      if (b && b.message) return b.message;
      return "Upload failed. Please try again.";
    }

    function upload(input, type, file) {
      var rowEl = input.closest ? input.closest(".pg-app__doc") : null;
      var msg = rowEl ? $("[data-msg]", rowEl) : null;
      var label = input.parentNode;

      /* Says it on the row itself. textContent, never innerHTML: this carries a
         client filename and the server's own error text. VFIToast is only a
         fallback and it does innerHTML its argument, so that path is escaped. */
      function say(text, bad) {
        if (msg) {
          msg.hidden = false;
          msg.textContent = text;
          return;
        }
        if (window.VFIToast) { window.VFIToast(esc(text), bad ? "bad" : "ok"); return; }
        // last resort: a refused upload must never look like nothing happened.
        // Progress lines are dropped instead — they are not worth an alert.
        if (bad) window.alert(text);
      }

      if (openCase.studentId == null) { say("This case has no student on it — reload the page.", true); return; }
      if (file.size > MAX_BYTES) {
        input.value = "";
        say("That file is over 15 MB. Please send a smaller scan.", true);
        return;
      }

      if (label && label.classList) label.classList.add("is-busy");
      say("Uploading " + file.name + "…");

      var fd = new FormData();
      fd.append("file", file);      // the field name the API validates
      // VFIApi passes FormData through untouched so the browser sets its own
      // multipart boundary; setting Content-Type here would break the parse.

      window.VFIApi.post("/api/partner/students/" + encodeURIComponent(openCase.studentId) +
        "/documents/" + encodeURIComponent(type), fd, {})
        .then(function () {
          // The upload answers with the new checklist but not with a verdict, and
          // only the server decides whether the case is ready now — so the panel
          // is refreshed from the case rather than half-updated from this reply.
          if (window.VFIToast) window.VFIToast("Document uploaded.", "ok");
          loadCase();
        })["catch"](function (err) {
          if (label && label.classList) label.classList.remove("is-busy");
          input.value = "";           // let the same file be re-picked after a failure
          say(uploadError(err), true);
        });
    }

    /* ---- wiring -------------------------------------------------------- */

    host.addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("[data-view]") : null;
      if (b) openDetail(b.getAttribute("data-view"));
    });

    function closePanel() {
      if (!modal) return;
      modal.classList.remove("is-open");
      openCase.id = null;              // also makes any in-flight paint a no-op
      openCase.studentId = null;
      openCase.readiness = null;
    }

    if (modal) {
      // walk up: the click may land on an icon inside the button
      modal.addEventListener("click", function (e) {
        var n = e.target;
        while (n && n !== modal) {
          if (n.getAttribute && n.getAttribute("data-appclose") !== null) { closePanel(); return; }
          n = n.parentNode;
        }
      });
      document.addEventListener("keydown", function (e) { if (e.key === "Escape") closePanel(); });

      // delegated: the checklist is re-rendered after every upload, so per-row
      // handlers would be re-bound (and leaked) on each repaint
      modal.addEventListener("change", function (e) {
        var input = e.target;
        if (!input || !input.getAttribute || input.getAttribute("data-up") === null) return;
        var file = input.files && input.files[0];
        if (file) upload(input, input.getAttribute("data-up"), file);
      });

      modal.addEventListener("click", function (e) {
        var b = e.target.closest ? e.target.closest("[data-dl]") : null;
        if (!b || openCase.studentId == null) return;
        b.classList.add("is-busy");
        window.VFIApi.get("/api/partner/students/" + encodeURIComponent(openCase.studentId) +
          "/documents/" + encodeURIComponent(b.getAttribute("data-dl")) + "/download", {})
          .then(function (res) {
            b.classList.remove("is-busy");
            if (!res || !res.url) return;
            // Defence in depth: an href follows whatever scheme it is given, so
            // only the http(s) capability URL the API mints is ever followed.
            var href = String(res.url);
            if (!/^https?:\/\//i.test(href) && href.charAt(0) !== "/") return;

            // The token is single-use and lives for seconds: spend it now, and
            // never keep it anywhere a second reader could find it.
            var a = document.createElement("a");
            a.href = href;
            a.rel = "noopener";
            a.download = res.name || "document";
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
          })["catch"](function () {
            b.classList.remove("is-busy");
            if (window.VFIToast) window.VFIToast("That document is not available to download.", "bad");
          });
      });
    }

    paint();
    document.addEventListener("vfi:application-created", paint);
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

    var ICON = { application: "pi-doc", enquiry: "pi-enquiry", payment: "pi-wallet", update: "pi-info" };

    /* created_at is ISO from the API, but a broadcast update carries whatever
       the admin typed ("03 Mar 2026") — only trim the ISO shape, or a hand-typed
       date gets cut mid-month. */
    function stamp(v) {
      var s = String(v == null ? "" : v);
      return /^\d{4}-\d{2}-\d{2}/.test(s) ? s.slice(0, 10) : s;
    }

    /* The link is server data going into an href, so it is constrained to a
       relative console path or an http(s) URL: a stored "javascript:" would
       otherwise execute on click. */
    function safeLink(v) {
      var s = String(v == null ? "" : v).trim();
      if (!s) return "";
      if (/^https?:\/\//i.test(s)) return s;
      if (/^[a-zA-Z][a-zA-Z0-9+.\-]*:/.test(s)) return "";   // any other scheme
      if (s.slice(0, 2) === "//") return "";                 // protocol-relative — not ours
      return s;
    }

    /* The pipeline writes one notification per event, so a partner who files
       twice in a morning sees two identical "Application submitted" lines and
       reads them as two separate things happening. Exact matches (title, body
       and day) fold into one row carrying a count. */
    function collapse(rows) {
      var out = [], seen = {};
      (rows || []).forEach(function (n) {
        // "k:" prefix so a title of "constructor" cannot hit Object.prototype
        var key = "k:" + JSON.stringify([n.title || "", n.body || "", stamp(n.created_at)]);
        var hit = seen[key];
        if (hit) {
          hit.count++;
          if (!n.read) hit.read = false;    // one unread copy keeps the row unread
          return;
        }
        hit = {
          id: n.id, kind: n.kind, title: n.title, body: n.body, link: n.link,
          read: !!n.read, created_at: n.created_at, isUpdate: !!n.isUpdate, count: 1
        };
        seen[key] = hit;
        out.push(hit);
      });
      return out;
    }

    function item(n) {
      var href = safeLink(n.link);
      var tag = href ? "a" : "div";
      var icon = ICON[n.isUpdate ? "update" : n.kind] || "pi-bell";

      return "<" + tag + ' class="pg-notif__row' + (n.read ? "" : " pg-notif__row--unread") + '"' +
        (href ? ' href="' + esc(href) + '"' : "") + ">" +
        '<div class="pg-notif__ic"><svg class="ic ic--sm"><use href="#' + icon + '"/></svg></div>' +
        '<div class="pg-notif__body">' +
          '<div class="pg-notif__title">' + esc(n.title) +
            (n.count > 1 ? '<span class="pg-notif__count">&times;' + esc(n.count) + "</span>" : "") + "</div>" +
          (n.body ? '<div class="pg-notif__text">' + esc(n.body) + "</div>" : "") +
          '<div class="pg-notif__time">' + esc(stamp(n.created_at)) + "</div>" +
        "</div>" +
        '<div class="' + (n.read ? "pg-notif__seen" : "pg-notif__dot") + '"></div>' +
        "</" + tag + ">";
    }

    /* partner-notifications.html styles pg-notif__* inside its own <style>, so
       the bell popover — which lives in the chrome on every page — had those
       classes and no rules at all, and rendered as stacked plain text. These are
       the popover's copy of them, scoped to .pp-pop so they cannot reach the
       page's own list — plus the linked row and the duplicate count, which are
       new here and no page styles at all. */
    function mountStyles() {
      if (document.getElementById("ppNotifCss")) return;
      var css = document.createElement("style");
      css.id = "ppNotifCss";
      css.textContent =
        ".pp-pop .pg-notif__row{display:flex;align-items:flex-start;gap:11px;padding:12px 16px;" +
          "border-bottom:1px solid var(--pp-line-2)}" +
        ".pp-pop .pg-notif__row:last-child{border-bottom:0}" +
        ".pp-pop .pg-notif__row--unread{background:var(--pp-teal-soft)}" +
        ".pp-pop .pg-notif__ic{width:32px;height:32px;border-radius:9px;flex:none;display:grid;" +
          "place-items:center;background:#fff;border:1px solid var(--pp-line);color:var(--pp-teal)}" +
        ".pp-pop .pg-notif__body{flex:1 1 auto;min-width:0}" +
        ".pp-pop .pg-notif__title{font-family:var(--pp-display);font-weight:700;color:var(--pp-ink);font-size:13.5px}" +
        ".pp-pop .pg-notif__text{color:var(--pp-muted);font-size:12.5px;margin-top:2px;line-height:1.45}" +
        ".pp-pop .pg-notif__time{color:var(--pp-faint);font-size:11.5px;margin-top:4px}" +
        ".pp-pop .pg-notif__dot{width:8px;height:8px;border-radius:50%;background:var(--pp-emerald);flex:none;margin-top:6px}" +
        ".pp-pop .pg-notif__seen{width:8px;flex:none}" +
        "a.pg-notif__row{color:inherit;display:flex}" +
        "a.pg-notif__row:hover{text-decoration:none;background:var(--pp-card-2)}" +
        ".pg-notif__count{display:inline-block;margin-left:6px;padding:1px 7px;border-radius:999px;" +
          "background:var(--pp-teal-soft);color:var(--pp-teal-dark);font-size:11px;font-weight:700;" +
          "font-family:var(--pp-display);vertical-align:middle}";
      document.head.appendChild(css);
    }

    /* Important Updates are staff-authored announcements (the ppUpdates
       collection) and were only ever visible in a card on the dashboard. A
       partner who never opens the dashboard never saw them, so they are folded
       into the bell alongside the per-agency notifications. They carry no read
       state — they are broadcasts, not addressed to one agency — so they never
       affect the unread count. */
    function updateItems() {
      if (!window.VFI || !VFI.list) return [];
      // fields as authored in the admin: flag, title, sub, date
      return (VFI.list("ppUpdates") || []).map(function (u) {
        return {
          title: [u.flag, u.title].filter(Boolean).join(" "),
          body: u.sub || "",
          created_at: u.date || "",
          read: true,
          isUpdate: true
        };
      });
    }

    function paint(res) {
      var rows = res.data || [];
      // newest-looking first: agency notifications, then broadcast updates
      var merged = collapse(rows.concat(updateItems()));

      if (list) {
        list.innerHTML = merged.map(item).join("");
        swap(list, empty, merged.length > 0);
      }
      if (pop) {
        var body = pop.querySelector(".pp-pop__body") || pop;
        body.innerHTML = merged.length
          ? merged.slice(0, 6).map(item).join("")
          : '<p class="pp-pop__empty">No notifications found</p>';
      }
      var bell = $("#ppBell");
      if (bell) bell.setAttribute("data-unread", String(res.unread_count || 0));
    }

    function load() {
      if (!list && !pop) return;
      mountStyles();
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
