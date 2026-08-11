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
    var logoInner = r.logo ? '<img src="' + esc(r.logo) + '" alt="' + esc(r.name) + ' logo">' : esc(initials(r.name));
    var tags = "";
    if (r.is_major_city) tags += '<span class="unic__tag">Major city</span>';
    if (r.affordability_band === "low") tags += '<span class="unic__tag">Affordable</span>';
    if (r.offer_tat_band === "fast") tags += '<span class="unic__tag">Fast offers</span>';
    if (r.vfi_represented) tags += '<span class="unic__tag">VFI partner</span>';
    return '<article class="unic">'
      + '<div class="unic__logo unic__logo--' + variant + '">' + logoInner + '</div>'
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
    if ($("#uniCrumb")) $("#uniCrumb").textContent = u.name;

    var s = u.stats || {}, p = u.profile || {};

    // ---- hero: logo + name + tagline + location + apply ----
    var loc = [u.city, u.province_state, u.country].filter(Boolean).join(", ");
    var logo = u.logo
      ? '<span class="udetail__logo"><img src="' + esc(u.logo) + '" alt="' + esc(u.name) + ' logo"></span>'
      : '<span class="udetail__logo">' + esc(initials(u.name)) + '</span>';
    var hero = $("#uniHero");
    if (hero) hero.innerHTML =
      '<div class="udetail__hero-in">' + logo
      + '<div class="udetail__htext"><h1>' + esc(u.name) + '</h1>'
      + (u.tagline ? '<p class="udetail__tagline">' + esc(u.tagline) + '</p>' : '')
      + (loc ? '<p class="udetail__tagline">' + esc(loc) + '</p>' : '') + '</div></div>'
      + '<div style="margin-top:20px"><button class="btn btn--enquire btn--lg" data-apply type="button">Apply — Book Free Counselling</button></div>';
    if (u.hero) { var hb = document.querySelector(".page-hero__bg"); if (hb) hb.style.backgroundImage = "url('" + u.hero + "')"; }

    // ---- build the sections that have content ----
    var secs = [];
    function push(id, label, inner) { if (inner) secs.push({ id: id, label: label, inner: inner }); }

    var facts = fact("Programs", s.programs || 0) + fact("Study levels", (s.levels || []).length)
      + (s.tuition_min != null ? fact("Tuition from", money({ minor: s.tuition_min, currency: s.tuition_currency })) : "")
      + fact("Affordability", cap(u.affordability_band || "—")) + fact("Offer speed", tatText(u.offer_tat_band))
      + fact("Scholarships", s.scholarship_available ? "Available" : "Ask us");
    var areas = (s.study_areas || []).slice(0, 10).map(function (a) { return '<span class="uchip">' + esc(cap(a)) + '</span>'; }).join("");
    push("overview", "Overview",
      '<div class="udetail__facts">' + facts + '</div>'
      + '<p class="unote">' + esc(p.overview || overviewText(u)) + '</p>'
      + (areas ? '<div class="uchips" style="margin-top:12px">' + areas + '</div>' : ''));

    if (p.ranking && (p.ranking.world || p.ranking.national || p.ranking.note)) {
      var rc = "";
      if (p.ranking.world) rc += '<div class="urank__card"><div class="urank__n">' + esc(p.ranking.world) + '</div><div class="urank__k">World rank</div></div>';
      if (p.ranking.national) rc += '<div class="urank__card"><div class="urank__n">' + esc(p.ranking.national) + '</div><div class="urank__k">National rank</div></div>';
      push("ranking", "Ranking", '<div class="urank">' + rc + '</div>' + (p.ranking.note ? '<p class="unote" style="margin-top:12px">' + esc(p.ranking.note) + '</p>' : ''));
    }

    push("intakes", "Intakes",
      '<p class="unote unote--card">Intakes: <b>' + esc((s.seasons && s.seasons.length) ? s.seasons.map(cap).join(", ") : "ask a counsellor") + '</b>.'
      + ((s.tests_required && s.tests_required.length) ? ' Common tests: ' + esc(s.tests_required.map(function (t) { return t.toUpperCase(); }).join(", ")) + '.' : '') + '</p>');

    var courses = u.courses || [], cl = "";
    if (courses.length) { for (var i = 0; i < courses.length; i++) cl += courseHtml(courses[i]); }
    else cl = '<p class="ucourse__m">Full course list on request — ask a counsellor.</p>';
    push("courses", "Courses", '<div class="info-card">' + cl + '</div>');

    var costInner = "";
    if (s.tuition_min != null) costInner += '<p class="unote">Tuition from <b>' + money({ minor: s.tuition_min, currency: s.tuition_currency }) + '</b>'
      + (s.tuition_max && s.tuition_max !== s.tuition_min ? ' up to <b>' + money({ minor: s.tuition_max, currency: s.tuition_currency }) + '</b>' : '') + ' per year.</p>';
    if (p.cost && p.cost.note) costInner += '<p class="unote">' + esc(p.cost.note) + '</p>';
    var costFacts = "";
    if (p.cost && p.cost.living) costFacts += fact("Living cost", p.cost.living);
    if (p.cost && p.cost.accommodation) costFacts += fact("Accommodation", p.cost.accommodation);
    if (costFacts) costInner += '<div class="udetail__facts">' + costFacts + '</div>';
    if (!costInner) costInner = '<p class="unote">Ask a VFI counsellor for a full cost breakdown.</p>';
    push("cost", "Cost to Study", costInner);

    var schols = p.scholarships || [];
    if (schols.length) {
      var sc = schols.map(function (x) {
        return '<div class="uschol__card"><div class="uschol__name">' + esc(x.name || "Scholarship") + '</div>'
          + (x.amount ? '<div class="uschol__amt">' + esc(x.amount) + '</div>' : '')
          + (x.level ? '<div class="uschol__note">' + esc(x.level) + '</div>' : '')
          + (x.note ? '<div class="uschol__note">' + esc(x.note) + '</div>' : '') + '</div>';
      }).join("");
      push("scholarships", "Scholarships", '<div class="uschol">' + sc + '</div>');
    } else if (s.scholarship_available) {
      push("scholarships", "Scholarships", '<p class="unote unote--card">Scholarships are available on selected programs. Ask a counsellor which ones you qualify for.</p>');
    }

    var adm = "";
    if (p.admission && p.admission.academic) adm += '<h4 style="margin:0 0 6px">Academic</h4><p class="unote" style="margin-bottom:14px">' + esc(p.admission.academic) + '</p>';
    if (p.admission && p.admission.english) adm += '<h4 style="margin:0 0 6px">English</h4><p class="unote">' + esc(p.admission.english) + '</p>';
    if (!adm && s.tests_required && s.tests_required.length) adm = '<p class="unote unote--card">Accepted entry tests: ' + esc(s.tests_required.map(function (t) { return t.toUpperCase(); }).join(", ")) + '. Ask a counsellor for the exact scores per course.</p>';
    if (adm) push("admissions", "Admissions", adm);

    if (p.placement && (p.placement.note || p.placement.salary || (p.placement.recruiters && p.placement.recruiters.length))) {
      var pl = "";
      if (p.placement.note) pl += '<p class="unote">' + esc(p.placement.note) + '</p>';
      if (p.placement.salary) pl += '<p class="unote"><b>Average salary:</b> ' + esc(p.placement.salary) + '</p>';
      if (p.placement.recruiters && p.placement.recruiters.length) pl += '<div class="urec" style="margin-top:10px">' + p.placement.recruiters.map(function (r) { return '<span class="urec__chip">' + esc(r) + '</span>'; }).join("") + '</div>';
      push("placements", "Placements", pl);
    }

    if (p.gallery && p.gallery.length) {
      push("gallery", "Gallery", '<div class="ugallery">' + p.gallery.map(function (g) { return '<img src="' + esc(g) + '" alt="' + esc(u.name) + '" loading="lazy">'; }).join("") + '</div>');
    }

    if (p.faqs && p.faqs.length) {
      push("faqs", "FAQs", p.faqs.map(function (f) {
        return '<details class="ufaq"><summary>' + esc(f.q || "") + '</summary><div class="ufaq__a">' + esc(f.a || "") + '</div></details>';
      }).join(""));
    }

    var nav = '<nav class="udetail__nav"><ul>' + secs.map(function (x) { return '<li><a href="#usec-' + x.id + '">' + esc(x.label) + '</a></li>'; }).join("") + '</ul></nav>';
    var body = secs.map(function (x) { return '<section class="usec" id="usec-' + x.id + '"><h2 class="usec__title">' + esc(x.label) + '</h2>' + x.inner + '</section>'; }).join("");
    if (u.related && u.related.length) {
      body += '<section class="usec"><h2 class="usec__title">Other universities in ' + esc(u.country) + '</h2><div class="unicards">'
        + u.related.map(uniCardHtml).join("") + '</div></section>';
    }

    var wrap = $("#uniDetail");
    wrap.innerHTML = nav + body;
    wrap.addEventListener("click", function (e) {
      var b = closestAttr(e.target, "data-apply-uni");
      if (b) { e.preventDefault(); openBook({ name: b.getAttribute("data-name"), country: b.getAttribute("data-country") }); }
    });
    spy(wrap);
  }

  function spy(wrap) {
    var links = Array.prototype.slice.call(wrap.querySelectorAll(".udetail__nav a"));
    var secEls = links.map(function (a) { return document.getElementById(a.getAttribute("href").slice(1)); });
    function onScroll() {
      var y = window.pageYOffset + 150, active = 0;
      for (var i = 0; i < secEls.length; i++) { if (secEls[i] && secEls[i].offsetTop <= y) active = i; }
      for (var j = 0; j < links.length; j++) links[j].classList.toggle("is-active", j === active);
    }
    window.addEventListener("scroll", onScroll, { passive: true });
    onScroll();
  }
})();
