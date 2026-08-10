/* =====================================================================
   VFI — student portal behaviour
   Powers student-profile.html and student-tracking.html.

   LIVE (Phase 5): all data is self-scoped and comes from the Laravel backend
   through window.VFIApi (same-origin cookie session + CSRF). The pages fetch on
   load and redirect on 401 — they render nothing until authenticated. Documents
   are uploaded as real bytes to private, virus-scanned storage; profile data is
   NEVER written to localStorage. Endpoints are implicit-self (no id in any URL).

   ES5 only: var / function / string concat, to match the rest of js/.
   ===================================================================== */
(function () {
  "use strict";

  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  };

  var STORE_KEY = "vfi_student_profile";

  /* =================================================================
     1. Character rules
     Each rule is a pure "clean this string" function. The same function
     drives the input handler, the keypress guard and the paste handler,
     so the three can never disagree.
     ================================================================= */
  var FILTERS = {
    /* phone, post code — digits and nothing else */
    digits: function (s) { return String(s).replace(/[^0-9]/g, ""); },
    /* four-digit years */
    year: function (s) { return String(s).replace(/[^0-9]/g, "").slice(0, 4); },
    /* people and place names: letters, space, hyphen, apostrophe, period */
    name: function (s) { return String(s).replace(/[^A-Za-z '\-.]/g, ""); },
    /* test scores: digits with at most one decimal point (IELTS 7.5, GRE 318) */
    score: function (s) {
      var t = String(s).replace(/[^0-9.]/g, "");
      var dot = t.indexOf(".");
      if (dot !== -1) t = t.slice(0, dot + 1) + t.slice(dot + 1).replace(/\./g, "");
      return t.slice(0, 6);
    },
    /* free text that still refuses control characters and markup */
    text: function (s) { return String(s).replace(/[^A-Za-z0-9 ,.'&()\/\-]/g, ""); }
  };

  function filterOf(el) {
    if (!el || !el.getAttribute) return null;
    var key = el.getAttribute("data-sp-filter");
    return key && FILTERS[key] ? FILTERS[key] : null;
  }

  function caretOf(el) {
    var pos = null;
    try { pos = el.selectionStart; } catch (e) { pos = null; }
    return typeof pos === "number" ? pos : el.value.length;
  }

  function setCaret(el, pos) {
    try { el.setSelectionRange(pos, pos); } catch (e) { /* type doesn't support it */ }
  }

  /* Strip disallowed characters while keeping the caret where the user
     left it: the new caret is the length of the *cleaned* text that sat
     before the old caret. */
  function scrubInput(el) {
    var fn = filterOf(el);
    if (!fn) return;
    var raw = el.value;
    var clean = fn(raw);
    if (clean === raw) return;
    var caret = caretOf(el);
    var head = fn(raw.slice(0, caret)).length;
    el.value = clean;
    setCaret(el, head);
  }

  /* Would typing this character at the caret actually add anything? */
  function accepts(el, fn, ch) {
    var start = caretOf(el);
    var end = start;
    try { if (typeof el.selectionEnd === "number") end = el.selectionEnd; } catch (e) { end = start; }
    var without = el.value.slice(0, start) + el.value.slice(end);
    var withCh = el.value.slice(0, start) + ch + el.value.slice(end);
    return fn(withCh).length > fn(without).length;
  }

  document.addEventListener("input", function (e) {
    scrubInput(e.target);
  });

  document.addEventListener("keypress", function (e) {
    var el = e.target;
    var fn = filterOf(el);
    if (!fn) return;
    if (e.ctrlKey || e.metaKey || e.altKey) return;
    var ch = "";
    if (typeof e.key === "string" && e.key.length === 1) ch = e.key;
    else if (e.charCode) ch = String.fromCharCode(e.charCode);
    if (!ch) return;                       /* Enter, Tab, arrows … */
    if (!accepts(el, fn, ch)) e.preventDefault();
  });

  document.addEventListener("paste", function (e) {
    var el = e.target;
    var fn = filterOf(el);
    if (!fn) return;
    var cd = e.clipboardData || window.clipboardData;
    if (!cd) return;
    var txt = "";
    try { txt = cd.getData("text") || ""; } catch (err) { txt = ""; }
    e.preventDefault();
    var start = caretOf(el);
    var end = start;
    try { if (typeof el.selectionEnd === "number") end = el.selectionEnd; } catch (err2) { end = start; }
    var merged = el.value.slice(0, start) + txt + el.value.slice(end);
    el.value = fn(merged);
    setCaret(el, fn(merged.slice(0, start + txt.length)).length);
    clearError(el);
  });

  /* =================================================================
     2. Small helpers
     ================================================================= */
  function esc(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
  }

  function clone(o) { return JSON.parse(JSON.stringify(o)); }

  function isPlain(v) {
    return v && typeof v === "object" && Object.prototype.toString.call(v) !== "[object Array]";
  }

  function mergeInto(base, extra) {
    if (!extra || typeof extra !== "object") return base;
    Object.keys(extra).forEach(function (k) {
      if (isPlain(extra[k]) && isPlain(base[k])) mergeInto(base[k], extra[k]);
      else base[k] = extra[k];
    });
    return base;
  }

  function filled(v) { return v != null && String(v).trim() !== ""; }

  function icon(id, cls) {
    return '<svg class="' + (cls || "ic") + '" aria-hidden="true"><use href="#' + id + '"/></svg>';
  }

  /* pretty-print an ISO date without relying on the store */
  var MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
  function prettyDate(iso) {
    if (!filled(iso)) return "";
    var bits = String(iso).split("-");
    if (bits.length !== 3) return String(iso);
    var m = parseInt(bits[1], 10);
    if (isNaN(m) || m < 1 || m > 12) return String(iso);
    return bits[2] + " " + MONTHS[m - 1] + " " + bits[0];
  }

  /* =================================================================
     3. Storage — our own key, never the VFI content store's
     ================================================================= */
  /* Phase 5: profile data now comes from the API per session and is NEVER
     written to disk (clear-text profile on a shared machine was a real risk).
     These are kept as inert no-ops so any stray call is harmless. */
  function readSaved() { return null; }
  function writeSaved() { return false; }

  /* =================================================================
     4. Sample data (fictional student, no backend behind any of it)
     ================================================================= */
  var DOC_DEFS = [
    { id: "passport",   name: "Passport (bio page)",         icon: "i-passport", note: "Colour scan, valid for at least six months past your intake." },
    { id: "transcripts", name: "Academic transcripts",       icon: "i-cap",      note: "Every year of study plus the degree certificate." },
    { id: "sop",        name: "Statement of purpose",        icon: "i-doc",      note: "Around 800 to 1,000 words, tailored to each course." },
    { id: "lor",        name: "Letters of recommendation",   icon: "i-mail",     note: "Two academic referees, on institution letterhead." },
    { id: "financials", name: "Financial documents",         icon: "i-money",    note: "Six months of bank statements and the sponsor affidavit." },
    { id: "testreport", name: "Test report form",            icon: "i-award",    note: "The official score report, not the online preview." }
  ];

  /* Visa-processing pack — separate from the application checklist above.
     Same upload machinery; these are the papers the embassy/visa file needs. */
  var VISA_DEFS = [
    { id: "offer",    name: "Offer / CAS / I-20 letter",      icon: "i-award",    note: "The confirmation your university issues once you accept your place." },
    { id: "visaform", name: "Visa application form",          icon: "i-doc",      note: "Your completed online form (DS-160, UKVI, IRCC…) saved as a PDF." },
    { id: "visafee",  name: "Visa fee & surcharge receipt",   icon: "i-money",    note: "Proof you paid the application fee and any health surcharge." },
    { id: "finproof", name: "Proof of funds",                 icon: "i-money",    note: "Bank statements or a loan sanction letter covering tuition and living costs." },
    { id: "photo",    name: "Passport-size photograph",       icon: "i-passport", note: "A recent photo that meets the embassy's size and background rules." },
    { id: "medical",  name: "Medical / police clearance",     icon: "i-check-c",  note: "Only where your destination asks for a medical certificate or police clearance." }
  ];

  var DOC_STATUS = {
    missing:  { label: "Missing",  chip: "sp-chip",       icon: "i-clock" },
    uploaded: { label: "Uploaded", chip: "sp-chip--info", icon: "i-doc" },
    verified: { label: "Verified", chip: "sp-chip--ok",   icon: "i-check-c" }
  };

  var SEED = {
    student: { name: "Ayesha Rahman", sid: "VFI-2026-04871", initials: "AR" },
    personal: {
      first: "Ayesha",
      last: "Rahman",
      dob: "2002-04-17",
      nationality: "Bangladeshi",
      cc: "+880",
      phone: "1719450382",
      email: "ayesha.rahman@example.com"
    },
    address: {
      line1: "House 42, Road 11, Block C",
      line2: "Banani",
      city: "Dhaka",
      district: "",
      postcode: "1213",
      country: "Bangladesh"
    },
    academic: [
      { qualification: "BSc in Computer Science and Engineering", institution: "Dhaka City University", year: "2025", grade: "CGPA 3.71 of 4.00" },
      { qualification: "Higher Secondary Certificate (Science)",  institution: "Banani Model College",  year: "2020", grade: "GPA 5.00 of 5.00" }
    ],
    tests: [
      { test: "IELTS Academic", score: "7.5", date: "2026-01-24" },
      { test: "GRE General",    score: "318", date: "" }
    ],
    prefs: {
      countries: ["United Kingdom", "Ireland", "Canada"],
      intake: "September 2026",
      budget: "",
      field: "Computing & Data"
    },
    documents: {
      passport:   { status: "verified", file: "passport-bio-page.pdf" },
      transcripts: { status: "verified", file: "bsc-transcripts-2025.pdf" },
      sop:        { status: "uploaded", file: "statement-of-purpose-v3.docx" },
      lor:        { status: "missing",  file: "" },
      financials: { status: "missing",  file: "" },
      testreport: { status: "missing",  file: "" }
    },
    visaDocuments: {
      offer:    { status: "verified", file: "cas-university-of-glasgow.pdf" },
      visaform: { status: "uploaded", file: "ukvi-application-form.pdf" },
      visafee:  { status: "missing",  file: "" },
      finproof: { status: "uploaded", file: "bank-statement-6-months.pdf" },
      photo:    { status: "missing",  file: "" },
      medical:  { status: "missing",  file: "" }
    }
  };

  /* =================================================================
     5. Toast + inline "Saved"
     ================================================================= */
  var toastTimer = null;
  function toast(msg) {
    var box = $("#spToast");
    var txt = $("#spToastText");
    if (!box || !txt) return;
    txt.textContent = msg;
    box.classList.add("is-on");
    if (toastTimer) window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(function () { box.classList.remove("is-on"); }, 3200);
  }

  var savedTimers = {};
  function flashSaved(form, msg) {
    var tag = $("[data-sp-saved]", form);
    if (!tag) return;
    var key = form.getAttribute("data-sp-form");
    var txt = $("[data-sp-savedtext]", tag);
    if (txt) txt.textContent = msg || "Saved";
    tag.classList.add("is-on");
    if (savedTimers[key]) window.clearTimeout(savedTimers[key]);
    savedTimers[key] = window.setTimeout(function () { tag.classList.remove("is-on"); }, 3200);
  }

  /* =================================================================
     6. Errors
     ================================================================= */
  function errorBoxFor(el) {
    if (!el || !el.closest) return null;
    var wrap = el.closest(".sp-field");
    return wrap ? $(".sp-err", wrap) : null;
  }

  function showError(el, msg) {
    el.setAttribute("aria-invalid", "true");
    var box = errorBoxFor(el);
    if (box) {
      var span = box.getElementsByTagName("span")[0];
      if (span) span.textContent = msg;
      box.classList.add("is-on");
    }
  }

  function clearError(el) {
    if (!el || !el.removeAttribute) return;
    el.removeAttribute("aria-invalid");
    var box = errorBoxFor(el);
    if (box) box.classList.remove("is-on");
  }

  document.addEventListener("input", function (e) {
    if (e.target && e.target.getAttribute && e.target.getAttribute("aria-invalid") === "true") clearError(e.target);
  });
  document.addEventListener("change", function (e) {
    if (e.target && e.target.getAttribute && e.target.getAttribute("aria-invalid") === "true") clearError(e.target);
  });

  /* =================================================================
     7. PROFILE PAGE
     ================================================================= */
  function initProfile(BOOT) {
    /* State comes straight from GET /api/me/profile (already shaped to match). */
    var state = {
      student: {
        name: BOOT.student.name,
        sid: BOOT.student.student_ref,
        initials: BOOT.student.initials
      },
      personal: BOOT.personal || {},
      address: BOOT.address || {},
      academic: BOOT.academic || [],
      tests: BOOT.tests || [],
      prefs: BOOT.prefs || { countries: [] },
      documents: BOOT.documents || {},
      visaDocuments: BOOT.visaDocuments || {}
    };
    if (Object.prototype.toString.call(state.prefs.countries) !== "[object Array]") state.prefs.countries = [];
    /* per-section optimistic-concurrency tokens (sent back on save) */
    var versions = BOOT.versions || {};

    var docDraft = clone(state.documents);
    var visaDraft = clone(state.visaDocuments);

    /* ---------- identity band ---------- */
    function paintIdentity() {
      var first = state.personal.first || "";
      var last = state.personal.last || "";
      var full = (first + " " + last).replace(/\s+/g, " ").trim() || state.student.name;
      var initials = ((first.charAt(0) || "") + (last.charAt(0) || "")).toUpperCase() || state.student.initials;
      $("#spName").textContent = full;
      $("#spAvatar").textContent = initials;
      $("#spSid").textContent = state.student.sid;
    }

    /* ---------- completeness ---------- */
    function completeness() {
      var done = 0, total = 0;
      function score(ok) { total++; if (ok) done++; }

      ["first", "last", "dob", "nationality", "phone", "email"].forEach(function (k) {
        score(filled(state.personal[k]));
      });
      ["line1", "city", "district", "postcode", "country"].forEach(function (k) {
        score(filled(state.address[k]));
      });

      var acDone = 0;
      state.academic.forEach(function (r) {
        if (filled(r.qualification) && filled(r.institution) && filled(r.year) && filled(r.grade)) acDone++;
      });
      for (var i = 0; i < 3; i++) score(i < acDone);

      var tDone = 0;
      state.tests.forEach(function (r) {
        if (filled(r.test) && filled(r.score) && filled(r.date)) tDone++;
      });
      for (var j = 0; j < 2; j++) score(j < tDone);

      score(state.prefs.countries && state.prefs.countries.length > 0);
      score(filled(state.prefs.intake));
      score(filled(state.prefs.budget));
      score(filled(state.prefs.field));

      DOC_DEFS.forEach(function (d) {
        var rec = state.documents[d.id] || {};
        score(rec.status === "uploaded" || rec.status === "verified");
      });

      return { pct: total ? Math.round((done / total) * 100) : 0, done: done, total: total };
    }

    function paintMeter() {
      var c = completeness();
      var fill = $("#spMeterFill");
      var meter = $("#spMeter");
      $("#spPctLabel").textContent = "Profile " + c.pct + "% complete";
      fill.style.width = c.pct + "%";
      fill.className = "sp-track__fill" + (c.pct >= 85 ? " sp-track__fill--good" : (c.pct < 55 ? " sp-track__fill--warn" : ""));
      meter.setAttribute("aria-valuenow", String(c.pct));
      meter.setAttribute("aria-valuetext", c.pct + " percent complete, " + c.done + " of " + c.total + " items done");
      $("#spPctHint").textContent = c.pct >= 100
        ? "Everything we need is on file — nice work."
        : (c.total - c.done) + " item" + ((c.total - c.done) === 1 ? "" : "s") + " left before your file is application-ready.";
    }

    /* ---------- personal + address (static markup) ---------- */
    function paintPersonal() {
      var p = state.personal;
      $("#spFirst").value = p.first || "";
      $("#spLast").value = p.last || "";
      $("#spDob").value = p.dob || "";
      $("#spNat").value = p.nationality || "";
      $("#spCc").value = p.cc || "+880";
      $("#spPhone").value = p.phone || "";
      $("#spEmail").value = p.email || "";
      ["#spFirst", "#spLast", "#spPhone", "#spEmail"].forEach(function (s) { clearError($(s)); });
    }

    function paintAddress() {
      var a = state.address;
      $("#spAddr1").value = a.line1 || "";
      $("#spAddr2").value = a.line2 || "";
      $("#spCity").value = a.city || "";
      $("#spDistrict").value = a.district || "";
      $("#spPost").value = a.postcode || "";
      $("#spCountry").value = a.country || "";
    }

    /* ---------- academic rows ---------- */
    function academicRow(row, i) {
      var n = i + 1;
      return '' +
      '<div class="sp-row" data-sp-row="academic">' +
        '<div class="sp-row__head">' +
          '<span class="sp-row__n">Qualification ' + n + '</span>' +
          '<button class="sp-iconbtn" type="button" data-sp-del="academic" data-sp-i="' + i + '">' +
            icon("i-x", "ic ic--sm") + ' Remove' +
          '</button>' +
        '</div>' +
        '<div class="sp-grid">' +
          '<div class="sp-field sp-span2">' +
            '<label for="spAcQ' + i + '">Qualification</label>' +
            '<input class="sp-input" id="spAcQ' + i + '" type="text" maxlength="70" data-sp-filter="text" data-sp-key="qualification" value="' + esc(row.qualification) + '" />' +
          '</div>' +
          '<div class="sp-field sp-span2">' +
            '<label for="spAcI' + i + '">Institution</label>' +
            '<input class="sp-input" id="spAcI' + i + '" type="text" maxlength="70" data-sp-filter="text" data-sp-key="institution" value="' + esc(row.institution) + '" />' +
          '</div>' +
          '<div class="sp-field">' +
            '<label for="spAcY' + i + '">Year completed</label>' +
            '<input class="sp-input" id="spAcY' + i + '" type="text" inputmode="numeric" maxlength="4" data-sp-filter="year" data-sp-key="year" value="' + esc(row.year) + '" aria-describedby="spAcYh' + i + ' spAcYe' + i + '" />' +
            '<p class="sp-hint" id="spAcYh' + i + '">Four digits, e.g. 2025.</p>' +
            '<p class="sp-err" id="spAcYe' + i + '">' + icon("i-x", "ic ic--sm") + '<span></span></p>' +
          '</div>' +
          '<div class="sp-field">' +
            '<label for="spAcG' + i + '">Grade or result</label>' +
            '<input class="sp-input" id="spAcG' + i + '" type="text" maxlength="30" data-sp-filter="text" data-sp-key="grade" value="' + esc(row.grade) + '" />' +
          '</div>' +
        '</div>' +
      '</div>';
    }

    function paintAcademic(rows) {
      var list = rows || state.academic;
      var box = $("#spAcademicRows");
      if (!list.length) {
        box.innerHTML = '<p class="sp-none">No qualifications listed yet. Add your most recent one first.</p>';
        return;
      }
      box.innerHTML = list.map(academicRow).join("");
    }

    function readAcademic() {
      return $$('[data-sp-row="academic"]', $("#spAcademicRows")).map(function (row) {
        var out = {};
        $$("[data-sp-key]", row).forEach(function (el) { out[el.getAttribute("data-sp-key")] = el.value.trim(); });
        return out;
      });
    }

    /* ---------- test rows ---------- */
    var TEST_NAMES = ["IELTS Academic", "IELTS General Training", "TOEFL iBT", "PTE Academic", "Duolingo English Test", "GRE General", "GMAT Focus"];

    function testRow(row, i) {
      var opts = '<option value="">Select a test</option>';
      TEST_NAMES.forEach(function (t) {
        opts += '<option' + (t === row.test ? ' selected' : '') + '>' + esc(t) + '</option>';
      });
      return '' +
      '<div class="sp-row" data-sp-row="tests">' +
        '<div class="sp-row__head">' +
          '<span class="sp-row__n">Test ' + (i + 1) + '</span>' +
          '<button class="sp-iconbtn" type="button" data-sp-del="tests" data-sp-i="' + i + '">' +
            icon("i-x", "ic ic--sm") + ' Remove' +
          '</button>' +
        '</div>' +
        '<div class="sp-grid sp-grid--3">' +
          '<div class="sp-field">' +
            '<label for="spTsT' + i + '">Test</label>' +
            '<select class="sp-select" id="spTsT' + i + '" data-sp-key="test">' + opts + '</select>' +
          '</div>' +
          '<div class="sp-field">' +
            '<label for="spTsS' + i + '">Overall score</label>' +
            '<input class="sp-input" id="spTsS' + i + '" type="text" inputmode="decimal" maxlength="6" data-sp-filter="score" data-sp-key="score" value="' + esc(row.score) + '" aria-describedby="spTsSh' + i + ' spTsSe' + i + '" />' +
            '<p class="sp-hint" id="spTsSh' + i + '">Numbers only, one decimal point allowed.</p>' +
            '<p class="sp-err" id="spTsSe' + i + '">' + icon("i-x", "ic ic--sm") + '<span></span></p>' +
          '</div>' +
          '<div class="sp-field">' +
            '<label for="spTsD' + i + '">Date taken</label>' +
            '<input class="sp-input" id="spTsD' + i + '" type="date" data-sp-key="date" value="' + esc(row.date) + '" />' +
          '</div>' +
        '</div>' +
      '</div>';
    }

    function paintTests(rows) {
      var list = rows || state.tests;
      var box = $("#spTestRows");
      if (!list.length) {
        box.innerHTML = '<p class="sp-none">No test scores yet. Add one as soon as your result is released.</p>';
        return;
      }
      box.innerHTML = list.map(testRow).join("");
    }

    function readTests() {
      return $$('[data-sp-row="tests"]', $("#spTestRows")).map(function (row) {
        var out = {};
        $$("[data-sp-key]", row).forEach(function (el) { out[el.getAttribute("data-sp-key")] = el.value.trim(); });
        return out;
      });
    }

    /* ---------- preferences ---------- */
    function paintPrefs() {
      var p = state.prefs;
      $$('#spCountries input[type="checkbox"]').forEach(function (cb) {
        cb.checked = p.countries.indexOf(cb.value) !== -1;
        cb.parentNode.classList.toggle("is-on", cb.checked);
      });
      $("#spIntake").value = p.intake || "";
      $("#spBudget").value = p.budget || "";
      $("#spFieldStudy").value = p.field || "";
    }

    var countriesBox = $("#spCountries");
    if (countriesBox) {
      countriesBox.addEventListener("change", function (e) {
        if (e.target && e.target.type === "checkbox") e.target.parentNode.classList.toggle("is-on", e.target.checked);
      });
    }

    /* ---------- documents (shared by the application pack + the visa pack) ---------- */
    function docItem(def, draft) {
      var rec = draft[def.id] || { status: "missing", file: "" };
      var meta = DOC_STATUS[rec.status] || DOC_STATUS.missing;
      var fileLine = filled(rec.file)
        ? '<br /><b>On file:</b> ' + esc(rec.file)
        : '<br /><b>On file:</b> nothing yet';
      return '' +
      '<div class="sp-doc" data-sp-doc-item="' + def.id + '">' +
        '<span class="sp-doc__ic" aria-hidden="true">' + icon(def.icon) + '</span>' +
        '<div>' +
          '<span class="sp-doc__name">' + esc(def.name) + '</span>' +
          '<p class="sp-doc__note">' + esc(def.note) + fileLine + '</p>' +
        '</div>' +
        '<span class="sp-chip ' + meta.chip + '">' + icon(meta.icon, "ic ic--sm") + ' ' + meta.label + '</span>' +
        '<div class="sp-doc__file">' +
          '<label for="spDoc-' + def.id + '">Attach ' + esc(def.name.toLowerCase()) + '</label>' +
          '<div class="sp-filerow">' +
            '<input class="sp-file" type="file" id="spDoc-' + def.id + '" data-sp-doc="' + def.id + '" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" />' +
            (filled(rec.file)
              ? '<button class="sp-iconbtn" type="button" data-sp-docdl="' + def.id + '">' + icon("i-doc", "ic ic--sm") + ' Download</button>' +
                (rec.status === "verified"
                  ? ''
                  : '<button class="sp-iconbtn" type="button" data-sp-docclear="' + def.id + '">' + icon("i-x", "ic ic--sm") + ' Clear</button>')
              : '') +
          '</div>' +
        '</div>' +
      '</div>';
    }

    /* one renderer drives both packs; `g` names the defs, draft and DOM ids */
    function paintPack(g) {
      $("#" + g.listId).innerHTML = g.defs.map(function (d) { return docItem(d, g.draft()); }).join("");
      var draft = g.draft(), ready = 0;
      g.defs.forEach(function (d) {
        var rec = draft[d.id] || {};
        if (rec.status === "uploaded" || rec.status === "verified") ready++;
      });
      var tag = $("#" + g.countId);
      tag.textContent = ready + " of " + g.defs.length + " ready";
      tag.className = "sp-chip " + (ready === g.defs.length ? "sp-chip--ok" : (ready === 0 ? "sp-chip--bad" : "sp-chip--info"));
      $("#" + g.subId).textContent = ready === g.defs.length ? g.doneMsg
        : (g.defs.length - ready) + " of " + g.defs.length + " still to " + g.verb + ".";
    }

    /* draft() getters, because a Cancel reassigns the draft variable */
    var PACK_DOCS = { defs: DOC_DEFS, listId: "spDocList", countId: "spDocsCount", subId: "spDocsSub", verb: "come",
      doneMsg: "Your pack is complete — your counsellor will verify anything still marked Uploaded.",
      draft: function () { return docDraft; }, set: function (v) { docDraft = v; } };
    var PACK_VISA = { defs: VISA_DEFS, listId: "spVisaList", countId: "spVisaCount", subId: "spVisaSub", verb: "attach",
      doneMsg: "Every visa document is attached — your counsellor will review them before your appointment.",
      draft: function () { return visaDraft; }, set: function (v) { visaDraft = v; } };

    function paintDocs() { paintPack(PACK_DOCS); }
    function paintVisa() { paintPack(PACK_VISA); }

    /* Convert the server checklist arrays back into the {key:{status,file}} maps
       the UI renders from, then repaint both packs + the meter. */
    function mapChecklist(arr) {
      var m = {};
      (arr || []).forEach(function (it) {
        m[it.key] = { status: it.status, file: it.file ? it.file.name : "" };
      });
      return m;
    }
    function applyChecklist(fresh) {
      state.documents = mapChecklist(fresh.application);
      state.visaDocuments = mapChecklist(fresh.visa);
      docDraft = clone(state.documents);
      visaDraft = clone(state.visaDocuments);
      paintDocs();
      paintVisa();
      paintMeter();   /* application docs feed the completeness meter */
    }

    function wirePack(g) {
      var list = $("#" + g.listId);
      if (!list) return;

      /* real multipart upload — the file IS the commit (docs §3.1–3.5) */
      list.addEventListener("change", function (e) {
        var el = e.target;
        if (!el || !el.getAttribute) return;
        var id = el.getAttribute("data-sp-doc");
        if (!id) return;
        var f = el.files && el.files[0];
        if (!f) return;

        var fd = new FormData();
        fd.append("file", f);
        el.disabled = true;
        toast("Uploading " + f.name + "…");

        window.VFIApi.post("/api/me/documents/" + encodeURIComponent(id), fd, {}).then(function (fresh) {
          applyChecklist(fresh);
          toast(f.name + " uploaded and scanned.");
        }).catch(function (err) {
          el.disabled = false; el.value = "";
          var msg = (err && err.body && err.body.message) ||
            (err && err.status === 403 ? "Please verify your email address before uploading." :
             "We couldn't upload that file. Please try again.");
          if (!err || err.status !== 401) toast(msg);
        });
      });

      list.addEventListener("click", function (e) {
        var t = e.target && e.target.closest ? e.target : null;
        if (!t) return;

        var clr = t.closest("[data-sp-docclear]");
        if (clr) {
          var cid = clr.getAttribute("data-sp-docclear");
          window.VFIApi.del("/api/me/documents/" + encodeURIComponent(cid), {}).then(function (fresh) {
            applyChecklist(fresh);
            toast("Removed.");
          }).catch(function (err) {
            if (!err || err.status !== 401) toast((err && err.body && err.body.message) || "We couldn't remove that. Please try again.");
          });
          return;
        }

        var dl = t.closest("[data-sp-docdl]");
        if (dl) {
          var did = dl.getAttribute("data-sp-docdl");
          window.VFIApi.get("/api/me/documents/" + encodeURIComponent(did) + "/download", {}).then(function (data) {
            var a = document.createElement("a");
            a.href = data.url; a.rel = "noopener";
            document.body.appendChild(a); a.click(); a.remove();
          }).catch(function (err) {
            if (!err || err.status !== 401) toast("That document isn't available to download right now.");
          });
        }
      });
    }
    wirePack(PACK_DOCS);
    wirePack(PACK_VISA);

    /* ---------- add / remove repeatable rows ---------- */
    $("#spAddAcademic").addEventListener("click", function () {
      var rows = readAcademic();
      rows.push({ qualification: "", institution: "", year: "", grade: "" });
      paintAcademic(rows);
      var target = $$('[data-sp-row="academic"]', $("#spAcademicRows")).pop();
      if (target) { var inp = $("input", target); if (inp) inp.focus(); }
    });

    $("#spAddTest").addEventListener("click", function () {
      var rows = readTests();
      rows.push({ test: "", score: "", date: "" });
      paintTests(rows);
      var target = $$('[data-sp-row="tests"]', $("#spTestRows")).pop();
      if (target) { var sel = $("select", target); if (sel) sel.focus(); }
    });

    document.addEventListener("click", function (e) {
      var btn = e.target && e.target.closest ? e.target.closest("[data-sp-del]") : null;
      if (!btn) return;
      var kind = btn.getAttribute("data-sp-del");
      var i = parseInt(btn.getAttribute("data-sp-i"), 10);
      if (kind === "academic") {
        var rows = readAcademic();
        rows.splice(i, 1);
        paintAcademic(rows);
        var add = $("#spAddAcademic"); if (add) add.focus();
      } else if (kind === "tests") {
        var trows = readTests();
        trows.splice(i, 1);
        paintTests(trows);
        var addT = $("#spAddTest"); if (addT) addT.focus();
      }
    });

    /* ---------- validation ---------- */
    var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

    function validatePersonal() {
      var bad = null;
      [["#spFirst", "First name"], ["#spLast", "Family name"]].forEach(function (pair) {
        var el = $(pair[0]);
        if (!filled(el.value)) { showError(el, "Enter your " + pair[1].toLowerCase() + "."); bad = bad || el; }
      });
      var phone = $("#spPhone");
      if (!filled(phone.value)) { showError(phone, "Enter your mobile number."); bad = bad || phone; }
      else if (phone.value.length < 6) { showError(phone, "That looks too short — at least 6 digits."); bad = bad || phone; }
      var mail = $("#spEmail");
      if (!filled(mail.value)) { showError(mail, "Enter your email address."); bad = bad || mail; }
      else if (!EMAIL_RE.test(mail.value.trim())) { showError(mail, "Use the form name@example.com."); bad = bad || mail; }
      return bad;
    }

    function validateAcademic() {
      var bad = null;
      $$('[data-sp-row="academic"]', $("#spAcademicRows")).forEach(function (row) {
        var y = $('[data-sp-key="year"]', row);
        if (filled(y.value) && y.value.length !== 4) { showError(y, "Use a four-digit year."); bad = bad || y; }
      });
      return bad;
    }

    function validateTests() {
      var bad = null;
      $$('[data-sp-row="tests"]', $("#spTestRows")).forEach(function (row) {
        var t = $('[data-sp-key="test"]', row);
        var s = $('[data-sp-key="score"]', row);
        if (filled(t.value) && !filled(s.value)) { showError(s, "Add the overall score for this test."); bad = bad || s; }
      });
      return bad;
    }

    /* ---------- save (per-section PUT to the API) ---------- */

    /* Adopt a fresh aggregate from the server (authoritative) without clobbering
       unsaved edits in the OTHER cards: update state + versions, repaint the
       global meter/identity + doc packs, and only repaint an input section when
       explicitly asked (used on a 409 conflict). */
    function applyAggregate(p, repaintKey) {
      state.student = { name: p.student.name, sid: p.student.student_ref, initials: p.student.initials };
      state.personal = p.personal || state.personal;
      state.address = p.address || state.address;
      state.academic = p.academic || state.academic;
      state.tests = p.tests || state.tests;
      state.prefs = p.prefs || state.prefs;
      state.documents = p.documents || state.documents;
      state.visaDocuments = p.visaDocuments || state.visaDocuments;
      versions = p.versions || versions;
      docDraft = clone(state.documents);
      visaDraft = clone(state.visaDocuments);
      paintIdentity();
      paintMeter();
      paintDocs();
      paintVisa();
      if (repaintKey) repaintSection(repaintKey);
    }

    function repaintSection(key) {
      if (key === "personal") paintPersonal();
      else if (key === "address") paintAddress();
      else if (key === "academic") paintAcademic();
      else if (key === "tests") paintTests();
      else if (key === "prefs") paintPrefs();
    }

    /* Map a card key → {endpoint, body}. Documents commit at upload time, so
       their "Save" button degrades to a confirmation (docs §3.5). */
    function sectionRequest(key) {
      if (key === "personal") {
        var pn = state.personal;
        return { ep: "/api/me/profile/personal", body: {
          version: versions.personal, first: pn.first, middle: pn.middle || "", last: pn.last,
          dob: pn.dob, nationality: pn.nationality, cc: pn.cc, phone: pn.phone, email: pn.email } };
      }
      if (key === "address") {
        var ad = state.address;
        return { ep: "/api/me/profile/address", body: {
          version: versions.address, line1: ad.line1, line2: ad.line2, city: ad.city,
          district: ad.district, postcode: ad.postcode, country: ad.country } };
      }
      if (key === "academic") return { ep: "/api/me/qualifications", body: { version: versions.academic, rows: state.academic } };
      if (key === "tests") return { ep: "/api/me/test_scores", body: { version: versions.tests, rows: state.tests } };
      if (key === "prefs") return { ep: "/api/me/preferences", body: {
        version: versions.prefs, countries: state.prefs.countries, intake: state.prefs.intake,
        budget: state.prefs.budget, field: state.prefs.field } };
      return null;
    }

    function persist(form, message) {
      var key = form.getAttribute("data-sp-form");

      /* the checklist commits on upload; Save is just a confirmation now */
      if (key === "documents" || key === "visadocs") {
        paintMeter();
        flashSaved(form, "Saved");
        toast("Your document checklist is up to date.");
        return;
      }

      var req = sectionRequest(key);
      if (!req) return;

      window.VFIApi.put(req.ep, req.body, {}).then(function (fresh) {
        applyAggregate(fresh);
        flashSaved(form, "Saved");
        toast(message);
      }).catch(function (err) {
        if (err && err.status === 409 && err.body) {
          applyAggregate(err.body, key);   // adopt latest + repaint the conflicted card
          toast("This section was updated on another device — the latest is shown. Review and save again.");
        } else if (err && err.status === 422) {
          toast("Some details need another look — please check them and try again.");
        } else if (!err || err.status !== 401) {   // 401 → api.js already redirected
          toast("We couldn't save that just now. Please try again in a moment.");
        }
      });
    }

    var SAVERS = {
      personal: function (form) {
        var bad = validatePersonal();
        if (bad) { bad.focus(); toast("Check the highlighted fields and try again."); return; }
        state.personal = {
          first: $("#spFirst").value.trim(),
          last: $("#spLast").value.trim(),
          dob: $("#spDob").value,
          nationality: $("#spNat").value,
          cc: $("#spCc").value,
          phone: $("#spPhone").value.trim(),
          email: $("#spEmail").value.trim()
        };
        persist(form, "Personal details saved.");
      },
      address: function (form) {
        state.address = {
          line1: $("#spAddr1").value.trim(),
          line2: $("#spAddr2").value.trim(),
          city: $("#spCity").value.trim(),
          district: $("#spDistrict").value.trim(),
          postcode: $("#spPost").value.trim(),
          country: $("#spCountry").value
        };
        persist(form, "Address saved.");
      },
      academic: function (form) {
        var bad = validateAcademic();
        if (bad) { bad.focus(); toast("Check the highlighted year and try again."); return; }
        state.academic = readAcademic().filter(function (r) {
          return filled(r.qualification) || filled(r.institution) || filled(r.year) || filled(r.grade);
        });
        paintAcademic();
        persist(form, "Academic background saved.");
      },
      tests: function (form) {
        var bad = validateTests();
        if (bad) { bad.focus(); toast("Check the highlighted score and try again."); return; }
        state.tests = readTests().filter(function (r) {
          return filled(r.test) || filled(r.score) || filled(r.date);
        });
        paintTests();
        persist(form, "Test scores saved.");
      },
      prefs: function (form) {
        var picked = [];
        $$('#spCountries input[type="checkbox"]').forEach(function (cb) { if (cb.checked) picked.push(cb.value); });
        state.prefs = {
          countries: picked,
          intake: $("#spIntake").value,
          budget: $("#spBudget").value,
          field: $("#spFieldStudy").value
        };
        persist(form, "Study preferences saved.");
      },
      documents: function (form) {
        state.documents = clone(docDraft);
        paintDocs();
        persist(form, "Document checklist saved.");
      },
      visadocs: function (form) {
        state.visaDocuments = clone(visaDraft);
        paintVisa();
        persist(form, "Visa documents saved.");
      }
    };

    var RESETTERS = {
      personal: paintPersonal,
      address: paintAddress,
      academic: function () { paintAcademic(); },
      tests: function () { paintTests(); },
      prefs: paintPrefs,
      documents: function () { docDraft = clone(state.documents); paintDocs(); },
      visadocs: function () { visaDraft = clone(state.visaDocuments); paintVisa(); }
    };

    $$("[data-sp-form]").forEach(function (form) {
      var key = form.getAttribute("data-sp-form");
      form.addEventListener("submit", function (e) {
        e.preventDefault();
        if (SAVERS[key]) SAVERS[key](form);
      });
      var cancel = $("[data-sp-cancel]", form);
      if (cancel) {
        cancel.addEventListener("click", function () {
          $$("[aria-invalid]", form).forEach(clearError);
          if (RESETTERS[key]) RESETTERS[key]();
          toast("Changes discarded — the last saved version is back.");
        });
      }
    });

    /* intake options are served by the backend so they never go stale (§1.6) */
    (function fillIntakeOptions() {
      var sel = $("#spIntake");
      if (!sel) return;
      var opts = BOOT.intakeOptions || [];
      var current = state.prefs.intake || "";
      var html = '<option value="">Choose an intake…</option>';
      var seen = false;
      opts.forEach(function (o) { html += '<option value="' + esc(o) + '">' + esc(o) + "</option>"; if (o === current) seen = true; });
      if (current && !seen) html += '<option value="' + esc(current) + '">' + esc(current) + "</option>";
      sel.innerHTML = html;
    })();

    /* ---------- first paint ---------- */
    paintIdentity();
    paintPersonal();
    paintAddress();
    paintAcademic();
    paintTests();
    paintPrefs();
    paintDocs();
    paintVisa();
    paintMeter();
  }

  /* =================================================================
     8. TRACKING PAGE
     ================================================================= */
  var STAGES = [
    { name: "Counselling",      state: "done", when: "12 Feb 2026" },
    { name: "Documents",        state: "done", when: "03 Mar 2026" },
    { name: "Application Sent", state: "done", when: "21 Mar 2026" },
    { name: "Offer Received",   state: "now",  when: "Since 22 Apr 2026" },
    { name: "Visa",             state: "todo", when: "Expected Jul 2026" },
    { name: "Departure",        state: "todo", when: "Expected Sep 2026" }
  ];

  var STAGE_STATE = {
    done: { label: "Completed",   cls: "is-done" },
    now:  { label: "In progress", cls: "is-now" },
    todo: { label: "Not started", cls: "" }
  };

  var APP_STATUS = {
    submitted:   { label: "Submitted",         chip: "sp-chip",       icon: "i-doc" },
    review:      { label: "Under Review",      chip: "sp-chip--info", icon: "i-search" },
    offer:       { label: "Offer",             chip: "sp-chip--ok",   icon: "i-check-c" },
    conditional: { label: "Conditional Offer", chip: "sp-chip--part", icon: "i-checks" },
    rejected:    { label: "Rejected",          chip: "sp-chip--bad",  icon: "i-x" },
    enrolled:    { label: "Enrolled",          chip: "sp-chip--ok",   icon: "i-cap" }
  };

  var FILTER_ORDER = ["all", "submitted", "review", "offer", "conditional", "rejected", "enrolled"];

  var APPS = [
    {
      uni: "University of Glasgow", place: "Glasgow, United Kingdom",
      course: "MSc Data Analytics", intake: "September 2026", sent: "21 Mar 2026",
      status: "offer", pct: 80, stage: "Offer issued, deposit pending",
      note: "Unconditional offer received on 22 April. Pay the tuition deposit by 30 June to hold your place."
    },
    {
      uni: "University of Birmingham", place: "Birmingham, United Kingdom",
      course: "MSc Computer Science", intake: "September 2026", sent: "21 Mar 2026",
      status: "conditional", pct: 65, stage: "One condition outstanding",
      note: "Offer is conditional on your final degree certificate reaching admissions before 15 July."
    },
    {
      uni: "Trinity College Dublin", place: "Dublin, Ireland",
      course: "MSc Computer Science (Intelligent Systems)", intake: "September 2026", sent: "26 Mar 2026",
      status: "review", pct: 45, stage: "With the academic panel",
      note: "Moved to academic review on 14 April. This programme usually answers within four to six weeks."
    },
    {
      uni: "Dublin City University", place: "Dublin, Ireland",
      course: "MSc Computing (Artificial Intelligence)", intake: "September 2026", sent: "02 Apr 2026",
      status: "submitted", pct: 25, stage: "Acknowledged by admissions",
      note: "Application received and logged. An assessor has not been assigned yet."
    },
    {
      uni: "Queen Mary University of London", place: "London, United Kingdom",
      course: "MSc Big Data Science", intake: "September 2026", sent: "24 Mar 2026",
      status: "submitted", pct: 25, stage: "Acknowledged by admissions",
      note: "Portal shows your file as complete. No further documents requested so far."
    },
    {
      uni: "University of Manchester", place: "Manchester, United Kingdom",
      course: "MSc Advanced Computer Science", intake: "September 2026", sent: "28 Mar 2026",
      status: "rejected", pct: 100, stage: "Closed for this cycle",
      note: "Not shortlisted. The programme reached capacity early; your counsellor suggested Glasgow as the closer match."
    }
  ];

  var EVENTS = [
    { date: "22 Apr 2026", tone: "ok",   icon: "i-check-c", title: "Unconditional offer from the University of Glasgow",
      text: "MSc Data Analytics, September 2026 intake. The offer letter is in your email and the deposit deadline is 30 June." },
    { date: "18 Apr 2026", tone: "part", icon: "i-checks",  title: "Conditional offer from the University of Birmingham",
      text: "One condition remains: the final degree certificate must reach admissions before 15 July." },
    { date: "14 Apr 2026", tone: "info", icon: "i-search",  title: "Trinity College Dublin moved your file to review",
      text: "Your application is now with the academic panel for the Intelligent Systems stream." },
    { date: "09 Apr 2026", tone: "wait", icon: "i-money",   title: "Financial documents requested",
      text: "Six months of bank statements and the sponsor affidavit are needed before any CAS can be issued." },
    { date: "05 Apr 2026", tone: "bad",  icon: "i-x",       title: "University of Manchester closed your application",
      text: "The programme filled early this cycle. Your counsellor has already suggested an alternative." },
    { date: "02 Apr 2026", tone: "info", icon: "i-doc",     title: "Application sent to Dublin City University",
      text: "MSc Computing (Artificial Intelligence). That completes the six universities on your shortlist." },
    { date: "21 Mar 2026", tone: "info", icon: "i-plane",   title: "First three applications submitted",
      text: "Glasgow, Birmingham and Queen Mary went out together with your statement of purpose." },
    { date: "03 Mar 2026", tone: "ok",   icon: "i-shield",  title: "Passport and transcripts verified",
      text: "Both documents passed the checking desk and are locked to your file." },
    { date: "12 Feb 2026", tone: "info", icon: "i-chat",    title: "Counselling session completed",
      text: "Shortlist agreed: six computing master's courses across the UK and Ireland for September 2026." }
  ];

  var TONE_CLASS = {
    ok: "sp-tl__ic--ok", info: "sp-tl__ic--info", wait: "sp-tl__ic--wait",
    part: "sp-tl__ic--part", bad: "sp-tl__ic--bad"
  };

  var TODOS = [
    { icon: "i-award", title: "Upload the official IELTS test report",
      text: "The scanned band-score form from the test centre, not the online preview.",
      due: "Overdue since 30 Apr 2026", late: true },
    { icon: "i-money", title: "Upload your financial documents",
      text: "Six months of bank statements plus the sponsor affidavit. Glasgow needs these before a CAS can be issued.",
      due: "Due 12 May 2026", late: false },
    { icon: "i-mail", title: "Add a second letter of recommendation",
      text: "One referee is already on file. Birmingham asks for two academic referees.",
      due: "Due 20 May 2026", late: false },
    { icon: "i-thumb", title: "Confirm your firm choice",
      text: "Tell your counsellor whether you are firming Glasgow or waiting for Trinity College Dublin.",
      due: "Due 30 Jun 2026", late: false },
    { icon: "i-passport", title: "Book your visa appointment",
      text: "Slots open once the deposit is paid and the sponsorship reference arrives.",
      due: "Opens after the deposit", late: false }
  ];

  function initTracking(T) {
    /* Live data from GET /api/me/tracking — same shapes the demo constants had,
       so the rendering below is unchanged. These locals shadow the constants. */
    var STAGES = (T && T.journey && T.journey.stages) || [];
    var APPS = (T && T.applications) || [];
    var EVENTS = (T && T.timeline) || [];
    var TODOS = (T && T.actions) || [];
    var SERVER_PCT = (T && T.journey && typeof T.journey.pct === "number") ? T.journey.pct : null;

    /* ---------- stepper ---------- */
    var doneCount = 0, nowCount = 0;
    STAGES.forEach(function (s) {
      if (s.state === "done") doneCount++;
      if (s.state === "now") nowCount++;
    });

    $("#spSteps").innerHTML = STAGES.map(function (s, i) {
      var meta = STAGE_STATE[s.state] || STAGE_STATE.todo;
      var dot = s.state === "done" ? icon("i-check", "ic ic--sm") : String(i + 1);
      return '' +
      '<li class="sp-step ' + meta.cls + '"' + (s.state === "now" ? ' aria-current="step"' : '') + '>' +
        '<span class="sp-step__dot" aria-hidden="true">' + dot + '</span>' +
        '<span class="sp-step__name">' + esc(s.name) + '</span>' +
        '<span class="sp-step__state">' + meta.label + '</span>' +
        '<span class="sp-step__date">' + esc(s.when) + '</span>' +
      '</li>';
    }).join("");

    var jpct = SERVER_PCT != null
      ? SERVER_PCT
      : (STAGES.length ? Math.round(((doneCount + (nowCount ? 0.5 : 0)) / STAGES.length) * 100) : 0);
    var jfill = $("#spJourneyFill");
    var jbar = $("#spJourney");
    jfill.style.width = jpct + "%";
    jbar.setAttribute("aria-valuenow", String(jpct));
    jbar.setAttribute("aria-valuetext", jpct + " percent — " + doneCount + " of " + STAGES.length + " stages complete");
    $("#spJourneyLabel").textContent = "Journey " + jpct + "% complete";
    $("#spJourneyHint").textContent = doneCount + " of " + STAGES.length + " stages done. Current stage: " +
      (STAGES.filter(function (s) { return s.state === "now"; })[0] || STAGES[0]).name + ".";

    /* ---------- applications + filter ---------- */
    var current = "all";

    function countFor(key) {
      if (key === "all") return APPS.length;
      return APPS.filter(function (a) { return a.status === key; }).length;
    }

    function appCard(a) {
      var meta = APP_STATUS[a.status] || APP_STATUS.submitted;
      return '' +
      '<li class="sp-app">' +
        '<div class="sp-app__top">' +
          '<div>' +
            '<h3 class="sp-app__uni">' + esc(a.uni) + '</h3>' +
            '<p class="sp-app__course">' + esc(a.course) + '</p>' +
          '</div>' +
          '<span class="sp-chip ' + meta.chip + '">' + icon(meta.icon, "ic ic--sm") + ' ' + meta.label + '</span>' +
        '</div>' +
        '<ul class="sp-app__meta">' +
          '<li>' + icon("i-pin", "ic") + esc(a.place) + '</li>' +
          '<li>' + icon("i-calendar", "ic") + 'Intake <b>' + esc(a.intake) + '</b></li>' +
          '<li>' + icon("i-clock", "ic") + 'Submitted <b>' + esc(a.sent) + '</b></li>' +
        '</ul>' +
        '<div>' +
          '<div class="sp-bar__top">' +
            '<span class="sp-bar__label" id="spBarL-' + esc(a.uni.replace(/[^A-Za-z]/g, "")) + '">' + esc(a.stage) + '</span>' +
            '<span class="sp-bar__pct">' + a.pct + '%</span>' +
          '</div>' +
          '<div class="sp-track sp-track--sm" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + a.pct +
            '" aria-valuetext="' + a.pct + ' percent — ' + esc(a.stage) + '" aria-labelledby="spBarL-' + esc(a.uni.replace(/[^A-Za-z]/g, "")) + '">' +
            '<span class="sp-track__fill' + (a.status === "offer" || a.status === "enrolled" ? " sp-track__fill--good" : (a.status === "rejected" ? " sp-track__fill--dim" : "")) +
            '" style="width:' + a.pct + '%"></span>' +
          '</div>' +
        '</div>' +
        '<p class="sp-app__note">' + esc(a.note) + '</p>' +
      '</li>';
    }

    function paintApps() {
      var shown = current === "all" ? APPS : APPS.filter(function (a) { return a.status === current; });
      $("#spApps").innerHTML = shown.map(appCard).join("");
      $("#spAppsNone").hidden = shown.length !== 0;
      var label = current === "all" ? "" : " with the status " + APP_STATUS[current].label;
      $("#spCount").textContent = shown.length === 0
        ? "No applications" + label + "."
        : "Showing " + shown.length + " of " + APPS.length + " application" + (APPS.length === 1 ? "" : "s") + label + ".";
      $$("#spFilters .sp-filter").forEach(function (b) {
        b.setAttribute("aria-pressed", String(b.getAttribute("data-sp-status") === current));
      });
    }

    $("#spFilters").innerHTML = FILTER_ORDER.map(function (key) {
      var label = key === "all" ? "All" : APP_STATUS[key].label;
      return '<button class="sp-filter" type="button" data-sp-status="' + key + '" aria-pressed="' + (key === "all") + '">' +
        esc(label) + ' <span class="sp-filter__n">(' + countFor(key) + ')</span></button>';
    }).join("");

    $("#spFilters").addEventListener("click", function (e) {
      var btn = e.target && e.target.closest ? e.target.closest("[data-sp-status]") : null;
      if (!btn) return;
      current = btn.getAttribute("data-sp-status");
      paintApps();
    });

    paintApps();

    /* ---------- timeline ---------- */
    $("#spTimeline").innerHTML = EVENTS.map(function (ev) {
      return '' +
      '<li class="sp-tl__item">' +
        '<span class="sp-tl__ic ' + (TONE_CLASS[ev.tone] || "") + '" aria-hidden="true">' + icon(ev.icon, "ic ic--sm") + '</span>' +
        '<span class="sp-tl__date">' + esc(ev.date) + '</span>' +
        '<h3 class="sp-tl__title">' + esc(ev.title) + '</h3>' +
        '<p class="sp-tl__text">' + esc(ev.text) + '</p>' +
      '</li>';
    }).join("");

    /* ---------- pending actions ---------- */
    $("#spTodos").innerHTML = TODOS.map(function (t) {
      return '' +
      '<li class="sp-todo">' +
        '<span class="sp-todo__ic' + (t.late ? " sp-todo__ic--due" : "") + '" aria-hidden="true">' + icon(t.icon, "ic ic--sm") + '</span>' +
        '<p class="sp-todo__title">' + esc(t.title) + '</p>' +
        '<div>' +
          '<p class="sp-todo__text">' + esc(t.text) + '</p>' +
          '<span class="sp-todo__due' + (t.late ? " sp-todo__due--late" : "") + '">' + icon("i-clock", "ic ic--sm") + esc(t.due) + '</span>' +
        '</div>' +
      '</li>';
    }).join("");

    var late = TODOS.filter(function (t) { return t.late; }).length;
    $("#spTodoSub").textContent = TODOS.length + " open item" + (TODOS.length === 1 ? "" : "s") +
      (late ? ", " + late + " already past its date." : ".");
  }

  /* =================================================================
     9. Side navigation — injected into #spSide on both portal pages so a
     signed-in student can move around easily (profile, documents, visa
     documents, application tracking) and sign out.
     ================================================================= */
  function renderSideNav(student) {
    var host = $("#spSide");
    if (!host) return;
    var st = student || {};
    var name = st.name || "Student";
    var sid = st.sid || st.student_ref || "";
    var initials = st.initials || ((name.charAt(0) || "S").toUpperCase());
    var onProfile = !!document.getElementById("spFormPersonal");
    var onTracking = !!document.getElementById("spSteps");

    function item(href, ic, label, active, extra, attrs) {
      return '<a class="sp-nav__item' + (active ? " is-active" : "") + (extra || "") + '" href="' + href + '"' +
        (attrs || "") + (active ? ' aria-current="page"' : "") + ">" + icon(ic) + "<span>" + esc(label) + "</span></a>";
    }

    host.innerHTML =
      '<div class="sp-side__inner">' +
        '<div class="sp-side__me">' +
          '<span class="sp-side__ava">' + esc(initials) + "</span>" +
          '<span class="sp-side__id"><b>' + esc(name) + "</b><span>" + esc(sid) + "</span></span>" +
        "</div>" +
        '<nav class="sp-nav" aria-label="Student portal">' +
          item("student-profile.html", "i-users", "My Profile", onProfile) +
          item("student-profile.html#spFormDocs", "i-doc", "Documents", false) +
          item("student-profile.html#spFormVisa", "i-passport", "Visa Documents", false) +
          item("student-tracking.html", "i-checks", "Application Tracking", onTracking) +
          '<span class="sp-nav__div" role="separator"></span>' +
          item("index.html", "i-home", "Back to website", false) +
          item("login.html", "i-arrow", "Log out", false, " sp-nav__item--out", ' data-sp-logout') +
        "</nav>" +
      "</div>";

    /* real logout — revoke the session server-side, then leave */
    var out = $("[data-sp-logout]", host);
    if (out) {
      out.addEventListener("click", function (e) {
        e.preventDefault();
        window.VFIApi.post("/api/student/logout", {}, { noRedirect: true }).then(function () {
          location.href = "login.html";
        }).catch(function () {
          location.href = "login.html";   /* leave regardless */
        });
      });
    }
  }

  /* =================================================================
     10. Boot
     ================================================================= */
  /* The portal pages are static files and cannot protect themselves — the API
     is the guard. We fetch FIRST and render nothing until it resolves; a 401
     makes js/api.js redirect to the sign-in page (docs §5.1). */
  function guardCatch(err) {
    if (err && err.status === 401) return;   /* api.js already redirected */
    /* transient failure: leave the shell blank rather than show anything stale */
  }

  if (document.getElementById("spFormPersonal")) {
    window.VFIApi.get("/api/me/profile", {}).then(function (payload) {
      renderSideNav(payload.student);
      initProfile(payload);
    }).catch(guardCatch);
  } else if (document.getElementById("spSteps")) {
    window.VFIApi.get("/api/me", {}).then(function (me) {
      renderSideNav(me);
      return window.VFIApi.get("/api/me/tracking", {});
    }).then(function (t) {
      if (t) initTracking(t);
    }).catch(guardCatch);
  } else {
    window.VFIApi.get("/api/me", {}).then(renderSideNav).catch(guardCatch);
  }
})();
