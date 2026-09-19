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
  // Zero is a FACT here, not a gap. DaadSource::tuitionMinor() returns 0 only
  // when the feed says "none", "no tuition" or "free" and null when it has no
  // figure, so a zero can be stated in words - and most German public
  // universities in the catalogue are exactly that case. "EUR 0" was true but
  // read like a broken number.
  //
  // Returns TEXT, never markup: tableHtml() escapes cell values, so a <span>
  // here would appear literally in the Cost to Study table.
  function money(t) {
    if (!t || t.minor == null) return "";
    if (+t.minor === 0) return "No tuition fee";
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
    /* Prefer the university the visitor actually clicked (ctxUni, set by
       openBook from a directory card) over the page-level one. Reading only
       window.__uniCtx meant every lead raised from the universities LIST — where
       no page-level context exists — reached staff with no university attached,
       so nobody knew what the enquiry was about. */
    var uni = ctxUni || window.__uniCtx;
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
      .then(function (d) { CMS = d.defaults || {}; applyLeadOptions(); renderDetail(d.university); })
      .catch(function () { wrap.innerHTML = '<div class="uni-msg">University not found. <a href="universities.html">Browse universities</a>.</div>'; });
  }

  /* Staff-owned copy served by the API (/manage → University page defaults).
     DEFAULTS below is only the safety net used before anything is authored. */
  var CMS = {};
  function cmsText(key, fallback) {
    var v = CMS[key];
    return (typeof v === "string" && v.trim() !== "") ? v : fallback;
  }
  function fill(t, u) { return String(t || "").replace(/\{university\}/g, u); }

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
  /* season card data: what staff set in the admin wins, else the built-in */
  function season(key) {
    var cms = (CMS.seasons || {})[key] || {}, base = SEASON[key] || SEASON.fall;
    return {
      month: cms.month || base.month,
      note: cms.note || base.note,
      img: cms.image || base.img
    };
  }
  /* The same allow-list the server applies when an image id is saved. Mirrored
     here for the same reason js/render.js mirrors it: the browser must not
     assume a clean database - rows written before that guard existed are
     already stored, and `content:import` copies legacy JSON in verbatim.
     Anything not on the list paints nothing rather than being trusted. */
  var U_IMG_MANAGED = /^\/storage\/media\/[0-9a-f]{64}\.(?:jpe?g|png|webp|gif)$/i;
  var U_IMG_BUNDLED = /^assets\/img\/[A-Za-z0-9_-][A-Za-z0-9._-]*\.(?:jpe?g|png|webp|gif)$/i;
  /* Also permitted because they are the documented, already-stored shapes for
     the university page defaults: a relative path under media/ (what
     ImageOptimiser writes for university logos, heroes and intake photos) and a
     plain https:// address, which that field's own hint invites. Narrowing to
     the first two would have silently blanked pictures that work today - the
     allow-list is here to bound what can be pointed at, not to redesign the
     field. */
  var U_IMG_STORED = /^media\/[A-Za-z0-9_\-\/]+\.(?:jpe?g|png|webp|gif)$/i;
  var U_IMG_HTTPS = /^https:\/\/[A-Za-z0-9.-]+(?::\d+)?\/[^\s"'\\]*$/i;

  function uSafeImg(url) {
    var v = String(url == null ? "" : url);
    // `..` anywhere, at all: no legitimate value contains it, and it is the
    // cheapest way to rule out traversal in every shape below at once.
    if (v.indexOf("..") !== -1) return "";
    return (U_IMG_MANAGED.test(v) || U_IMG_BUNDLED.test(v)
      || U_IMG_STORED.test(v) || U_IMG_HTTPS.test(v)) ? v : "";
  }

  /* Set a background through the CSSOM, never through markup.
     Building `style="background-image:url('" + esc(v) + "')"` looks escaped and
     is not: esc() writes &#39; and the browser decodes it back to a quote before
     the CSS parser runs, so the value can close the url() and inject rules. In
     a property assignment a quote is data. */
  function uPaintBg(el, url) {
    var safe = uSafeImg(url);
    if (!el || !safe) return;
    el.style.backgroundImage = 'url("' + safe.replace(/["\\\n\r]/g, "") + '")';
  }

  /* Paint every deferred background inside a freshly written container. */
  function uPaintAll(root) {
    if (!root) return;
    var nodes = root.querySelectorAll("[data-uimg]");
    for (var i = 0; i < nodes.length; i++) uPaintBg(nodes[i], nodes[i].getAttribute("data-uimg"));
  }

  function intakeCard(name, month, note, key, image) {
    var img = image || season(key).img;
    return '<article class="uintake">'
      + '<div class="uintake__img" data-uimg="' + esc(uSafeImg(img)) + '"></div>'
      + '<div class="uintake__body"><h3 class="uintake__name">' + esc(name) + '</h3>'
      + (month ? '<span class="uintake__month">' + esc(month) + '</span>' : '')
      + (note ? '<p class="uintake__note">' + esc(note) + '</p>' : '')
      + '</div></article>';
  }

  /* lead-form "I'm interested in" options come from the admin defaults */
  function applyLeadOptions() {
    var sel = $("#leadLevel"), opts = CMS.interest_options || [];
    if (!sel || !opts.length) return;
    sel.innerHTML = opts.map(function (o) { return '<option>' + esc(o) + '</option>'; }).join("");
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
  // feeHref: where to send someone whose course carries no tuition of its own.
  // Empty when this university has no cost figures either, in which case "On
  // request" is the honest answer rather than a link to an empty section.
  function courseRow(c, feeHref) {
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
      + (c.tuition
        ? '<div class="ucourse__fee">' + money(c.tuition) + '</div>'
        : (feeHref
          ? '<div class="ucourse__fee ucourse__fee--link"><a href="' + feeHref + '">See Cost to Study</a></div>'
          : '<div class="ucourse__fee"><span class="ucourse__na">On request</span></div>'))
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
    var footnote = cmsText("intake_footnote", "Applications open several months ahead — a counsellor can confirm the exact deadline for your course.");
    if (ib.length) {
      intakeInner = '<div class="uintakes">' + ib.map(function (b) {
        return intakeCard(b.name, b.month || "", b.note || "", seasonKey(b.name), b.image);
      }).join("") + '</div>'
        + (footnote ? '<p class="unote" style="margin-top:12px">' + esc(footnote) + '</p>' : '');
    } else if (s.seasons && s.seasons.length) {
      intakeInner = '<div class="uintakes">' + s.seasons.map(function (sn) {
        var m = season(sn);
        return intakeCard(cap(sn) + " intake", m.month, m.note, sn, m.img);
      }).join("") + '</div>'
        + (footnote ? '<p class="unote" style="margin-top:12px">' + esc(footnote) + '</p>' : '');
    }
    push("intakes", "Intakes", intakeInner);

    // Cost rows are built HERE, before the courses, because the course rows
    // need to know whether there is a cost figure to link to and this is the
    // computation that decides it. One value, read in two places.
    var costCur = s.tuition_currency || "";
    var costRows = (p.cost_rows || []).map(function (r) { return [r.label, r.value]; });
    if (!costRows.length) {
      if (s.tuition_min != null) costRows.push(["Annual tuition fee (from)", money({ minor: s.tuition_min, currency: costCur })]);
      if (s.tuition_max != null && s.tuition_max !== s.tuition_min) costRows.push(["Annual tuition fee (up to)", money({ minor: s.tuition_max, currency: costCur })]);
      if (p.cost && p.cost.living) costRows.push(["Living expenses", p.cost.living]);
      if (p.cost && p.cost.accommodation) costRows.push(["Housing & food", p.cost.accommodation]);
    }
    // Only a section that will actually be rendered can be linked to: push()
    // drops an empty one, and the anchor would then go nowhere.
    var feeHref = costRows.length ? "#usec-cost" : "";

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
          + byLevel[lvl].map(function (c) { return courseRow(c, feeHref); }).join("") + '</div></div>'
          // Rendered always, shown only when the list really overflows -
          // sizeCourseLists() decides. "More than ten" was a proxy for
          // overflow, and ten short titles fit where eight long ones do not.
          + '<p class="ucourses__hint" hidden>Showing all ' + byLevel[lvl].length + ' courses — scroll the list above.</p>'
          + '</div>';
      }
      push("courses", "Courses", '<div class="utabgroup"><div class="utabs">' + tabs + '</div>' + panels + '</div>');
    } else {
      push("courses", "Courses", '<p class="unote unote--card">Full course list on request — ask a counsellor.</p>');
    }

    // Cost to Study — narrative, expenses table, footnote
    // (costRows/costCur are built above the courses section - see the note there)
    var costIntro = (p.cost && p.cost.note) ? p.cost.note
      : fill(cmsText("cost_intro",
        "The cost of studying at {university} has two parts: tuition for your course, and living costs while you are there — "
        + "housing and food, books and materials, local travel, health cover and personal spending. Tuition varies by course and level, "
        + "so use the figures below as a planning guide and ask a counsellor for the exact cost of the courses on your shortlist."), u.name);
    var costInner = '<p class="unote">' + esc(costIntro) + '</p>';

    if (costRows.length) {
      costInner += tableHtml("Types of expenses", "Annual expenses" + (costCur ? " in " + costCur : ""), costRows);
      var cnote = cmsText("cost_footnote", "Note: these figures are approximate and change year to year. "
        + "Check the current fee schedule on the university’s official website, or ask your VFI counsellor for the latest breakdown.");
      if (cnote) costInner += '<p class="ucost__note">' + esc(cnote) + '</p>';
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
          + '<div class="uschol__act">'
          + (x.amount ? '<span class="uschol__amt">' + esc(x.amount) + '</span>' : '')
          + '<button type="button" class="btn btn--outline btn--sm" data-apply>View &amp; Apply</button></div></div>';
      }).join("") + '</div>';
    } else if (s.scholarship_available) {
      scholInner = '<p class="unote unote--card">' + esc(fill(cmsText("scholarship_note",
        "Scholarships are available on selected programs at {university}. Ask a counsellor which ones you qualify for."), u.name)) + '</p>';
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

    // FAQs — this university's, else the admin default set, else the built-in
    var faqs = (p.faqs && p.faqs.length) ? p.faqs
      : ((CMS.faqs && CMS.faqs.length) ? CMS.faqs : [
      { q: "Is there an application fee?", a: "It varies by course. Your VFI counsellor will confirm the fee for each course on your shortlist and tell you if a fee waiver applies." },
      { q: "What are the English language requirements?", a: (s.tests_required && s.tests_required.length)
        ? ("This university accepts " + s.tests_required.map(function (t) { return t.toUpperCase(); }).join(", ") + ". The score you need depends on the course — ask a counsellor for the exact requirement.")
        : "Requirements depend on the course. Ask a counsellor which test and score your shortlist needs." },
      { q: "When should I apply?", a: "Apply as early as you can. Places and scholarships are limited and popular courses close before the published deadline." },
      { q: "Can VFI help with my application and visa?", a: "Yes. VFI supports you end to end — shortlisting, application, documents, scholarships and visa guidance. Counselling is free." }
    ]);
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
    // Backgrounds are set here, after the markup is in the document, because
    // they are assigned as CSS properties rather than written into a style
    // attribute - see uPaintBg.
    uPaintAll(wrap);

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
        // The panel that just became visible can be measured now; the one that
        // was hidden never could be.
        sizeCourseLists();
      }
    });

    sizeCourseLists();
    spy();
  }

  /*
    Decide, per course list, whether it needs its own scrollbar.

    CSS cannot ask how tall its content is, so this measures: with the class
    off the box is in normal flow and scrollHeight is the natural content
    height. Taller than the cap and it becomes a scroller; otherwise it stays
    plain page flow and the wheel passes straight through, which is the whole
    point - a list of five has nothing to scroll and should not behave as if it
    does.

    Called after the first paint, after a level tab switch (Masters and
    Bachelors hold different numbers), on resize (the rows reflow and get
    taller as the column narrows) and on load (web fonts change row heights).
  */
  function sizeCourseLists() {
    $$(".ucourses__scroll").forEach(function (box) {
      // A hidden panel measures 0, so leave it for when its tab is opened.
      if (!box.offsetParent) return;

      box.classList.remove("is-scrollable");

      var cap = parseInt(getComputedStyle(box).getPropertyValue("--ucourses-max"), 10);
      if (!cap) cap = 720;

      // A few pixels of slack: a list one line over the cap is worse as a
      // scroller than as a slightly tall box.
      var overflows = box.scrollHeight > cap + 8;
      if (overflows) box.classList.add("is-scrollable");

      var panel = closestClass(box, "utabpanel") || box.parentNode.parentNode;
      var hint = panel ? panel.querySelector(".ucourses__hint") : null;
      if (hint) hint.hidden = !overflows;
    });
  }

  /* Nearest ancestor carrying a class, since this file predates .closest(). */
  function closestClass(el, cls) {
    for (var n = el; n && n.nodeType === 1; n = n.parentNode) {
      if (n.classList && n.classList.contains(cls)) return n;
    }
    return null;
  }

  /*
    Sticky-nav scrollspy: the tab that is highlighted follows the section you are
    reading, and the bar keeps the highlighted tab in view.

    The reading line is measured, not assumed. The site header is fixed and
    var(--header-h) tall (74px, 64px on a phone) and this bar rests under it, so
    "the top of what you can actually read" is wherever those two end at this
    width. The previous +140 was close enough on a desktop and wrong on a phone.

    Positions are cached because the old version called offsetTop on every
    section on every scroll event - a forced layout per section per event, on a
    page that is nearly 5,000px tall. They are read through
    getBoundingClientRect rather than offsetTop as well: offsetTop is relative
    to the offsetParent, which is <body> today and would silently stop being it
    the moment anything above these sections gained position: relative.
  */
  function spy() {
    var links = $$("#uniNavList a");
    if (!links.length) return;

    var nav = $("#uniNav");
    var rail = nav ? nav.querySelector(".container") : null;
    var targets = links.map(function (a) { return document.getElementById(a.getAttribute("href").slice(1)); });
    var tops = null;
    var queued = false;

    function docTop(el) { return el.getBoundingClientRect().top + window.pageYOffset; }

    function measure() {
      tops = targets.map(function (t) { return t ? docTop(t) : Infinity; });
    }

    /* Where the page stops being hidden behind the header and this bar. */
    function readingLine() {
      var header = document.querySelector(".header");
      var chrome = (header ? header.getBoundingClientRect().height : 0)
        + (nav ? nav.getBoundingClientRect().height : 0);
      return chrome + 24;
    }

    /*
      Keep the active tab inside the bar. At phone width six tabs do not fit and
      the bar scrolls horizontally, so the highlight could land off-screen.
      Only scrollLeft is touched: scrollIntoView would also move the page
      vertically, which is the one thing a scroll handler must never do.
    */
    function reveal(link) {
      if (!rail || rail.scrollWidth <= rail.clientWidth + 1) return;
      var pad = 16;
      var left = link.offsetLeft;
      var right = left + link.offsetWidth;
      if (left - pad < rail.scrollLeft) rail.scrollLeft = Math.max(0, left - pad);
      else if (right + pad > rail.scrollLeft + rail.clientWidth) rail.scrollLeft = right + pad - rail.clientWidth;
    }

    var current = -1;

    function paint() {
      queued = false;
      if (!tops) measure();

      var line = window.pageYOffset + readingLine();
      var active = 0;
      for (var i = 0; i < tops.length; i++) { if (tops[i] <= line) active = i; }

      // At the very bottom, the last section wins. Below the last nav section
      // sits the "Related universities" block, so on a short final section the
      // line can stop short of it and the highlight would never arrive.
      if (window.innerHeight + window.pageYOffset >= document.documentElement.scrollHeight - 4) {
        active = links.length - 1;
      }

      if (active === current) return;
      current = active;

      for (var j = 0; j < links.length; j++) {
        var on = j === active;
        links[j].classList.toggle("is-active", on);
        // Not colour alone: a screen reader has to be able to say which one.
        if (on) links[j].setAttribute("aria-current", "true");
        else links[j].removeAttribute("aria-current");
      }
      reveal(links[active]);
    }

    function onScroll() {
      if (queued) return;
      queued = true;
      window.requestAnimationFrame(paint);
    }

    function onResize() {
      // Heights and offsets both change with width: --header-h drops to 64px on
      // a phone and the sections reflow.
      tops = null;
      current = -1;
      onScroll();
    }

    function onReflow() {
      // A narrower column makes course rows taller, so a list that fitted can
      // stop fitting. Re-measure before re-measuring the section offsets,
      // because this one changes them.
      sizeCourseLists();
      onResize();
    }

    window.addEventListener("scroll", onScroll, { passive: true });
    window.addEventListener("resize", onReflow);
    window.addEventListener("orientationchange", onReflow);

    // Images and the lead form land after this runs and move everything below
    // them, so re-measure once the page has settled.
    window.addEventListener("load", onReflow);

    paint();
  }
})();
