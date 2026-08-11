/* =====================================================================
   VFI — public university directory + detail + Book Free Counselling
   (Phase 8, student side). Drives universities.html (search by country,
   live cards, paging) and university.html (detail: facts, courses,
   intakes, related). "Apply Now" opens a counselling modal that posts a
   lead to POST /api/contact (the same public intake as the contact form),
   which connects the student with a VFI agent.

   All reads hit the PUBLIC endpoints (no auth):
     GET /api/universities/meta         countries for the dropdown
     GET /api/universities?country=&q=  paged directory
     GET /api/universities/{id}         detail

   ES5 only: var / function / string concat.
   ===================================================================== */
(function () {
  "use strict";
  if (!window.VFIApi) return;

  var $ = function (s, c) { return (c || document).querySelector(s); };
  function esc(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
  }
  function val(sel) { var el = $(sel); return el ? String(el.value || "").trim() : ""; }
  function show(sel, on) { var el = $(sel); if (el) el.hidden = !on; }
  function cap(s) { s = String(s || ""); return s ? s.charAt(0).toUpperCase() + s.slice(1) : ""; }
  function money(t) {
    if (!t || t.minor == null) return "";
    return (t.currency ? t.currency + " " : "") + Math.round(t.minor / 100).toLocaleString();
  }
  function tatText(b) { return b === "fast" ? "Fast" : (b === "slow" ? "Standard+" : "Standard"); }
  function getParam(n) {
    var m = new RegExp("[?&]" + n + "=([^&]*)").exec(window.location.search);
    return m ? decodeURIComponent(m[1].replace(/\+/g, " ")) : "";
  }
  function closestAttr(node, attr) {
    while (node && node !== document) {
      if (node.getAttribute && node.getAttribute(attr) != null) return node;
      node = node.parentNode;
    }
    return null;
  }

  /* ---------------------------------------- shared university card markup */
  function initials(name) {
    var w = String(name || "").replace(/[^A-Za-z ]/g, "").split(" ");
    var out = "";
    for (var i = 0; i < w.length && out.length < 2; i++) { if (w[i]) out += w[i].charAt(0).toUpperCase(); }
    return out || "U";
  }
  function uniCardHtml(r) {
    var variant = ["a", "b", "c"][r.id % 3];
    var tags = "";
    if (r.is_major_city) tags += '<span class="unic__tag">Major city</span>';
    if (r.affordability_band === "low") tags += '<span class="unic__tag">Affordable</span>';
    if (r.offer_tat_band === "fast") tags += '<span class="unic__tag">Fast offers</span>';
    if (r.vfi_represented) tags += '<span class="unic__tag">VFI partner</span>';
    return '<article class="unic">'
      + '<div class="unic__logo unic__logo--' + variant + '">' + esc(initials(r.name)) + '</div>'
      + '<h3>' + esc(r.name) + '</h3>'
      + '<p class="unic__loc"><svg class="ic ic--sm"><use href="#i-pin"/></svg> ' + esc(r.location) + '</p>'
      + (tags ? '<div class="unic__tags">' + tags + '</div>' : '')
      + '<p style="margin:10px 0 0"><span class="unic__prog"><svg class="ic ic--sm"><use href="#i-book"/></svg> ' + r.programs + ' program' + (r.programs === 1 ? '' : 's') + '</span></p>'
      + '<div class="unic__cta">'
      + '<a href="university.html?id=' + r.id + '" class="btn btn--outline btn--sm">Know More</a>'
      + '<button type="button" class="btn btn--enquire btn--sm" data-apply-uni data-name="' + esc(r.name) + '" data-country="' + esc(r.country) + '">Apply Now</button>'
      + '</div></article>';
  }

  /* -------------------------------------------- Book Free Counselling modal */
  var modal = $("#bookModal");
  var ctxUni = null;
  function openBook(ctx) {
    ctxUni = ctx || null;
    var ctxEl = $("#bookCtx");
    if (ctxEl) {
      if (ctx && ctx.name) { ctxEl.innerHTML = 'Enquiry about <b>' + esc(ctx.name) + '</b>' + (ctx.country ? ' · ' + esc(ctx.country) : ''); ctxEl.hidden = false; }
      else { ctxEl.hidden = true; }
    }
    var out = $("#bookMsgOut"); if (out) { out.textContent = ""; out.className = "umodal__msg"; }
    if (modal) { modal.hidden = false; document.body.style.overflow = "hidden"; var n = $("#bookName"); if (n) n.focus(); }
  }
  function closeBook() { if (modal) { modal.hidden = true; document.body.style.overflow = ""; } }

  if (modal) {
    modal.addEventListener("click", function (e) {
      if (e.target.getAttribute && e.target.getAttribute("data-close") != null) closeBook();
    });
    document.addEventListener("keydown", function (e) { if (e.key === "Escape") closeBook(); });
  }
  var bookForm = $("#bookForm");
  if (bookForm) bookForm.addEventListener("submit", function (e) {
    e.preventDefault();
    var name = val("#bookName"), phone = val("#bookPhone"), email = val("#bookEmail"), note = val("#bookNote");
    var out = $("#bookMsgOut");
    if (!name || !phone || !email) { out.className = "umodal__msg umodal__msg--bad"; out.textContent = "Please add your name, phone and email."; return; }
    var msg = ctxUni && ctxUni.name
      ? ("Counselling request — interested in " + ctxUni.name + (ctxUni.country ? " (" + ctxUni.country + ")" : "") + (note ? ". " + note : ""))
      : ("Counselling request from the universities page." + (note ? " " + note : ""));
    var btn = $("#bookSubmit"); if (btn) btn.disabled = true;
    out.className = "umodal__msg"; out.textContent = "Sending…";
    VFIApi.post("/api/contact", {
      fname: name, phone: phone, email: email, msg: msg,
      source_page: ctxUni && ctxUni.name ? ("university:" + ctxUni.name).slice(0, 180) : "universities.html"
    }).then(function () {
      out.className = "umodal__msg umodal__msg--ok";
      out.textContent = "Thanks! A VFI counsellor will contact you shortly.";
      bookForm.reset();
    }).catch(function () {
      out.className = "umodal__msg umodal__msg--bad";
      out.textContent = "Sorry, that didn't go through — please try again or use the Contact page.";
    }).then(function () { if (btn) btn.disabled = false; });
  });

  // hero / overview [data-apply] buttons use the page's current university
  document.addEventListener("click", function (e) {
    var a = closestAttr(e.target, "data-apply");
    if (a) { e.preventDefault(); openBook(window.__uniCtx || null); }
  });

  /* ------------------------------------------------------- directory page */
  if ($("#uniResults") && $("#uniSearch")) initDirectory();

  function initDirectory() {
    var state = { country: "", q: "", page: 1 };

    VFIApi.get("/api/universities/meta").then(function (m) {
      var sel = $("#uniCountry"); if (!sel) return;
      var list = (m && m.countries) || [], html = "", i;
      for (i = 0; i < list.length; i++) html += '<option value="' + esc(list[i].country) + '">' + esc(list[i].country) + ' (' + list[i].count + ')</option>';
      if (html) sel.insertAdjacentHTML("beforeend", html);
    }).catch(function () {});

    function load() {
      var qp = [];
      if (state.country) qp.push("country=" + encodeURIComponent(state.country));
      if (state.q) qp.push("q=" + encodeURIComponent(state.q));
      qp.push("page=" + state.page);
      $("#uniResults").innerHTML = '<div class="uni-msg">Loading universities…</div>';
      VFIApi.get("/api/universities?" + qp.join("&")).then(renderDir)
        .catch(function () { $("#uniResults").innerHTML = '<div class="uni-msg">Could not load universities. Please try again.</div>'; });
    }
    function renderDir(d) {
      var rows = (d && d.data) || [], meta = (d && d.meta) || {};
      $("#uniResCount").textContent = (meta.total || 0) + " universit" + (meta.total === 1 ? "y" : "ies");
      $("#uniResTitle").textContent = state.country ? ("Universities in " + state.country) : "All universities";
      var grid = $("#uniResults");
      if (!rows.length) { grid.innerHTML = '<div class="uni-msg">No universities found. Try another country or clear the search.</div>'; show("#uniPager", false); return; }
      var html = "", i; for (i = 0; i < rows.length; i++) html += uniCardHtml(rows[i]); grid.innerHTML = html;
      $("#uniPageInfo").textContent = "Page " + meta.page + " of " + meta.last_page;
      show("#uniPager", meta.last_page > 1);
      $("#uniPrev").disabled = meta.page <= 1; $("#uniNext").disabled = meta.page >= meta.last_page;
    }
    function run() { state.country = $("#uniCountry").value; state.q = val("#uniQ"); state.page = 1; load(); }
    function toTop() { var h = $("#uniResults"); if (h && h.scrollIntoView) h.scrollIntoView({ behavior: "smooth", block: "start" }); }

    $("#uniSearchBtn").addEventListener("click", run);
    $("#uniQ").addEventListener("keydown", function (e) { if (e.key === "Enter") run(); });
    $("#uniCountry").addEventListener("change", run);
    $("#uniPrev").addEventListener("click", function () { if (state.page > 1) { state.page--; load(); toTop(); } });
    $("#uniNext").addEventListener("click", function () { state.page++; load(); toTop(); });
    $("#uniResults").addEventListener("click", function (e) {
      var b = closestAttr(e.target, "data-apply-uni");
      if (b) { e.preventDefault(); openBook({ name: b.getAttribute("data-name"), country: b.getAttribute("data-country") }); }
    });

    // deep link: universities.html?country=United+Kingdom
    var pc = getParam("country");
    if (pc) { state.country = pc; setTimeout(function () { var s = $("#uniCountry"); if (s) s.value = pc; }, 300); }
    load();
  }

  /* ---------------------------------------------------------- detail page */
  if ($("#uniDetail")) initDetail();

  function initDetail() {
    var id = getParam("id"), wrap = $("#uniDetail");
    if (!id) { wrap.innerHTML = '<div class="uni-msg">No university selected. <a href="universities.html">Browse universities</a>.</div>'; return; }
    VFIApi.get("/api/universities/" + encodeURIComponent(id))
      .then(function (d) { renderDetail(d.university); })
      .catch(function () { wrap.innerHTML = '<div class="uni-msg">University not found. <a href="universities.html">Browse universities</a>.</div>'; });
  }
  function fact(k, v) { return '<div class="ufact"><div class="ufact__k">' + esc(k) + '</div><div class="ufact__v">' + esc(v) + '</div></div>'; }
  function courseHtml(c) {
    var fee = c.tuition ? money(c.tuition) : "";
    var meta = [cap(c.level)]; if (c.duration_band) meta.push(c.duration_band); if (c.study_area) meta.push(cap(c.study_area));
    var chips = ""; if (c.is_stem) chips += '<span class="uchip">STEM</span>'; if (c.scholarship_available) chips += '<span class="uchip uchip--coral">Scholarship</span>';
    return '<div class="ucourse"><div><div class="ucourse__t">' + esc(c.title) + '</div><div class="ucourse__m">' + esc(meta.join(" · ")) + '</div>'
      + (chips ? '<div class="uchips">' + chips + '</div>' : '') + '</div>'
      + (fee ? '<div class="ucourse__fee">' + fee + '</div>' : '') + '</div>';
  }
  function overviewText(u) {
    var s = u.stats || {};
    var loc = [u.city, u.country].filter(Boolean).join(", ");
    var t = u.name + (loc ? " is based in " + loc + "." : ".");
    if (s.programs) t += " It offers " + s.programs + " program" + (s.programs === 1 ? "" : "s")
      + ((s.levels && s.levels.length) ? " across " + s.levels.length + " study level" + (s.levels.length === 1 ? "" : "s") : "") + ".";
    if (s.scholarship_available) t += " Scholarships are available on selected programs.";
    if (u.is_major_city) t += " The campus sits in a major city.";
    t += " Talk to a VFI counsellor for an up-to-date shortlist, fees and intake dates.";
    return t;
  }
  function renderDetail(u) {
    window.__uniCtx = { name: u.name, country: u.country, id: u.id };
    document.title = u.name + " | VFI Overseas Education";
    if ($("#uniName")) $("#uniName").textContent = u.name;
    if ($("#uniCrumb")) $("#uniCrumb").textContent = u.name;
    if ($("#uniLoc")) $("#uniLoc").textContent = [u.city, u.province_state, u.country].filter(Boolean).join(", ");
    show("#uniApplyHero", true);

    var s = u.stats || {}, facts = "";
    facts += fact("Programs", s.programs || 0);
    facts += fact("Study levels", (s.levels || []).length);
    if (s.tuition_min != null) facts += fact("Tuition from", money({ minor: s.tuition_min, currency: s.tuition_currency }));
    facts += fact("Affordability", cap(u.affordability_band || "—"));
    facts += fact("Offer speed", tatText(u.offer_tat_band));
    facts += fact("Scholarships", s.scholarship_available ? "Available" : "Ask us");

    var areas = (s.study_areas || []).slice(0, 8).map(function (a) { return '<span class="uchip">' + esc(cap(a)) + '</span>'; }).join("");
    var courses = u.courses || [];

    var html = '<div class="udetail__facts">' + facts + '</div>';
    html += '<div class="info-card"><h3>About ' + esc(u.name) + '</h3><p>' + esc(overviewText(u)) + '</p>'
      + (areas ? '<div class="uchips">' + areas + '</div>' : '')
      + '<div style="margin-top:18px"><button class="btn btn--enquire" data-apply type="button">Apply — Book Free Counselling</button></div></div>';

    html += '<h2 class="sec-title" style="font-size:1.3rem;margin:30px 0 6px">Intakes &amp; entry requirements</h2>';
    html += '<p class="sec-lead" style="margin:0 0 18px">Intakes: '
      + ((s.seasons && s.seasons.length) ? s.seasons.map(cap).join(", ") : "ask a counsellor") + '. '
      + ((s.tests_required && s.tests_required.length) ? 'Common tests: ' + s.tests_required.map(function (t) { return t.toUpperCase(); }).join(", ") + '.' : '') + '</p>';

    html += '<h2 class="sec-title" style="font-size:1.3rem;margin:26px 0 12px">Top courses</h2><div class="info-card">';
    if (courses.length) { for (var i = 0; i < courses.length; i++) html += courseHtml(courses[i]); }
    else html += '<p class="ucourse__m">Full course list on request — ask a counsellor.</p>';
    html += '</div>';

    if (u.related && u.related.length) {
      html += '<h2 class="sec-title" style="font-size:1.3rem;margin:32px 0 14px">Other universities in ' + esc(u.country) + '</h2><div class="unicards">';
      for (var j = 0; j < u.related.length; j++) html += uniCardHtml(u.related[j]);
      html += '</div>';
    }

    var wrap = $("#uniDetail");
    wrap.innerHTML = html;
    wrap.addEventListener("click", function (e) {
      var b = closestAttr(e.target, "data-apply-uni");
      if (b) { e.preventDefault(); openBook({ name: b.getAttribute("data-name"), country: b.getAttribute("data-country") }); }
    });
  }
})();
