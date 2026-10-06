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
  // A zero is a fact, not a gap: DaadSource::tuitionMinor() returns 0 only when
  // the feed says "none"/"no tuition"/"free", and null when it has no figure —
  // and null already renders as an em dash here, so the two stay distinguishable.
  // "EUR 0" was true and read like a broken number.
  //
  // One word, not the university page's "No tuition fee", because every call
  // site here already supplies a label: tuitionLabel() precedes it on the card
  // and in the detail row, and the compare grid has a "Tuition" row header. So
  // "Tuition — No tuition fee" would stutter, while "Tuition — Free" reads.
  // It is also four characters against "EUR 0"'s five, so no column can get
  // wider than it already was.
  function money(t) {
    if (!t || t.minor == null) return "—";
    if (+t.minor === 0) return "Free";
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

  /* `cmpSeq` exists because Object.keys() betrays insertion order.
     state.compare is keyed by programme id, and JS enumerates integer-like keys
     in ascending NUMERIC order — so Object.keys(...).slice(0, 4) took the four
     LOWEST ids while the bar promised "first 4 compared". Tick six and the grid
     showed four you had not chosen, with nothing to say so. Each entry now
     carries the order it was ticked in, and comparedIds() sorts on that. */
  var state = { page: 1, compare: {}, rows: {}, cmpSeq: 0 };

  /* Ticked programme ids, in the order the counsellor ticked them. */
  function comparedIds() {
    return Object.keys(state.compare).sort(function (a, b) {
      return (state.compare[a].order || 0) - (state.compare[b].order || 0);
    });
  }

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
  /* The vocabulary, kept after it is fetched so the SAME list that fills the
     filters can also label a value on a card.
     Without this the modal printed `4yr_plus` — a database token — straight at
     a counsellor, under the heading "Duration". /api/taxonomy already serves
     level, study_area and duration_band with human labels; this page was
     fetching all of them and throwing away everything it did not put in a
     dropdown. */
  var LABELS = {};
  function lbl(kind, value) {
    if (value == null || value === "") return "";
    var terms = LABELS[kind] || [], i;
    for (i = 0; i < terms.length; i++) {
      if (terms[i].value === value) return terms[i].label;
    }
    return cap(String(value).replace(/_/g, " "));   // unknown token, still readable
  }

  /* The ONE taxonomy kind this page deliberately does NOT route through lbl():
     `intake`. Its served labels are form labels — "Fall / Autumn (September)" —
     written to disambiguate inside a dropdown, where that is exactly right.
     Inside a sentence they read wrong: "Fall / Autumn (September) 2026". So a
     RENDERED intake stays cap(season) + " " + year, and only the #pgIntake
     <option> carries the full served label.

     Every other kind — level, study_area, discipline_area, duration_band — goes
     through lbl() at EVERY print site. That is the point of the three helpers
     below: the detail panel and the compare grid were formatting the same four
     fields in two places and had already drifted apart, so Compare printed
     "DURATION 4yr_plus" and "STUDY AREA —" on live while the detail panel beside
     it printed "4+ years" and a folded Subject row. One helper each, no second
     copy to forget. */
  function levelText(p, empty) { return lbl("level", p.level) || empty; }
  function durationText(p, empty) { return lbl("duration_band", p.duration_band) || empty; }

  /* Subject = study_area AND/OR discipline_area, folded into one value.
     Many feeds populate only one of the two — DAAD gives a discipline and no
     study area, so `study_area` is null on every German row — and printing an
     em dash beside a populated sibling reads as missing data rather than as a
     field this source does not carry.

     discipline_area goes through lbl() even though the column currently holds
     human text rather than taxonomy tokens ("Agricultural Science", not
     `agriculture`): lbl() returns an unmatched value essentially unchanged, so
     this is a no-op on today's data and becomes correct for free on the day the
     column is normalised to the served vocabulary. See buildQuery() for why
     that distinction is load-bearing for the Discipline filter. */
  function subjectText(p, empty) {
    var parts = [lbl("study_area", p.study_area), lbl("discipline_area", p.discipline_area)];
    var out = [], i;
    for (i = 0; i < parts.length; i++) { if (parts[i]) out.push(parts[i]); }
    return out.join(" · ") || empty;
  }

  function loadTaxonomy() {
    return VFIApi.get("/api/taxonomy").then(function (res) {
      var vocab = (res && res.vocabularies) || {};
      LABELS = vocab;
      $$("[data-tax]").forEach(function (sel) {
        var kind = sel.getAttribute("data-tax");
        if (vocab[kind]) fillSelect(sel, vocab[kind]);
      });
      $$("[data-tax-checks]").forEach(function (box) {
        var kind = box.getAttribute("data-tax-checks");
        if (vocab[kind]) fillChecks(box, vocab[kind]);
      });
      /* There is no `nat.value = "Bangladesh"` here any more, and no
         #pgNationality to set it on. The control sat in the top search row
         pre-selected to Bangladesh, which reads as an applied constraint, and
         nothing ever sent it. See the comment where it was removed in
         partner-search.html. */
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
    /* #pgDiscipline was filled from the taxonomy with 18 real options and then
       never read: picking "Cybersecurity" changed the query by nothing at all.
       It now sends the taxonomy TOKEN (`cybersecurity`), the same contract as
       country / study_area / duration_band / levels above, because /api/taxonomy
       is the one vocabulary both ends are meant to share.

       Measured against live before writing this, because it decides whether the
       control works: `programs.discipline_area` does NOT hold those tokens. It
       holds free text, and three ingests disagree about its shape —
       "Agricultural Science" (DAAD subject), "Data Science" (seed titles),
       "Business Administration, Management And Operations." (Scorecard CIP
       titles, trailing period included). A 300-row sample across Germany, the
       US, the UK and Canada held 148 distinct values and ZERO equal to any of
       the 18 taxonomy tokens. So a server-side `where('discipline_area', $token)`
       matches nothing, for every option, and this control would go from
       decorative to actively worse: an empty catalogue presented as a result.
       The token→stored-value mapping belongs on the ingest/indexer side and is
       flagged to the backend engineer adding this filter; it cannot be papered
       over here. Until it exists the empty-result line below at least names
       Discipline as the filter that emptied the page. */
    add("discipline_area", val("#pgDiscipline"));
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

  /* A tuition figure that is really an institution-wide average must not read as
     this programme's price — that is the number a counsellor quotes to a student.
     The US College Scorecard publishes one annual tuition per school and nothing
     per course, so ~40,000 programmes carry the same value; tuition.basis is set
     by the feed that knows which kind of number it is, so the card never has to
     ask where a row came from. The amber --stale pill is reused on purpose: it is
     already this page's "check this before you trust the row" styling. */
  function isAvgTuition(t) { return !!(t && t.basis === "institution_average"); }
  function tuitionLabel(t) { return isAvgTuition(t) ? "Uni. average" : "Tuition"; }
  function basisBadge(t) {
    return isAvgTuition(t)
      ? '<span class="pg-badge pg-badge--stale" title="Institution-wide average annual tuition, not a programme fee. Confirm the exact programme cost with the university before quoting it.">Institution average</span>'
      : '';
  }

  function badgesHtml(r) {
    var b = r.badges || [], out = "", n = 0, key;
    out += sourceBadge(r.source);
    out += basisBadge(r.tuition);
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

  /* The CURRENT shape: one card per programme carrying an `intakes` ARRAY.
     `r.intake` — one row per programme-intake, the shape before the collapse —
     is kept only so a response a browser cached from before that change still
     renders something truthful rather than an empty dash. */
  function intakeList(r) { return r.intakes || (r.intake ? [r.intake] : []); }

  /* "Rolling" is this page's existing word for an intake with no published
     deadline, and most of the DAAD catalogue has none. */
  function deadlineOf(x, r) {
    var d = x ? x.deadline : (r ? r.deadline : null);
    return d || "Rolling";
  }

  /* Every intake this programme offers that matched the search, on ONE card.
     The API used to return one row per programme-intake and this rendered one
     card each, so a Master's at Kiel with three intakes filled the screen three
     times over with identical text. The intake is a property of the programme,
     not a different programme, so it belongs inside the card — and it is the
     thing a counsellor picks, so it is a control rather than a sentence. */
  function intakesHtml(r, list) {
    if (!list.length) return "<span>—</span>";
    var out = "", i, x;
    for (i = 0; i < list.length; i++) {
      x = list[i];
      // data-deadline rides along so changing the picker can move the card's
      // Deadline line without another request; see the change handler in init().
      out += '<option value="' + esc(String(x.row_id == null ? i : x.row_id)) + '"'
        + ' data-season="' + esc(x.season || "") + '" data-year="' + esc(String(x.year || "")) + '"'
        + ' data-deadline="' + esc(deadlineOf(x, null)) + '">'
        + esc(intakeText(x)) + (x.is_stale ? " (passed)" : "") + "</option>";
    }
    if (list.length === 1) return "<span>" + esc(intakeText(list[0])) + "</span>";
    return '<select class="pg-card__intake" data-intake-for="' + esc(String(r.program_id)) + '"'
      + ' aria-label="Intake for ' + esc(r.title) + '">' + out + "</select>";
  }

  function cardHtml(r) {
    var checked = state.compare[r.program_id] ? " checked" : "";
    var list = intakeList(r);
    /* The deadline printed here belongs to the intake CHOSEN on this card, not
       to the programme. r.deadline is the soonest deadline across every matching
       intake — the server's sort key — and printing that beside a picker reading
       "Spring 2027" showed the Fall 2026 date under a Spring selection, which is
       the one number on this card a counsellor repeats to a student. The first
       option is the one selected at paint, so the line starts on it and the
       change handler keeps the two together from then on. */
    var dl = deadlineOf(list.length ? list[0] : null, r);
    return '<div class="pg-card" data-id="' + r.program_id + '">'
      + '<div class="pg-card__top">'
      + '<input type="checkbox" class="pg-card__cmp" data-cmp="' + r.program_id + '"' + checked + ' aria-label="Select to compare">'
      + '<div><div class="pg-card__title">' + esc(r.title) + "</div>"
      + '<div class="pg-card__uni">' + esc(r.university) + " · " + esc(r.country) + "</div></div>"
      + "</div>"
      + '<div class="pg-card__meta">'
      // lbl(), not cap(): cap("mba") is "Mba", cap("bachelor_honours") is
      // "Bachelor honours", and the taxonomy already serves "MBA" and
      // "Bachelor's (Honours)" for exactly this.
      + "<span><b>" + esc(levelText(r, "—")) + "</b></span>"
      + "<span>" + intakesHtml(r, list) + "</span>"
      + "<span>" + tuitionLabel(r.tuition) + " <b>" + money(r.tuition) + "</b></span>"
      + '<span>Deadline <b data-deadline-for="' + esc(String(r.program_id)) + '">' + esc(dl) + "</b></span>"
      + "</div>"
      + badgesHtml(r)
      + '<div class="pg-card__foot">'
      + '<button class="pp-btn pp-btn--ghost pp-btn--sm" data-detail="' + r.program_id + '" type="button">Details</button>'
      + "</div></div>";
  }
  /* A zero-result screen must never be mute about its own filters.
     Eleven controls on this page narrow the query and four of them sit in a
     panel that can be collapsed, so "No programs match these filters" could
     easily be the work of a Discipline or a Requirement the counsellor set
     minutes ago and can no longer see. Every label below is read back off the
     control itself, so this can only ever describe a filter the query actually
     carried — it cannot drift from buildQuery() the way a second hand-written
     list would. */
  function selLabel(sel) {
    var el = $(sel);
    if (!el || !String(el.value || "").trim()) return "";
    var o = el.options[el.selectedIndex];
    return o ? o.textContent.replace(/\s+/g, " ").trim() : "";
  }
  function checkedLabels(scope) {
    return $$(scope + " input[type=checkbox]:checked").map(function (c) {
      // the <label class="pp-check"> wrapping the box carries the visible text
      var p = c.parentNode;
      return p && p.textContent ? p.textContent.replace(/\s+/g, " ").trim() : "";
    }).filter(function (t) { return t; });
  }
  function appliedFilters() {
    var out = [];
    function push(name, text) { if (text) out.push(name + ": " + text); }
    push("Keyword", val("#pgSearchInput"));
    push("Intake", selLabel("#pgIntake"));
    push("Year", selLabel("#pgYear"));
    push("Country", selLabel("#pgCountry"));
    push("Study area", selLabel("#pgStudyArea"));
    push("Discipline", selLabel("#pgDiscipline"));
    push("Duration", selLabel("#pgDuration"));
    push("Program level", checkedLabels("#pgLevels").join(", "));
    push("Requirements", checkedLabels("#pgReqs").join(", "));
    push("Quick filters", $$(".pg-search__chips .pp-chip.is-on").map(function (ch) {
      return ch.textContent.replace(/\s+/g, " ").trim();
    }).join(", "));
    return out;
  }

  function renderResults(data) {
    var rows = (data && data.data) || [], meta = (data && data.meta) || {};
    var res = $("#pgResults");
    /* Both numbers are now the programme count, because a result IS a
       programme. They used to differ: a row was one programme-INTAKE, so
       reporting meta.total as programmes claimed a catalogue of 123,621 against
       a real 41,287. meta.programs is still read first so a cached response
       from before the collapse still counts correctly. */
    var progs = meta.programs != null ? meta.programs : (meta.total || 0);
    $("#pgResCount").textContent = progs.toLocaleString
      ? progs.toLocaleString() + " program" + (progs === 1 ? "" : "s") + " found"
      : progs + " program" + (progs === 1 ? "" : "s") + " found";
    show("#pgResHead", true);
    if (!rows.length) {
      var applied = appliedFilters();
      res.innerHTML = '<div class="pg-search__msg">No programs match these filters.'
        + (applied.length ? "<small>Filtering on — " + esc(applied.join(" · ")) + "</small>" : "")
        + "<small>Clear one of them, or use Clear All.</small></div>";
      show("#pgPager", false); return;
    }
    var html = "", i;
    /* The rows this page is currently showing, by program_id. The compare grid
       reads `source` back out of here when a card is ticked, because the compare
       endpoint does not return it — see comparedSource(). Reset per render on
       purpose: a card can only be ticked while it is on screen, so this never
       needs to outlive the page it rendered. */
    state.rows = {};
    for (i = 0; i < rows.length; i++) {
      state.rows[String(rows[i].program_id)] = rows[i];
      html += cardHtml(rows[i]);
    }
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
      .catch(function (err) {
        /* A 422 here means this form sent the API a filter it does not accept —
           which is precisely what happens for one deploy if a control is wired
           on one side and not the other. "Could not load results." costs an
           afternoon of guessing for that, so the validation message is shown as
           it came. js/api.js puts it on err.message and handles 401 itself by
           redirecting, so neither case reaches this line. */
        var why = (err && err.status === 422 && err.message) ? err.message : "";
        res.innerHTML = '<div class="pg-search__msg">Could not load results.'
          + (why ? "<small>" + esc(why) + "</small>" : "") + "</div>";
        // The old count is now a lie sitting above an error, but the Sort
        // control lives in the same bar, so blank the number and keep the bar.
        var rc = $("#pgResCount"); if (rc) rc.textContent = "Results";
        show("#pgPager", false);
      });
  }

  /* --------------------------------------------------------------- detail */
  function drow(k, v) { return "<dt>" + esc(k) + "</dt><dd>" + v + "</dd>"; }
  /* The card gets a badge; the detail panel gets the sentence, because this is
     the screen a counsellor is looking at when the student asks what it costs. */
  function basisNote(t) {
    return isAvgTuition(t)
      ? "<br><small>Institution-wide average annual tuition &mdash; the source publishes one figure per university, not per programme."
        + " Do not quote it as the programme fee; confirm the exact cost with the university.</small>"
      : "";
  }
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
      // All three through the shared helpers, so this panel and the compare
      // grid cannot say different things about the same programme again.
      + drow("Level", esc(levelText(p, "Not published")))
      + drow("Subject", esc(subjectText(p, "Not published by this source")))
      + drow("Duration", esc(durationText(p, "Not published")))
      + drow(tuitionLabel(p.tuition), money(p.tuition) + basisNote(p.tuition))
      + drow("Application fee", p.application_fee ? money(p.application_fee) : "Not published")
      + drow("Intakes", esc(intakes))
      + drow("Requirements", reqs)
      + drow("Highlights", flagLabels(p))
      + "</dl>"
      + '<div class="pg-shortlist">'
      + '<div class="pp-field"><label class="pp-field__label">Add to a student’s shortlist</label>'
      + '<select class="pp-select" id="pgSlStudent"><option value="">Select a student…</option></select></div>'
      /* The intake is chosen HERE, against this programme.
         It was not chosen anywhere: a shortlist row recorded a student and a
         programme, the screen showed whichever intake came next, and Apply
         created the application for that one. A counsellor who wanted Summer
         had no way to say so. The student's own recorded intake is a
         preference, not an instruction — these are different questions and the
         answer below says which one is being answered. */
      + '<div class="pp-field"><label class="pp-field__label">Intake for this programme</label>'
      + '<select class="pp-select" id="pgSlIntake">' + ((p.intakes || []).map(function (i) {
          return '<option value="' + esc(i.season || "") + '" data-year="' + esc(String(i.year || "")) + '">'
            + esc(cap(i.season) + " " + i.year) + "</option>";
        }).join("") || '<option value="">No published intake</option>') + "</select>"
      + '<span class="pp-field__err" id="pgSlIntakeNote"></span></div>'
      + '<div class="pp-field"><label class="pp-field__label">Note (optional)</label><input class="pp-input" id="pgSlNote" maxlength="500"></div>'
      + '<button class="pp-btn pp-btn--primary pp-btn--sm" id="pgSlSave" type="button">Save</button>'
      + "</div>";
  }
  function bindShortlist(programId) {
    var sel = $("#pgSlStudent");
    var intakeSel = $("#pgSlIntake");
    var intakeNote = $("#pgSlIntakeNote");
    var students = {};

    /* The two intakes, side by side.
       A student can be registered wanting Spring while this programme is being
       shortlisted for Summer. Neither is wrong — one is what they told the
       agency, the other is what is being applied for — but a counsellor should
       see the difference at the moment they create it, not discover it on an
       offer letter. This only ever says so; it never overrides the choice. */
    function reconcile() {
      if (!intakeNote) return;
      var s = students[sel && sel.value];
      var want = (s && s.intake) ? String(s.intake).trim() : "";
      if (!want || !intakeSel || !intakeSel.value) { intakeNote.textContent = ""; return; }
      var optEl = intakeSel.options[intakeSel.selectedIndex];
      var chosen = optEl ? optEl.textContent.trim() : "";
      intakeNote.textContent = (chosen && want.toLowerCase() !== chosen.toLowerCase())
        ? "This student is registered for " + want + ". Saving will shortlist them for " + chosen + "."
        : "";
    }

    if (sel) VFIApi.get("/api/partner/students").then(function (d) {
      var list = (d && d.data) || [], html = "", i;
      for (i = 0; i < list.length; i++) {
        students[String(list[i].id)] = list[i];
        html += '<option value="' + list[i].id + '">' + esc(list[i].name || list[i].email) + "</option>";
      }
      if (html) sel.insertAdjacentHTML("beforeend", html);
      sel.addEventListener("change", reconcile);
      if (intakeSel) intakeSel.addEventListener("change", reconcile);
    }).catch(function () {});

    var save = $("#pgSlSave");
    if (save) save.addEventListener("click", function () {
      var sid = sel ? sel.value : "";
      if (!sid) { toast("Pick a student first."); return; }
      var body = { program_id: Number(programId), note: val("#pgSlNote") };
      if (intakeSel && intakeSel.value) {
        var o = intakeSel.options[intakeSel.selectedIndex];
        body.intake_month = intakeSel.value;
        if (o && o.getAttribute("data-year")) body.intake_year = Number(o.getAttribute("data-year"));
      }
      VFIApi.post("/api/partner/students/" + sid + "/shortlist", body)
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
    var ids = comparedIds();
    show("#pgCmpBar", ids.length > 0);
    var el = $("#pgCmpCount");
    if (el) el.textContent = ids.length + " selected" + (ids.length > 4 ? " (first 4 compared)" : "");
  }
  /* The compare endpoint does not return `source`, so the grid had NO
     sample-data marking at all while the card and the detail panel both carry
     one: a counsellor could tick a seed row, open Compare, and read a fabricated
     tuition in the column beside two real ones with nothing saying which was
     which — the single worst thing this screen can do. This reads the value off
     the search row the counsellor actually ticked, which is real data from the
     response that drew the card, not a guess. A programme ticked and then
     compared after a re-render returns null and simply gets no badge; it can
     never claim a seed row is real. */
  function comparedSource(id) {
    var c = state.compare[String(id)];
    return c && c.source ? c.source : null;
  }

  function compareHtml(rows) {
    var keys = [
      ["University", function (p) { return esc((p.university || "") + " · " + (p.country || "")); }],
      // These four were the drift: Level printed cap() ("Mba"), and Study area
      // and Duration printed the raw database token, so Compare read
      // "DURATION 4yr_plus" / "STUDY AREA —" against the detail panel's
      // "4+ years" and a populated Subject. Same helpers as detailHtml now.
      ["Level", function (p) { return esc(levelText(p, "—")); }],
      ["Subject", function (p) { return esc(subjectText(p, "—")); }],
      ["Duration", function (p) { return esc(durationText(p, "—")); }],
      /* The compare grid puts a real course fee and an institution average in
         adjacent columns, so the qualifier travels with the value — the row
         label is shared by every column and cannot say it. */
      ["Tuition", function (p) { return money(p.tuition) + (isAvgTuition(p.tuition) ? " <small>(uni. average)</small>" : ""); }],
      ["App. fee", function (p) { return p.application_fee ? money(p.application_fee) : "—"; }],
      ["STEM", function (p) { return p.is_stem ? "Yes" : "—"; }],
      ["Scholarship", function (p) { return p.scholarship_available ? "Yes" : "—"; }],
      ["MOI ok", function (p) { return p.moi_acceptable ? "Yes" : "—"; }],
      // null means the compare row had no institution to ask, and "No" would be
      // asserting that no interview is needed. detailHtml already tests
      // `=== false` for the same reason.
      ["Interview", function (p) { return p.interview_required == null ? "—" : (p.interview_required ? "Required" : "No"); }],
      ["Intakes", function (p) { return esc((p.intakes || []).map(function (i) { return cap(i.season) + " " + i.year; }).join(", ") || "—"); }]
    ];
    var html = '<div class="pg-cmpgrid">', c, k, badge;
    for (c = 0; c < rows.length; c++) {
      badge = sourceBadge(comparedSource(rows[c].id));
      html += '<div class="pg-cmpgrid__col"><div class="pg-card__title">' + esc(rows[c].title) + "</div>"
        + (badge ? '<div class="pg-card__badges">' + badge + "</div>" : "");
      for (k = 0; k < keys.length; k++) {
        html += '<div class="pg-cmpgrid__row"><div class="pg-cmpgrid__k">' + esc(keys[k][0]) + "</div>" + keys[k][1](rows[c]) + "</div>";
      }
      html += "</div>";
    }
    return html + "</div>";
  }
  function openCompare() {
    var ids = comparedIds().slice(0, 4);   // the first four TICKED, not the four lowest ids
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
      if (!c || !c.getAttribute) return;

      if (c.getAttribute("data-cmp") != null) {
        var id = c.getAttribute("data-cmp");
        if (c.checked) {
          // An object, never `true`: comparedSource() reads .source back out of
          // it, and every truthiness test on state.compare still works.
          var row = state.rows[String(id)];
          state.compare[id] = { source: row ? row.source : null, order: ++state.cmpSeq };
        } else {
          delete state.compare[id];
        }
        updateCmpBar();
        return;
      }

      // The per-card intake picker. Moving the Deadline line with it keeps the
      // card internally consistent without a second request — every intake's
      // deadline already arrived on this card. See cardHtml().
      if (c.getAttribute("data-intake-for") != null) {
        var card = closestAttr(c, "data-id");
        var out = card ? card.querySelector("[data-deadline-for]") : null;
        var opt = c.options ? c.options[c.selectedIndex] : null;
        if (out && opt) out.textContent = opt.getAttribute("data-deadline") || "Rolling";
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
    /* Tell the filter panel the truth about itself before the first search.

       Measured against the live catalogue of 41,069 programmes: of the 33 facet
       checkboxes on this page, only FIVE discriminate. 24 match nothing, and 4
       match every programme. That is not an indexing bug — SearchIndexer writes
       all 33 — it is absent source data: Scorecard publishes no co-op,
       scholarship or interview fields, and institutions.interview_required is
       NOT NULL DEFAULT false so every programme truthfully reports "no
       interview". The filters cannot be repaired by code.

       A counsellor ticking "Scholarship Available" got an empty page and
       concluded the catalogue was empty. One ticking "No Interview Required"
       watched the count not move and believed they had narrowed to
       interview-free programmes. The second is the worse of the two.

       So each facet now carries its real count, a facet that finds nothing is
       disabled and says so, and one that matches everything is disabled as
       well — it is not a filter, it is a label. Driven entirely by the numbers,
       so a facet starts working on its own the day a feed carries it. */
    function applyFacetCounts() {
      return VFIApi.get("/api/partner/programs/facets", { noRedirect: true }).then(function (res) {
        var counts = (res && res.facets) || {};
        var total = (res && res.total) || 0;

        $$("[data-facet]").forEach(function (box) {
          var token = box.getAttribute("data-facet");
          if (!(token in counts)) return;
          var n = counts[token];
          var label = box.parentNode;
          var why = n === 0
            ? "No programme in the catalogue carries this yet"
            : (total && n === total ? "Every programme matches this, so it narrows nothing" : "");

          var tag = document.createElement("span");
          tag.className = "pg-facet__n";
          tag.textContent = n === 0 ? " — none" : (total && n === total ? " — all" : " (" + n.toLocaleString() + ")");
          label.appendChild(tag);

          if (why) {
            box.checked = false;
            box.disabled = true;
            label.classList.add("is-inert");
            label.setAttribute("title", why);
          }
        });

        // Destinations with nothing behind them. VFI has no licensed feed for
        // five of the fifteen; offering them is how a counsellor concludes
        // Canada has nothing to apply to.
        var cc = (res && res.countries) || {};
        var sel = $("#pgCountry");
        if (sel) {
          Array.prototype.forEach.call(sel.options, function (o) {
            if (!o.value) return;
            var n = cc[o.value] || 0;
            if (!n) { o.disabled = true; o.textContent = o.textContent + " — none yet"; }
            else { o.textContent = o.textContent + " (" + n.toLocaleString() + ")"; }
          });
        }
      })["catch"](function () { /* counts are an improvement, never a gate */ });
    }

    loadTaxonomy().then(function () {
      applyFacetCounts();            // not awaited: the first search must not wait on it
      search(1);
    }, function () { search(1); });
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();
