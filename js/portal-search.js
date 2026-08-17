/* =====================================================================
   VFI — partner program search (Phase 8F)
   Wires partner-search.html to the live catalogue through window.VFIApi
   (same-origin cookie session + CSRF). Every dropdown/checkbox is filled
   from the SINGLE served taxonomy (GET /api/taxonomy) — no hardcoded
   option lists — and the form drives GET /api/partner/programs/search.
   Details, compare (GET .../compare) and shortlist (POST .../shortlist)
   all run here. A 401 makes js/api.js redirect to the console login.

   ES5 only: var / function / string concat, to match the rest of js/.
   ===================================================================== */
(function () {
  "use strict";

  if (!window.VFIApi) return;
  if (!document.body || document.body.getAttribute("data-pp-page") !== "search") return;

  var $ = function (s, c) { return (c || document).querySelector(s); };
  var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };

  function esc(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
  }
  function toast(m) { if (window.VFIToast) window.VFIToast(m); }
  function val(sel) { var el = $(sel); return el ? String(el.value || "").trim() : ""; }
  function show(sel, on) { var el = $(sel); if (el) el.hidden = !on; }
  function cap(s) { s = String(s || ""); return s ? s.charAt(0).toUpperCase() + s.slice(1) : ""; }
  function money(t) {
    if (!t || t.minor == null) return "—";
    var n = Math.round(t.minor / 100);
    return (t.currency ? t.currency + " " : "") + n.toLocaleString();
  }
  function closestAttr(node, attr) {
    while (node && node !== document) {
      if (node.getAttribute && node.getAttribute(attr) != null) return node;
      node = node.parentNode;
    }
    return null;
  }

  var state = { page: 1, compare: {} };

  var BADGE = {
    stem: "STEM", scholarship: "Scholarship", coop: "Co-op", waive_english: "English waiver",
    moi: "MOI ok", fee_waiver: "Fee waiver", fast_offer: "Fast offer", major_city: "Major city",
    no_interview: "No interview", own_english: "Own English test", high_job_demand: "High demand",
    affordable: "Affordable", high_acceptance: "High acceptance", low_deposit: "Low deposit"
  };

  /* ---------------------------------------------------------------- taxonomy */
  function fillSelect(sel, terms) {
    var html = "";
    for (var i = 0; i < terms.length; i++) {
      html += '<option value="' + esc(terms[i].value) + '">' + esc(terms[i].label) + "</option>";
    }
    sel.insertAdjacentHTML("beforeend", html);
  }
  function fillChecks(box, terms) {
    var html = "";
    for (var i = 0; i < terms.length; i++) {
      html += '<label class="pp-check"><input type="checkbox" data-level="' + esc(terms[i].value) + '"> ' + esc(terms[i].label) + "</label>";
    }
    box.innerHTML = html;
  }
  function fillYears() {
    var y = $("#pgYear"); if (!y) return;
    var base = (new Date()).getFullYear();
    for (var i = 0; i < 4; i++) {
      var o = document.createElement("option");
      o.value = String(base + i); o.textContent = String(base + i); y.appendChild(o);
    }
  }
  function loadTaxonomy() {
    return VFIApi.get("/api/taxonomy").then(function (res) {
      var vocab = (res && res.vocabularies) || {};
      $$("[data-tax]").forEach(function (sel) {
        var kind = sel.getAttribute("data-tax");
        if (vocab[kind]) fillSelect(sel, vocab[kind]);
      });
      $$("[data-tax-checks]").forEach(function (box) {
        var kind = box.getAttribute("data-tax-checks");
        if (vocab[kind]) fillChecks(box, vocab[kind]);
      });
      var nat = $("#pgNationality"); if (nat) nat.value = "Bangladesh";
    });
  }

  /* ------------------------------------------------------------- build query */
  function collectFacets() {
    var out = [];
    $$("#pgReqs input[type=checkbox]").forEach(function (c) {
      if (c.checked && c.getAttribute("data-facet")) out.push(c.getAttribute("data-facet"));
    });
    $$(".pg-search__chips .pp-chip.is-on").forEach(function (ch) {
      var f = ch.getAttribute("data-facet"); if (f) out.push(f);
    });
    return out;
  }
  function collectLevels() {
    var out = [];
    $$("#pgLevels input[type=checkbox]:checked").forEach(function (c) {
      var v = c.getAttribute("data-level"); if (v) out.push(v);
    });
    $$(".pg-search__chips .pp-chip.is-on").forEach(function (ch) {
      var l = ch.getAttribute("data-level"); if (l) out.push(l);
    });
    return out;
  }
  function buildQuery(page) {
    var p = [];
    function add(k, v) { if (v !== "" && v != null) p.push(encodeURIComponent(k) + "=" + encodeURIComponent(v)); }
    add("q", val("#pgSearchInput"));
    add("intake", val("#pgIntake"));
    add("year", val("#pgYear"));
    add("country", val("#pgCountry"));
    add("study_area", val("#pgStudyArea"));
    add("duration_band", val("#pgDuration"));
    add("sort", val("#pgSort"));
    var levels = collectLevels(), i;
    for (i = 0; i < levels.length; i++) p.push("levels%5B%5D=" + encodeURIComponent(levels[i]));
    var facets = collectFacets();
    for (i = 0; i < facets.length; i++) p.push("facets%5B%5D=" + encodeURIComponent(facets[i]));
    add("page", page || 1);
    return p.join("&");
  }

  /* --------------------------------------------------------------- rendering */
  /* Sample rows must LOOK like sample rows.
     UK/Canada/Australia/Ireland/NZ have no licensed programme feed yet, so those
     universities are realistic placeholders generated by the seed ingest. A
     counsellor must never quote one to a student believing it is real, so the
     provenance is shown on the card and in the detail panel, not buried in a
     database column. */
  function sourceBadge(src) {
    return src === 'seed'
      ? '<span class="pg-badge pg-badge--sample" title="Placeholder data — this university and its fees are not real. Awaiting a licensed feed for this country.">Sample data</span>'
      : '';
  }

  function badgesHtml(r) {
    var b = r.badges || [], out = "", n = 0, key;
    out += sourceBadge(r.source);
    for (key in BADGE) {
      if (BADGE.hasOwnProperty(key) && b.indexOf(key) !== -1 && n < 4) {
        var cls = (key === "scholarship" || key === "fee_waiver" || key === "waive_english") ? " pg-badge--coral" : "";
        out += '<span class="pg-badge' + cls + '">' + esc(BADGE[key]) + "</span>"; n++;
      }
    }
    if (r.is_stale) out += '<span class="pg-badge pg-badge--stale">Deadline passed</span>';
    return out ? '<div class="pg-card__badges">' + out + "</div>" : "";
  }
  function intakeText(x) { return x ? (cap(x.season) + " " + x.year) : "—"; }
  function cardHtml(r) {
    var checked = state.compare[r.program_id] ? " checked" : "";
    return '<div class="pg-card" data-id="' + r.program_id + '">'
      + '<div class="pg-card__top">'
      + '<input type="checkbox" class="pg-card__cmp" data-cmp="' + r.program_id + '"' + checked + ' aria-label="Select to compare">'
      + '<div><div class="pg-card__title">' + esc(r.title) + "</div>"
      + '<div class="pg-card__uni">' + esc(r.university) + " · " + esc(r.country) + "</div></div>"
      + "</div>"
      + '<div class="pg-card__meta">'
      + "<span><b>" + esc(cap(r.level)) + "</b></span>"
      + "<span>" + esc(intakeText(r.intake)) + "</span>"
      + "<span>Tuition <b>" + money(r.tuition) + "</b></span>"
      + "<span>Deadline <b>" + esc(r.deadline || "Rolling") + "</b></span>"
      + "</div>"
      + badgesHtml(r)
      + '<div class="pg-card__foot">'
      + '<button class="pp-btn pp-btn--ghost pp-btn--sm" data-detail="' + r.program_id + '" type="button">Details</button>'
      + "</div></div>";
  }
  function renderResults(data) {
    var rows = (data && data.data) || [], meta = (data && data.meta) || {};
    var res = $("#pgResults");
    /* meta.total counts INTAKE rows — a programme with three intakes owns three
       of them — so reporting it as programmes claimed a catalogue of 123,621
       against a real 41,287. meta.programs is the distinct count; fall back to
       total only for a cached response from before the API returned it. */
    var progs = meta.programs != null ? meta.programs : (meta.total || 0);
    $("#pgResCount").textContent = progs.toLocaleString
      ? progs.toLocaleString() + " program" + (progs === 1 ? "" : "s") + " found"
      : progs + " program" + (progs === 1 ? "" : "s") + " found";
    show("#pgResHead", true);
    if (!rows.length) {
      res.innerHTML = '<div class="pg-search__msg">No programs match these filters. Try widening them.</div>';
      show("#pgPager", false); return;
    }
    var html = "", i;
    for (i = 0; i < rows.length; i++) html += cardHtml(rows[i]);
    res.innerHTML = html;
    $("#pgPageInfo").textContent = "Page " + meta.page + " of " + meta.last_page;
    show("#pgPager", meta.last_page > 1);
    $("#pgPrev").disabled = meta.page <= 1;
    $("#pgNext").disabled = meta.page >= meta.last_page;
  }
  function search(page) {
    state.page = page || 1;
    var res = $("#pgResults");
    res.innerHTML = '<div class="pg-search__msg">Searching…</div>';
    VFIApi.get("/api/partner/programs/search?" + buildQuery(state.page))
      .then(renderResults)
      .catch(function () { res.innerHTML = '<div class="pg-search__msg">Could not load results.</div>'; });
  }

  /* --------------------------------------------------------------- detail */
  function drow(k, v) { return "<dt>" + esc(k) + "</dt><dd>" + v + "</dd>"; }
  function flagLabels(p) {
    var out = [];
    if (p.is_stem) out.push("STEM");
    if (p.scholarship_available) out.push("Scholarship");
    if (p.has_coop_internship) out.push("Co-op / internship");
    if (p.moi_acceptable) out.push("MOI accepted");
    if (p.application_fee_waiver) out.push("Fee waiver");
    var inst = p.institution || {};
    if (inst.interview_required === false) out.push("No interview");
    if (inst.is_major_city) out.push("Major city");
    return out.length ? esc(out.join(", ")) : "—";
  }
  function detailHtml(p) {
    var inst = p.institution || {};
    // The detail panel is what a counsellor reads before quoting a fee to a
    // student, so a placeholder record has to say so unmissably here.
    var sampleWarning = p.source === 'seed'
      ? '<div class="pg-sample-warn"><b>Sample data — do not quote this to a student.</b>'
        + ' This university, its fees and its dates are placeholders. VFI has no licensed'
        + ' programme feed for ' + esc(inst.country || 'this country') + ' yet.</div>'
      : '';
    var intakes = (p.intakes || []).map(function (i) {
      return cap(i.season) + " " + i.year + (i.deadline ? (" (by " + i.deadline + ")") : "");
    }).join(", ") || "—";
    var reqs = (p.requirements || []).map(function (r) {
      return esc(r.test.toUpperCase() + (r.min_overall ? (" " + r.min_overall) : "")
        + (r.is_required ? "" : " (optional)") + (r.waiver_available ? " — waiver available" : ""));
    }).join("<br>") || "No test requirements listed";
    return sampleWarning
      + '<dl class="pg-dl">'
      + drow("University", esc(inst.name || "") + " · " + esc(inst.country || "") + (inst.city ? " · " + esc(inst.city) : ""))
      + drow("Level", esc(cap(p.level)))
      + drow("Study area", esc(p.study_area || "—"))
      + drow("Discipline", esc(p.discipline_area || "—"))
      + drow("Duration", esc(p.duration_band || "—"))
      + drow("Tuition", money(p.tuition))
      + drow("Application fee", p.application_fee ? money(p.application_fee) : "—")
      + drow("Intakes", esc(intakes))
      + drow("Requirements", reqs)
      + drow("Highlights", flagLabels(p))
      + "</dl>"
      + '<div class="pg-shortlist">'
      + '<div class="pp-field"><label class="pp-field__label">Add to a student’s shortlist</label>'
      + '<select class="pp-select" id="pgSlStudent"><option value="">Select a student…</option></select></div>'
      + '<div class="pp-field"><label class="pp-field__label">Note (optional)</label><input class="pp-input" id="pgSlNote" maxlength="500"></div>'
      + '<button class="pp-btn pp-btn--primary pp-btn--sm" id="pgSlSave" type="button">Save</button>'
      + "</div>";
  }
  function bindShortlist(programId) {
    var sel = $("#pgSlStudent");
    if (sel) VFIApi.get("/api/partner/students").then(function (d) {
      var list = (d && d.data) || [], html = "", i;
      for (i = 0; i < list.length; i++) html += '<option value="' + list[i].id + '">' + esc(list[i].name || list[i].email) + "</option>";
      if (html) sel.insertAdjacentHTML("beforeend", html);
    }).catch(function () {});
    var save = $("#pgSlSave");
    if (save) save.addEventListener("click", function () {
      var sid = sel ? sel.value : "";
      if (!sid) { toast("Pick a student first."); return; }
      VFIApi.post("/api/partner/students/" + sid + "/shortlist", { program_id: Number(programId), note: val("#pgSlNote") })
        .then(function () { toast("Saved to shortlist."); })
        .catch(function () { toast("Could not save to shortlist."); });
    });
  }
  function openDetail(id) {
    VFIApi.get("/api/partner/programs/" + id).then(function (data) {
      var p = data.program;
      $("#pgModalTitle").textContent = p.title;
      $("#pgModalBody").innerHTML = detailHtml(p);
      openModal();
      bindShortlist(id);
    }).catch(function () { toast("Could not load the program."); });
  }

  /* --------------------------------------------------------------- compare */
  function updateCmpBar() {
    var ids = Object.keys(state.compare);
    show("#pgCmpBar", ids.length > 0);
    var el = $("#pgCmpCount");
    if (el) el.textContent = ids.length + " selected" + (ids.length > 4 ? " (first 4 compared)" : "");
  }
  function compareHtml(rows) {
    var keys = [
      ["University", function (p) { return esc((p.university || "") + " · " + (p.country || "")); }],
      ["Level", function (p) { return esc(cap(p.level)); }],
      ["Study area", function (p) { return esc(p.study_area || "—"); }],
      ["Duration", function (p) { return esc(p.duration_band || "—"); }],
      ["Tuition", function (p) { return money(p.tuition); }],
      ["App. fee", function (p) { return p.application_fee ? money(p.application_fee) : "—"; }],
      ["STEM", function (p) { return p.is_stem ? "Yes" : "—"; }],
      ["Scholarship", function (p) { return p.scholarship_available ? "Yes" : "—"; }],
      ["MOI ok", function (p) { return p.moi_acceptable ? "Yes" : "—"; }],
      ["Interview", function (p) { return p.interview_required ? "Required" : "No"; }],
      ["Intakes", function (p) { return esc((p.intakes || []).map(function (i) { return cap(i.season) + " " + i.year; }).join(", ") || "—"); }]
    ];
    var html = '<div class="pg-cmpgrid">', c, k;
    for (c = 0; c < rows.length; c++) {
      html += '<div class="pg-cmpgrid__col"><div class="pg-card__title">' + esc(rows[c].title) + "</div>";
      for (k = 0; k < keys.length; k++) {
        html += '<div class="pg-cmpgrid__row"><div class="pg-cmpgrid__k">' + esc(keys[k][0]) + "</div>" + keys[k][1](rows[c]) + "</div>";
      }
      html += "</div>";
    }
    return html + "</div>";
  }
  function openCompare() {
    var ids = Object.keys(state.compare).slice(0, 4);
    if (!ids.length) { toast("Tick a few programs to compare."); return; }
    VFIApi.get("/api/partner/programs/compare?ids=" + ids.join(",")).then(function (data) {
      var rows = (data && data.data) || [];
      $("#pgModalTitle").textContent = "Compare " + rows.length + " programs";
      $("#pgModalBody").innerHTML = compareHtml(rows);
      openModal();
    }).catch(function () { toast("Could not load the comparison."); });
  }

  /* --------------------------------------------------------------- modal */
  function openModal() { var m = $("#pgModal"); if (m) { m.hidden = false; document.body.style.overflow = "hidden"; } }
  function closeModal() { var m = $("#pgModal"); if (m) { m.hidden = true; document.body.style.overflow = ""; } }

  /* --------------------------------------------------------------- wire up */
  function clearAll() {
    $$(".pg-search input[type=checkbox]").forEach(function (c) { c.checked = false; });
    $$(".pg-search select").forEach(function (s) { s.selectedIndex = 0; });
    var si = $("#pgSearchInput"); if (si) si.value = "";
    $$(".pg-search__chips .pp-chip").forEach(function (c) { c.classList.remove("is-on"); });
    state.compare = {}; updateCmpBar();
    search(1);
  }
  function setAdvOpen(open) {
    var wrap = $("#pgAdvWrap"), title = $("#pgAdvTitle");
    if (!wrap) return;
    wrap.classList.toggle("is-collapsed", !open);
    if (title) title.setAttribute("aria-expanded", open ? "true" : "false");
  }

  function init() {
    fillYears();

    // chips toggle their own active state
    $$(".pg-search__chips .pp-chip").forEach(function (chip) {
      chip.addEventListener("click", function () { chip.classList.toggle("is-on"); });
    });

    var sBtn = $("#pgSearchBtn"); if (sBtn) sBtn.addEventListener("click", function () { search(1); });
    var sIn = $("#pgSearchInput"); if (sIn) sIn.addEventListener("keydown", function (e) { if (e.key === "Enter") search(1); });
    var sort = $("#pgSort"); if (sort) sort.addEventListener("change", function () { search(1); });
    var prev = $("#pgPrev"); if (prev) prev.addEventListener("click", function () { if (state.page > 1) search(state.page - 1); });
    var next = $("#pgNext"); if (next) next.addEventListener("click", function () { search(state.page + 1); });

    var clr = $("#pgClearAll");
    if (clr) {
      clr.addEventListener("click", clearAll);
      clr.addEventListener("keydown", function (e) { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); clearAll(); } });
    }
    var advTitle = $("#pgAdvTitle"), advClose = $("#pgAdvClose");
    if (advTitle) {
      advTitle.addEventListener("click", function () { setAdvOpen($("#pgAdvWrap").classList.contains("is-collapsed")); });
      advTitle.addEventListener("keydown", function (e) { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); setAdvOpen($("#pgAdvWrap").classList.contains("is-collapsed")); } });
    }
    if (advClose) advClose.addEventListener("click", function () { setAdvOpen(false); });

    // result delegation
    var res = $("#pgResults");
    res.addEventListener("click", function (e) {
      var d = closestAttr(e.target, "data-detail"); if (d) openDetail(d.getAttribute("data-detail"));
    });
    res.addEventListener("change", function (e) {
      var c = e.target;
      if (c.getAttribute && c.getAttribute("data-cmp") != null) {
        var id = c.getAttribute("data-cmp");
        if (c.checked) state.compare[id] = true; else delete state.compare[id];
        updateCmpBar();
      }
    });

    var cmpBtn = $("#pgCmpBtn"); if (cmpBtn) cmpBtn.addEventListener("click", openCompare);
    var cmpClr = $("#pgCmpClear"); if (cmpClr) cmpClr.addEventListener("click", function () {
      state.compare = {}; updateCmpBar();
      $$("#pgResults input[data-cmp]").forEach(function (c) { c.checked = false; });
    });

    var modal = $("#pgModal");
    if (modal) modal.addEventListener("click", function (e) {
      // closestAttr, not e.target: the X button contains an <svg>, so a click
      // lands on the icon and never on the element carrying data-close.
      if (closestAttr(e.target, "data-close")) closeModal();
    });
    document.addEventListener("keydown", function (e) { if (e.key === "Escape") closeModal(); });

    // populate the taxonomy, then run an initial search so results show at once
    loadTaxonomy().then(function () { search(1); }, function () { search(1); });
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();
