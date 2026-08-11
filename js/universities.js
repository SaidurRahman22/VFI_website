/* =====================================================================
   VFI — public university directory + full detail template
   (Phase 8, student side).

   universities.html  search by country → cards → Apply / Know More
   university.html    the full detail template: hero identity card,
                      sticky section nav, and the sections
                      Overview · Ranking · Intakes · Courses ·
                      Cost to Study · Scholarships · Admissions ·
                      Placements · Life on campus · Gallery · FAQs,
                      plus a sticky lead form and a closing CTA band.

   Catalogue data (courses, intakes, fees, tests) comes from the live
   ingest; the editorial blocks (ranking, cost table, scholarships,
   admissions tabs, placements, services, gallery, FAQs) are authored by
   staff in the admin. A section only renders when it has content.

   Public endpoints (no auth):
     GET /api/universities/meta         countries for the dropdown
     GET /api/universities?country=&q=  paged directory
     GET /api/universities/{id}         detail
   Leads post to POST /api/contact — the same public intake as the
   contact form — which connects the student with a VFI agent.

   ES5 only: var / function / string concat.
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
  function initials(name) {
    var w = String(name || "").replace(/[^A-Za-z ]/g, "").split(" "), out = "";
    for (var i = 0; i < w.length && out.length < 2; i++) { if (w[i]) out += w[i].charAt(0).toUpperCase(); }
    return out || "U";
  }

  /* level code → display label, and the order tabs appear in */
  var LEVEL_LABEL = {
    master: "Masters", mba: "MBA", bachelor: "Bachelors", bachelor_honours: "Bachelors (Hons)",
    phd: "PhD", mphil: "MPhil", pg_diploma: "PG Diploma", pg_certificate: "PG Certificate",
    grad_diploma: "Graduate Diploma", grad_certificate: "Graduate Certificate",
    diploma: "Diploma", advanced_diploma: "Advanced Diploma", associate: "Associate",
    foundation: "Foundation", pathway: "Pathway", integrated_master: "Integrated Masters"
  };
  var LEVEL_ORDER = ["master", "mba", "bachelor", "bachelor_honours", "phd", "pg_diploma",
    "pg_certificate", "diploma", "associate", "foundation", "pathway"];
  function levelLabel(l) { return LEVEL_LABEL[l] || cap(String(l || "").replace(/_/g, " ")); }

  /* ---------------------------------------- shared university card markup */
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
      + '<p style="margin:10px 0 0"><span class="unic__prog"><svg class="ic ic--sm"><use href="#i-book"/></svg> '
        + r.programs + ' program' + (r.programs === 1 ? '' : 's') + '</span></p>'
      + '<div class="unic__cta">'
      + '<a href="university.html?id=' + r.id + '" class="btn btn--outline btn--sm">Know More</a>'
      + '<button type="button" class="btn btn--enquire btn--sm" data-apply-uni data-name="' + esc(r.name) + '" data-country="' + esc(r.country) + '">Apply Now</button>'
      + '</div></article>';
  }

  /* -------------------------------------------- Book Free Counselling modal */
  var modal = $("#bookModal");
  var ctxUni = null;

  function openBook(ctx) {
    ctxUni = ctx || window.__uniCtx || null;
    var ctxEl = $("#bookCtx");
    if (ctxEl) {
      if (ctxUni && ctxUni.name) {
        ctxEl.innerHTML = 'Enquiry about <b>' + esc(ctxUni.name) + '</b>' + (ctxUni.country ? ' · ' + esc(ctxUni.country) : '');
        ctxEl.hidden = false;
      } else ctxEl.hidden = true;
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

  /* post a counselling lead; `extra` is appended to the message */
  function sendLead(fields, outSel, btnSel, onDone) {
    var out = $(outSel), btn = $(btnSel);
    if (!fields.name || !fields.phone || !fields.email) {
      out.className = "umodal__msg umodal__msg--bad";
      out.textContent = "Please add your name, phone and email.";
      return;
    }
    var uni = window.__uniCtx;
    var msg = "Counselling request";
    if (fields.interest) msg += " — interested in " + fields.interest;
    if (uni && uni.name) msg += " — university: " + uni.name + (uni.country ? " (" + uni.country + ")" : "");
    if (fields.note) msg += ". " + fields.note;
    if (btn) btn.disabled = true;
    out.className = "umodal__msg"; out.textContent = "Sending…";
    VFIApi.post("/api/contact", {
      fname: fields.name, phone: fields.phone, email: fields.email, msg: msg,
      source_page: (uni && uni.name ? ("university:" + uni.name) : "universities.html").slice(0, 180)
    }).then(function () {
      out.className = "umodal__msg umodal__msg--ok";
      out.textContent = "Thanks! A VFI counsellor will contact you shortly.";
      if (onDone) onDone();
    }).catch(function () {
      out.className = "umodal__msg umodal__msg--bad";
      out.textContent = "Sorry, that didn't go through — please try again or use the Contact page.";
    }).then(function () { if (btn) btn.disabled = false; });
  }

  var bookForm = $("#bookForm");
  if (bookForm) bookForm.addEventListener("submit", function (e) {
    e.preventDefault();
    sendLead({ name: val("#bookName"), phone: val("#bookPhone"), email: val("#bookEmail"), note: val("#bookNote") },
      "#bookMsgOut", "#bookSubmit", function () { bookForm.reset(); });
  });

  var leadForm = $("#leadForm");
  if (leadForm) leadForm.addEventListener("submit", function (e) {
    e.preventDefault();
    sendLead({ name: val("#leadName"), phone: val("#leadPhone"), email: val("#leadEmail"), interest: val("#leadLevel") },
      "#leadMsgOut", "#leadSubmit", function () { leadForm.reset(); });
  });

  // any [data-apply] button opens the modal for the current university
  document.addEventListener("click", function (e) {
    var a = closestAttr(e.target, "data-apply");
    if (a) { e.preventDefault(); openBook(null); }
  });

  /* ======================================================= DIRECTORY PAGE */
  if ($("#uniResults") && $("#uniSearch")) initDirectory();

  function initDirectory() {
    var state = { country: "", q: "", page: 1 };

    VFIApi.get("/api/universities/meta").then(function (m) {
      var sel = $("#uniCountry"); if (!sel) return;
      var list = (m && m.countries) || [], html = "", i;
      for (i = 0; i < list.length; i++) {
        html += '<option value="' + esc(list[i].country) + '">' + esc(list[i].country) + ' (' + list[i].count + ')</option>';
      }
      if (html) sel.insertAdjacentHTML("beforeend", html);
      var pc = getParam("country"); if (pc) sel.value = pc;
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
      if (!rows.length) {
        grid.innerHTML = '<div class="uni-msg">No universities found. Try another country or clear the search.</div>';
        show("#uniPager", false); return;
      }
      var html = "", i; for (i = 0; i < rows.length; i++) html += uniCardHtml(rows[i]);
      grid.innerHTML = html;
      $("#uniPageInfo").textContent = "Page " + meta.page + " of " + meta.last_page;
      show("#uniPager", meta.last_page > 1);
      $("#uniPrev").disabled = meta.page <= 1;
      $("#uniNext").disabled = meta.page >= meta.last_page;
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

    var pc = getParam("country"); if (pc) state.country = pc;
    load();
  }

  /* ========================================================== DETAIL PAGE */
  if ($("#uniDetail") && !$("#uniResults")) initDetail();

  function initDetail() {
    var id = getParam("id"), wrap = $("#uniDetail");
    if (!id) {
      wrap.innerHTML = '<div class="uni-msg">No university selected. <a href="universities.html">Browse universities</a>.</div>';
      return;
    }
    VFIApi.get("/api/universities/" + encodeURIComponent(id))
      .then(function (d) { renderDetail(d.university); })
      .catch(function () { wrap.innerHTML = '<div class="uni-msg">University not found. <a href="universities.html">Browse universities</a>.</div>'; });
  }

  /* intake seasons — month, a short honest note, and a photo already on the site */
  var SEASON = {
    fall: { month: "September", img: "assets/img/campus.jpg",
      note: "The main intake. The widest choice of courses and scholarships — start 8–12 months ahead." },
    spring: { month: "January", img: "assets/img/students-group.jpg",
      note: "The second intake. A good option if you need more time for tests, documents or funds." },
    summer: { month: "May", img: "assets/img/students-friends.jpg",
      note: "A smaller intake on selected courses, often pathway and short programs." },
    winter: { month: "November", img: "assets/img/library.jpg",
      note: "A limited intake on selected courses. Ask a counsellor which programs are open." }
  };
  function seasonKey(name) {
    var n = String(name || "").toLowerCase();
    for (var k in SEASON) { if (SEASON.hasOwnProperty(k) && n.indexOf(k) !== -1) return k; }
    if (n.indexOf("autumn") !== -1) return "fall";
    return "fall";
  }
  function intakeCard(name, month, note, key) {
    var img = (SEASON[key] || SEASON.fall).img;
    return '<article class="uintake">'
      + '<div class="uintake__img" style="background-image:url(\'' + esc(img) + '\')"></div>'
      + '<div class="uintake__body"><h3 class="uintake__name">' + esc(name) + '</h3>'
      + (month ? '<span class="uintake__month">' + esc(month) + '</span>' : '')
      + (note ? '<p class="uintake__note">' + esc(note) + '</p>' : '')
      + '</div></article>';
  }

  /* ---- small builders ---- */
  function statTile(v, k) { return '<div class="ustat"><div class="ustat__v">' + esc(v) + '</div><div class="ustat__k">' + esc(k) + '</div></div>'; }
  function rankCard(n, k) { return '<div class="urank__card"><div class="urank__n">' + esc(n) + '</div><div class="urank__k">' + esc(k) + '</div></div>'; }
  function tableHtml(head1, head2, rows) {
    var body = rows.map(function (r) { return '<tr><td>' + esc(r[0]) + '</td><td>' + esc(r[1]) + '</td></tr>'; }).join("");
    return '<div class="utable-wrap"><table class="utable"><thead><tr><th>' + esc(head1) + '</th><th>' + esc(head2)
      + '</th></tr></thead><tbody>' + body + '</tbody></table></div>';
  }
  function accItem(title, body) {
    return '<details class="uacc"><summary>' + esc(title) + '</summary><div class="uacc__b">' + esc(body || "") + '</div></details>';
  }
  function courseRow(c) {
    var meta = [levelLabel(c.level)];
    if (c.duration_band) meta.push(String(c.duration_band).replace(/_/g, " "));
    if (c.study_area) meta.push(cap(String(c.study_area).replace(/_/g, " ")));
    var chips = "";
    if (c.is_stem) chips += '<span class="uchip">STEM</span>';
    if (c.scholarship_available) chips += '<span class="uchip uchip--coral">Scholarship</span>';
    // the US feed ends CIP titles with a full stop — drop it for display
    var title = String(c.title || "").replace(/\s*\.\s*$/, "");
    return '<div class="ucourse"><div class="ucourse__info"><div class="ucourse__t">' + esc(title) + '</div>'
      + '<div class="ucourse__m">' + esc(meta.join(" · ")) + '</div>'
      + (chips ? '<div class="uchips" style="margin-top:6px">' + chips + '</div>' : '') + '</div>'
      + '<div class="ucourse__fee">' + (c.tuition ? money(c.tuition) : '<span class="ucourse__na">On request</span>') + '</div>'
      + '</div>';
  }
  function autoOverview(u) {
    var s = u.stats || {};
    var loc = [u.city, u.country].filter(Boolean).join(", ");
    var t = u.name + (loc ? " is based in " + loc + "." : ".");
    if (s.programs) {
      t += " It offers " + s.programs + " program" + (s.programs === 1 ? "" : "s")
        + ((s.levels && s.levels.length) ? " across " + s.levels.length + " study level" + (s.levels.length === 1 ? "" : "s") : "") + ".";
    }
    if (s.scholarship_available) t += " Scholarships are available on selected programs.";
    t += " Talk to a VFI counsellor for an up-to-date shortlist, fees and intake dates.";
    return t;
  }

  function renderDetail(u) {
    window.__uniCtx = { name: u.name, country: u.country, id: u.id };
    document.title = u.name + " | VFI Overseas Education";
    var s = u.stats || {}, p = u.profile || {};
    if ($("#uniCrumb")) $("#uniCrumb").textContent = u.name;

    /* ---- hero identity card ---- */
    if (u.hero) { var bn = $("#uniBanner"); if (bn) bn.style.backgroundImage = "url('" + u.hero + "')"; }
    var loc = [u.city, u.province_state, u.country].filter(Boolean).join(", ");
    var sub = [];
    if (u.tagline) sub.push('<span>' + esc(u.tagline) + '</span>');
    if (loc) sub.push('<span><svg class="ic ic--sm"><use href="#i-pin"/></svg> ' + esc(loc) + '</span>');
    if (u.website) sub.push('<a href="' + esc(u.website) + '" target="_blank" rel="noopener nofollow">' + esc(String(u.website).replace(/^https?:\/\//, "")) + '</a>');
    var hero = $("#uniHero");
    if (hero) hero.innerHTML =
      '<span class="uhero__logo">' + (u.logo ? '<img src="' + esc(u.logo) + '" alt="' + esc(u.name) + ' logo">' : esc(initials(u.name))) + '</span>'
      + '<div class="uhero__txt"><h1>' + esc(u.name) + '</h1>'
      + (sub.length ? '<div class="uhero__sub">' + sub.join("") + '</div>' : '') + '</div>'
      + '<div class="uhero__cta"><button class="btn btn--enquire btn--lg" data-apply type="button">Apply with VFI</button></div>';

    /* ---- sidebar + CTA band ---- */
    var lt = $("#uniLeadTitle"); if (lt) lt.textContent = "Want to study in " + (u.country || "abroad") + "?";
    var ct = $("#uniCtaTitle"); if (ct) ct.textContent = "Start your journey at " + u.name;
    show("#uniCta", true);

    /* ---- sections ---- */
    var secs = [];
    function push(id, label, inner) { if (inner) secs.push({ id: id, label: label, inner: inner }); }

    // Overview — about + stat tiles
    var tiles = (p.stats || []).map(function (t) { return statTile(t.value, t.label); }).join("");
    push("overview", "Overview",
      '<div class="upanel"><p class="unote">' + esc(p.overview || autoOverview(u)) + '</p>'
      + (tiles ? '<div class="ustats">' + tiles + '</div>' : '') + '</div>');

    // Ranking
    var rk = (p.rankings || []).map(function (r) { return rankCard(r.rank, r.by); }).join("");
    if (!rk && p.ranking) {
      if (p.ranking.world) rk += rankCard(p.ranking.world, "World rank");
      if (p.ranking.national) rk += rankCard(p.ranking.national, "National rank");
    }
    if (rk) push("ranking", "Ranking", '<div class="urank">' + rk + '</div>'
      + (p.ranking && p.ranking.note ? '<p class="unote" style="margin-top:12px">' + esc(p.ranking.note) + '</p>' : ''));

    // Intakes — card grid; editorial blocks if authored, else from the catalogue
    var ib = p.intake_blocks || [], intakeInner = "";
    if (ib.length) {
      intakeInner = '<div class="uintakes">' + ib.map(function (b) {
        return intakeCard(b.name, "", b.note || "", seasonKey(b.name));
      }).join("") + '</div>';
    } else if (s.seasons && s.seasons.length) {
      intakeInner = '<div class="uintakes">' + s.seasons.map(function (sn) {
        var m = SEASON[sn] || {};
        return intakeCard(cap(sn) + " intake", m.month || "", m.note || "", sn);
      }).join("") + '</div>'
        + '<p class="unote" style="margin-top:12px">Applications open several months ahead — a counsellor can confirm the exact deadline for your course.</p>';
    }
    push("intakes", "Intakes", intakeInner);

    // Courses — tabbed by level, from the real catalogue
    var courses = u.courses || [], byLevel = {}, i;
    for (i = 0; i < courses.length; i++) {
      var lv = courses[i].level || "other";
      (byLevel[lv] = byLevel[lv] || []).push(courses[i]);
    }
    var levels = Object.keys(byLevel).sort(function (a, b) {
      var ia = LEVEL_ORDER.indexOf(a), ib2 = LEVEL_ORDER.indexOf(b);
      return (ia < 0 ? 99 : ia) - (ib2 < 0 ? 99 : ib2);
    });
    if (levels.length) {
      var tabs = "", panels = "";
      for (i = 0; i < levels.length; i++) {
        var lvl = levels[i];
        tabs += '<button type="button" class="utab' + (i === 0 ? ' is-on' : '') + '" data-tab="c-' + esc(lvl) + '">'
          + esc(levelLabel(lvl)) + ' (' + byLevel[lvl].length + ')</button>';
        panels += '<div class="utabpanel" data-panel="c-' + esc(lvl) + '"' + (i === 0 ? '' : ' hidden') + '>'
          + '<div class="upanel ucourses"><div class="ucourses__scroll">'
          + byLevel[lvl].map(function (c) { return courseRow(c); }).join("") + '</div></div>'
          + (byLevel[lvl].length > 10 ? '<p class="ucourses__hint">Showing all ' + byLevel[lvl].length + ' courses — scroll the list above.</p>' : '')
          + '</div>';
      }
      push("courses", "Courses", '<div class="utabgroup"><div class="utabs">' + tabs + '</div>' + panels + '</div>');
    } else {
      push("courses", "Courses", '<p class="unote unote--card">Full course list on request — ask a counsellor.</p>');
    }

    // Cost to Study — narrative, expenses table, footnote
    var cur = s.tuition_currency || "";
    var costInner = '<p class="unote">' + esc(p.cost && p.cost.note ? p.cost.note
      : ("The cost of studying at " + u.name + " has two parts: tuition for your course, and living costs while you are there — "
        + "housing and food, books and materials, local travel, health cover and personal spending. Tuition varies by course and level, "
        + "so use the figures below as a planning guide and ask a counsellor for the exact cost of the courses on your shortlist.")) + '</p>';

    var rows = (p.cost_rows || []).map(function (r) { return [r.label, r.value]; });
    if (!rows.length) {
      if (s.tuition_min != null) rows.push(["Annual tuition fee (from)", money({ minor: s.tuition_min, currency: cur })]);
      if (s.tuition_max != null && s.tuition_max !== s.tuition_min) rows.push(["Annual tuition fee (up to)", money({ minor: s.tuition_max, currency: cur })]);
      if (p.cost && p.cost.living) rows.push(["Living expenses", p.cost.living]);
      if (p.cost && p.cost.accommodation) rows.push(["Housing & food", p.cost.accommodation]);
    }
    if (rows.length) {
      costInner += tableHtml("Types of expenses", "Annual expenses" + (cur ? " in " + cur : ""), rows);
      costInner += '<p class="ucost__note">Note: these figures are approximate and change year to year. '
        + 'Check the current fee schedule on the university’s official website, or ask your VFI counsellor for the latest breakdown.</p>';
    } else {
      costInner += '<p class="unote unote--card" style="margin-top:14px">Ask a VFI counsellor for a full cost breakdown for this university.</p>';
    }
    push("cost", "Cost to Study", costInner);

    // Scholarships
    var schols = p.scholarships || [], scholInner = "";
    if (schols.length) {
      scholInner = '<div class="uschol">' + schols.map(function (x) {
        var meta = [x.level, x.note].filter(Boolean).join(" · ");
        return '<div class="uschol__card"><div><div class="uschol__name">' + esc(x.name || "Scholarship") + '</div>'
          + (meta ? '<div class="uschol__meta">' + esc(meta) + '</div>' : '') + '</div>'
          + '<div style="display:flex;align-items:center;gap:14px">'
          + (x.amount ? '<span class="uschol__amt">' + esc(x.amount) + '</span>' : '')
          + '<button type="button" class="btn btn--outline btn--sm" data-apply>View &amp; Apply</button></div></div>';
      }).join("") + '</div>';
    } else if (s.scholarship_available) {
      scholInner = '<p class="unote unote--card">Scholarships are available on selected programs at ' + esc(u.name)
        + '. Ask a counsellor which ones you qualify for.</p>';
    }
    push("scholarships", "Scholarships", scholInner);

    // Admissions — tabs per level, else a single block
    var adms = p.admissions || [], admInner = "";
    if (adms.length) {
      var atabs = "", apanels = "";
      for (i = 0; i < adms.length; i++) {
        var a = adms[i], key = "a-" + i;
        atabs += '<button type="button" class="utab' + (i === 0 ? ' is-on' : '') + '" data-tab="' + key + '">' + esc(a.level || ("Level " + (i + 1))) + '</button>';
        var blocks = "";
        if (a.academic) blocks += '<p class="usub">Academic requirements</p><p class="unote" style="margin-bottom:14px">' + esc(a.academic) + '</p>';
        if (a.english) blocks += '<p class="usub">English proficiency</p><p class="unote" style="margin-bottom:14px">' + esc(a.english) + '</p>';
        if (a.tests) blocks += '<p class="usub">Standardised tests</p><p class="unote">' + esc(a.tests) + '</p>';
        apanels += '<div class="utabpanel" data-panel="' + key + '"' + (i === 0 ? '' : ' hidden') + '><div class="upanel">' + blocks + '</div></div>';
      }
      admInner = '<div class="utabgroup"><div class="utabs">' + atabs + '</div>' + apanels + '</div>';
    } else {
      var fb = "";
      if (p.admission && p.admission.academic) fb += '<p class="usub">Academic requirements</p><p class="unote" style="margin-bottom:14px">' + esc(p.admission.academic) + '</p>';
      if (p.admission && p.admission.english) fb += '<p class="usub">English proficiency</p><p class="unote">' + esc(p.admission.english) + '</p>';
      if (!fb && s.tests_required && s.tests_required.length) {
        fb = '<p class="usub">Accepted entry tests</p><p class="unote">'
          + esc(s.tests_required.map(function (t) { return t.toUpperCase(); }).join(", "))
          + '. A counsellor can confirm the exact score each course needs.</p>';
      }
      if (fb) admInner = '<div class="upanel">' + fb + '</div>';
    }
    push("admissions", "Admissions", admInner);

    // Placements — rate, note, recruiters, jobs table
    var pl = p.placement || {}, plInner = "";
    if (pl.rate) plInner += '<div class="ustats" style="margin:0 0 16px"><div class="ustat"><div class="ustat__v">' + esc(pl.rate) + '</div><div class="ustat__k">Placement rate</div></div></div>';
    if (pl.note) plInner += '<p class="unote">' + esc(pl.note) + '</p>';
    if (pl.alumni) plInner += '<p class="unote">' + esc(pl.alumni) + '</p>';
    if (pl.salary) plInner += '<p class="unote"><b>Average salary:</b> ' + esc(pl.salary) + '</p>';
    if (pl.recruiters && pl.recruiters.length) {
      plInner += '<p class="usub" style="margin-top:16px">Top recruiters</p><div class="urec">'
        + pl.recruiters.map(function (r) { return '<span class="urec__chip">' + esc(r) + '</span>'; }).join("") + '</div>';
    }
    if (pl.jobs && pl.jobs.length) {
      plInner += '<p class="usub" style="margin:18px 0 8px">Jobs after graduating</p>'
        + tableHtml("Job profile", "Average salary", pl.jobs.map(function (j) { return [j.profile, j.salary]; }));
    }
    if (plInner && !/utable-wrap|urec/.test(plInner)) plInner = '<div class="upanel">' + plInner + '</div>';
    push("placements", "Placements", plInner);

    // Life on campus — services accordion
    var svcs = p.services || [];
    if (svcs.length) {
      push("life", "Life on campus", svcs.map(function (x) { return accItem(x.title, x.body); }).join(""));
    }

    // Gallery
    if (p.gallery && p.gallery.length) {
      push("gallery", "Gallery", '<div class="ugallery">'
        + p.gallery.map(function (g) { return '<img src="' + esc(g) + '" alt="' + esc(u.name) + '" loading="lazy">'; }).join("") + '</div>');
    }

    // FAQs — staff-authored, else a generic VFI set (no invented facts)
    var faqs = (p.faqs && p.faqs.length) ? p.faqs : [
      { q: "Is there an application fee?", a: "It varies by course. Your VFI counsellor will confirm the fee for each course on your shortlist and tell you if a fee waiver applies." },
      { q: "What are the English language requirements?", a: (s.tests_required && s.tests_required.length)
        ? ("This university accepts " + s.tests_required.map(function (t) { return t.toUpperCase(); }).join(", ") + ". The score you need depends on the course — ask a counsellor for the exact requirement.")
        : "Requirements depend on the course. Ask a counsellor which test and score your shortlist needs." },
      { q: "When should I apply?", a: "Apply as early as you can. Places and scholarships are limited and popular courses close before the published deadline." },
      { q: "Can VFI help with my application and visa?", a: "Yes. VFI supports you end to end — shortlisting, application, documents, scholarships and visa guidance. Counselling is free." }
    ];
    push("faqs", "FAQs", faqs.map(function (f) { return accItem(f.q, f.a); }).join(""));

    /* ---- paint ---- */
    var navList = $("#uniNavList");
    if (navList) {
      navList.innerHTML = secs.map(function (x) { return '<li><a href="#usec-' + x.id + '">' + esc(x.label) + '</a></li>'; }).join("");
      show("#uniNav", true);
    }
    var body = secs.map(function (x) {
      return '<section class="usec" id="usec-' + x.id + '"><h2 class="usec__title">' + esc(x.label) + '</h2>' + x.inner + '</section>';
    }).join("");
    if (u.related && u.related.length) {
      body += '<section class="usec"><h2 class="usec__title">Related universities</h2><div class="unicards">'
        + u.related.map(uniCardHtml).join("") + '</div></section>';
    }

    var wrap = $("#uniDetail");
    wrap.innerHTML = body;

    // delegated: apply buttons + tab switching
    wrap.addEventListener("click", function (e) {
      var b = closestAttr(e.target, "data-apply-uni");
      if (b) {
        e.preventDefault();
        openBook({ name: b.getAttribute("data-name") || u.name, country: u.country });
        return;
      }
      var t = closestAttr(e.target, "data-tab");
      if (t) {
        e.preventDefault();
        var group = t.parentNode.parentNode, key = t.getAttribute("data-tab");
        $$(".utab", group).forEach(function (x) { x.classList.toggle("is-on", x === t); });
        $$(".utabpanel", group).forEach(function (pn) { pn.hidden = pn.getAttribute("data-panel") !== key; });
      }
    });

    spy();
  }

  /* sticky-nav scrollspy */
  function spy() {
    var links = $$("#uniNavList a");
    if (!links.length) return;
    var targets = links.map(function (a) { return document.getElementById(a.getAttribute("href").slice(1)); });
    function onScroll() {
      var y = window.pageYOffset + 140, active = 0;
      for (var i = 0; i < targets.length; i++) { if (targets[i] && targets[i].offsetTop <= y) active = i; }
      for (var j = 0; j < links.length; j++) links[j].classList.toggle("is-active", j === active);
    }
    window.addEventListener("scroll", onScroll, { passive: true });
    onScroll();
  }
})();
