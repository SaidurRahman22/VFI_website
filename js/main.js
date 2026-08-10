/* =====================================================================
   VFI Overseas Education — interactions
   Vanilla JS, no dependencies. All animations respect reduced-motion.
   ===================================================================== */
(function () {
  "use strict";
  var reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  var $ = function (s, c) { return (c || document).querySelector(s); };
  var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };

  /* -------- Year in footer -------- */
  var yearEl = $("#year");
  if (yearEl) yearEl.textContent = new Date().getFullYear();

  /* -------- Sticky header shadow -------- */
  var header = $("#header");
  var menuHover = false;
  var overlayHeader = document.body.getAttribute("data-header") === "overlay";
  function updateHeader() {
    if (header) header.classList.toggle("solid", !overlayHeader || window.scrollY > 8 || menuHover);
    if (toTop) toTop.classList.toggle("is-show", window.scrollY > 600);
  }
  var onScroll = updateHeader;

  /* -------- Mobile nav -------- */
  var nav = $("#nav");
  var burger = $("#hamburger");
  var scrim = $("#navScrim");
  function setMenu(open) {
    if (!nav || !burger) return;
    nav.classList.toggle("is-open", open);
    burger.classList.toggle("is-open", open);
    burger.setAttribute("aria-expanded", String(open));
    burger.setAttribute("aria-label", open ? "Close menu" : "Open menu");
    if (scrim) {
      scrim.hidden = false;
      // allow transition
      requestAnimationFrame(function () { scrim.classList.toggle("is-show", open); });
      if (!open) setTimeout(function () { if (!nav.classList.contains("is-open")) scrim.hidden = true; }, 320);
    }
    document.body.style.overflow = open ? "hidden" : "";
    if (open) { var firstLink = nav.querySelector(".nav__link"); if (firstLink) firstLink.focus(); }
    else if (document.activeElement && nav.contains(document.activeElement)) { burger.focus(); }
  }
  if (burger) burger.addEventListener("click", function () { setMenu(!nav.classList.contains("is-open")); });
  if (scrim) scrim.addEventListener("click", function () { setMenu(false); });
  // close menu when a link is tapped
  $$(".nav__link, .nav__cta", nav).forEach(function (a) {
    a.addEventListener("click", function () {
      if (window.innerWidth <= 1200 && !a.parentElement.classList.contains("has-menu")) setMenu(false);
    });
  });
  document.addEventListener("keydown", function (e) {
    if (!nav || !nav.classList.contains("is-open")) return;
    if (e.key === "Escape") { setMenu(false); return; }
    if (e.key === "Tab") {
      var f = nav.querySelectorAll('a[href],button:not([disabled]),input,select,textarea');
      if (!f.length) return;
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  });
  // reset on resize to desktop
  window.addEventListener("resize", function () {
    if (window.innerWidth > 1200 && nav && nav.classList.contains("is-open")) setMenu(false);
  });

  /* -------- Mega-menus: solid header while open (desktop) + tap-to-expand (mobile) -------- */
  $$(".has-menu").forEach(function (li) {
    var trigger = li.querySelector(".nav__link");
    li.addEventListener("mouseenter", function () { if (window.innerWidth > 1200) { menuHover = true; updateHeader(); } });
    li.addEventListener("mouseleave", function () { if (window.innerWidth > 1200) { menuHover = false; updateHeader(); } });
    li.addEventListener("focusin", function () { if (window.innerWidth > 1200) { menuHover = true; updateHeader(); } });
    li.addEventListener("focusout", function () { setTimeout(function () { if (!li.contains(document.activeElement)) { menuHover = false; updateHeader(); } }, 0); });
    if (trigger) trigger.addEventListener("click", function (e) {
      if (window.innerWidth <= 1200) {
        e.preventDefault();
        var willOpen = !li.classList.contains("open");
        $$(".has-menu").forEach(function (o) { if (o !== li) o.classList.remove("open"); });
        li.classList.toggle("open", willOpen);
      }
    });
  });

  /* -------- Smooth anchor scroll with sticky-header offset -------- */
  $$('a[href^="#"]').forEach(function (link) {
    link.addEventListener("click", function (e) {
      var id = link.getAttribute("href");
      if (id === "#" || id.length < 2) { e.preventDefault(); return; }
      var target = document.getElementById(id.slice(1));
      if (!target) return;
      e.preventDefault();
      var headerH = header ? header.offsetHeight : 0;
      var y = target.getBoundingClientRect().top + window.scrollY - headerH - 12;
      window.scrollTo({ top: y, behavior: reduceMotion ? "auto" : "smooth" });
      if (history.replaceState) history.replaceState(null, "", id);
    });
  });

  /* -------- Incoming #hash from another page --------
     Arriving at e.g. contact.html#contact, the browser jumps the target flush
     to the viewport top, which tucks it under the fixed header. Re-scroll with
     the same offset the in-page links use, and repeat once layout settles so
     late-loading fonts and images can't leave it a few pixels off. */
  (function () {
    if (!location.hash || location.hash.length < 2) return;
    var target;
    try { target = document.getElementById(decodeURIComponent(location.hash.slice(1))); }
    catch (e) { return; }
    if (!target) return;
    var apply = function () {
      var headerH = header ? header.offsetHeight : 0;
      var y = target.getBoundingClientRect().top + window.scrollY - headerH - 12;
      /* "instant", not "auto" — the root element sets scroll-behavior:smooth,
         and "auto" defers to it, which would visibly animate the correction on
         page load. */
      window.scrollTo({ top: Math.max(0, y), behavior: "instant" });
    };
    apply();
    setTimeout(apply, 300);
    window.addEventListener("load", function () { setTimeout(apply, 60); });
  })();

  /* -------- Scroll animations --------
     Elements are tagged with [data-anim] and flipped to .is-in as they scroll
     into view. Most tagging happens automatically from the rules below, so a
     page picks up animation without any markup changes.

     The previous version had a blanket "reveal everything after 1200ms" safety
     net, which meant that by the time you scrolled down, every section was
     already visible and nothing ever animated. The net is now scoped to
     elements that are actually in view, so off-screen content stays armed. */

  /* elements tagged one-by-one */
  var ANIM_ONE = [
    [".sec-head", "fade"], [".sec-title", "up"], [".page-hero__inner", "up"],
    [".pfeat__text", "left"], [".pfeat__vis", "right"],
    [".pfeat.is-flip .pfeat__text", "right"], [".pfeat.is-flip .pfeat__vis", "left"],
    [".ctaband", "pop"], [".pgrow__card", "pop"], [".gpanel", "scale"],
    [".phero__text", "up"], [".phero__vis", "blur"],
    [".papp__text", "left"], [".papp__vis", "right"],
    [".band__inner > *", "up"], [".journey__inner > *", "up"],
    [".contact__inner > *", "up"], [".testprep__inner > *", "up"],
    [".ambitions__inner > *", "up"], [".whyvfi > *", "up"],
    [".svcrow__text", "left"], [".svcrow__media", "right"],
    [".eband__inner > *", "up"], [".bhero__inner > *", "up"],
    [".home-hero__inner > *", "up"], [".pjobs__head", "up"],
    [".newsletter__inner", "up"], [".stats", "fade"]
  ];

  /* containers whose direct children animate in sequence */
  var ANIM_STAGGER = [
    [".svc-grid", "pop"], [".lead-grid", "up"], [".stats__grid", "pop"],
    [".psteps3__grid", "pop"], [".pjobs__grid", "up"], [".events__grid", "up"],
    [".fevents__grid", "up"], [".blogs__grid", "up"], [".gal-grid", "clip"],
    [".dest-grid", "pop"], [".destcards", "pop"], [".team-grid", "pop"],
    [".unigrid", "up"], [".unicards", "up"], [".updates__grid", "up"],
    [".whygrid", "up"], [".whykc", "up"], [".costgrid", "up"],
    [".accordion", "up"], [".citycard", "up"], [".admits", "up"],
    [".tech__row", "pop"], [".courselist", "up"], [".examrow", "up"],
    [".critlist", "up"], [".starlist", "up"], [".psteps", "up"],
    [".offer-grid", "up"], [".feature-post", "up"], [".visarow", "up"]
  ];

  /* things that get a hover lift as well */
  var LIFT = ".svc-card, .info-card, .event, .blog, .ph, .pjob, .ptest, .pstep3, .citycard, .dest-card";

  function tagged(el) { return el.hasAttribute("data-anim"); }
  function taggedAncestor(el) {
    for (var p = el.parentElement; p; p = p.parentElement) {
      if (p.hasAttribute && p.hasAttribute("data-anim")) return true;
    }
    return false;
  }
  function tag(el, anim, order) {
    if (!el || tagged(el) || taggedAncestor(el)) return;
    el.setAttribute("data-anim", anim);
    if (order) el.setAttribute("data-anim-order", order);
    el.classList.remove("reveal");   // don't run both systems on one node
  }

  function autoTag(scope) {
    var root = scope || document;

    // a .pfeat carries .reveal from the generator — let its two halves animate instead
    $$(".pfeat", root).forEach(function (f) { f.classList.remove("reveal"); });

    ANIM_ONE.forEach(function (r) {
      $$(r[0], root).forEach(function (el) { tag(el, r[1]); });
    });

    ANIM_STAGGER.forEach(function (r) {
      $$(r[0], root).forEach(function (box) {
        if (tagged(box)) return;
        Array.prototype.slice.call(box.children).forEach(function (child, i) {
          tag(child, r[1], i);
        });
      });
    });

    /* Anything still carrying the old .reveal class becomes a plain rise.
       If tag() declines — because an ancestor is already animating this
       element as part of a group — the class must still come off. Leaving it
       would apply opacity:0 with no observer watching, hiding the node for
       good. */
    $$(".reveal", root).forEach(function (el) {
      tag(el, "up");
      el.classList.remove("reveal");
    });

    $$(LIFT, root).forEach(function (el) { el.classList.add("lift"); });
  }

  /* ---- split a heading into words so it can rise word by word ---- */
  function splitWords(el) {
    if (!el || el.getAttribute("data-split") === "1") return;
    if (el.children.length) return;                     // leave rich markup alone
    var text = el.textContent;
    if (!text || text.length > 90) return;
    el.setAttribute("data-split", "1");
    el.textContent = "";
    text.split(/\s+/).forEach(function (w, i) {
      if (!w) return;
      var s = document.createElement("span");
      s.className = "anim-word";
      s.style.transitionDelay = (i * 55) + "ms";
      s.textContent = w;
      el.appendChild(s);
      el.appendChild(document.createTextNode(" "));
    });
  }

  var io = null;

  function initReveal(scope) {
    autoTag(scope);
    var els = $$("[data-anim]:not(.is-in)");

    if (reduceMotion || !("IntersectionObserver" in window)) {
      els.forEach(function (el) { el.classList.add("is-in"); });
      return;
    }

    $$(".sec-title:not([data-split]), .page-hero__inner h1:not([data-split])").forEach(splitWords);

    if (!io) {
      io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          var el = entry.target;
          var order = parseInt(el.getAttribute("data-anim-order") || "0", 10);
          el.style.transitionDelay = Math.min(order, 8) * 85 + "ms";
          el.classList.add("is-in");
          io.unobserve(el);
        });
      }, { threshold: 0.08, rootMargin: "0px 0px -6% 0px" });
    }
    els.forEach(function (el) { io.observe(el); });

    /* Safety net — only for elements that are ALREADY within the viewport.
       Anything below the fold stays hidden so it can animate on scroll. */
    setTimeout(function () {
      $$("[data-anim]:not(.is-in)").forEach(function (el) {
        var r = el.getBoundingClientRect();
        if (r.top < window.innerHeight && r.bottom > 0) el.classList.add("is-in");
      });
    }, 1400);
  }
  window.VFIInitReveal = initReveal;
  initReveal();

  /* -------- Scroll progress bar -------- */
  if (!reduceMotion && !$(".scrollbar")) {
    var bar = document.createElement("div");
    bar.className = "scrollbar";
    bar.setAttribute("aria-hidden", "true");
    bar.innerHTML = '<div class="scrollbar__fill"></div>';
    document.body.appendChild(bar);
    var fill = $(".scrollbar__fill", bar);
    var barTick = false;
    var onBarScroll = function () {
      if (barTick) return;
      barTick = true;
      requestAnimationFrame(function () {
        var h = document.documentElement.scrollHeight - window.innerHeight;
        fill.style.width = (h > 0 ? Math.min(100, (window.scrollY / h) * 100) : 0) + "%";
        barTick = false;
      });
    };
    window.addEventListener("scroll", onBarScroll, { passive: true });
    onBarScroll();
  }

  /* -------- Subtle parallax on hero visuals -------- */
  if (!reduceMotion) {
    // decorative inner art only — never an element carrying [data-anim], whose
    // transform the reveal transition owns
    var pxEls = $$(".phero__mock, .pphones, .home-hero__art, .testprep__art").filter(function (el) {
      return !el.hasAttribute("data-anim");
    });
    pxEls.forEach(function (el) { el.setAttribute("data-parallax", "1"); });
    if (pxEls.length) {
      var pxTick = false;
      var onPx = function () {
        if (pxTick) return;
        pxTick = true;
        requestAnimationFrame(function () {
          var y = window.scrollY;
          pxEls.forEach(function (el) {
            if (y > window.innerHeight * 1.2) return;   // only while the hero is on screen
            el.style.transform = "translate3d(0," + (y * 0.07).toFixed(1) + "px,0)";
          });
          pxTick = false;
        });
      };
      window.addEventListener("scroll", onPx, { passive: true });
    }
  }

  /* -------- Animated counters -------- */
  var counters = $$(".stat__num");
  function formatNum(n, useComma) {
    n = Math.round(n);
    if (useComma) return n.toLocaleString("en-US");
    return String(n);
  }
  function runCounter(el) {
    var target = parseFloat(el.getAttribute("data-count")) || 0;
    var suffix = el.getAttribute("data-suffix") || "";
    var useComma = el.getAttribute("data-format") === "1";
    if (reduceMotion) { el.textContent = formatNum(target, useComma) + suffix; return; }
    var dur = 1600, start = null;
    function step(ts) {
      if (start === null) start = ts;
      var p = Math.min((ts - start) / dur, 1);
      var eased = 1 - Math.pow(1 - p, 3); // easeOutCubic
      el.textContent = formatNum(target * eased, useComma) + suffix;
      if (p < 1) requestAnimationFrame(step);
      else el.textContent = formatNum(target, useComma) + suffix;
    }
    requestAnimationFrame(step);
  }
  if ("IntersectionObserver" in window && counters.length) {
    var cio = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) { runCounter(entry.target); cio.unobserve(entry.target); }
      });
    }, { threshold: 0.5 });
    counters.forEach(function (el) { cio.observe(el); });
  } else {
    counters.forEach(runCounter);
  }

  /* -------- Accordion: smooth height + single-open -------- */
  var accs = $$(".acc");
  function setBody(acc, open) {
    var body = $(".acc__body", acc);
    if (!body) return;
    if (reduceMotion) { body.style.maxHeight = open ? "none" : "0px"; return; }
    if (open) {
      body.style.maxHeight = "0px";              // concrete start
      void body.offsetHeight;                    // force reflow so the expand transitions
      body.style.maxHeight = body.scrollHeight + "px";
      // release to natural height after the transition so responsive reflow works
      var done = function () { if (acc.open) body.style.maxHeight = "none"; body.removeEventListener("transitionend", done); };
      body.addEventListener("transitionend", done);
      setTimeout(done, 500);
    } else {
      body.style.maxHeight = body.scrollHeight + "px"; // concrete start
      void body.offsetHeight;                          // force reflow so the collapse transitions (and transitionend fires)
      body.style.maxHeight = "0px";
    }
  }
  function animatedClose(el) {
    var b = $(".acc__body", el);
    if (!b) { el.open = false; return; }
    setBody(el, false); // animate collapse while the panel is still open
    if (reduceMotion) { el.open = false; return; }
    var closed = false;
    var close = function () { if (closed) return; closed = true; el.open = false; b.removeEventListener("transitionend", close); };
    b.addEventListener("transitionend", close);
    setTimeout(close, 500); // fallback if transitionend is missed
  }
  /* One wiring path for both the initial pass and the re-hook after render.js
     rebuilds a list. The _wired flag must be set here too — without it the
     re-hook attaches a second click handler, and the two handlers open then
     immediately close the panel. */
  function wireAcc(acc) {
    if (acc._wired) return;
    acc._wired = true;
    var body = $(".acc__body", acc);
    if (body && acc.open) body.style.maxHeight = "none";
    var summary = $("summary", acc);
    if (!summary) return;
    summary.addEventListener("click", function (e) {
      e.preventDefault();
      if (!acc.open) {
        accs.forEach(function (o) { if (o !== acc && o.open) animatedClose(o); });
        acc.open = true;
        setBody(acc, true);
      } else {
        animatedClose(acc);
      }
    });
  }
  accs.forEach(wireAcc);

  window.VFIInitAccordions = function () {
    accs = $$(".acc");   // refresh so single-open also closes newly rendered panels
    accs.forEach(wireAcc);
  };

  /* -------- Back to top -------- */
  var toTop = $("#toTop");
  if (toTop) toTop.addEventListener("click", function () {
    window.scrollTo({ top: 0, behavior: reduceMotion ? "auto" : "smooth" });
  });

  /* -------- Contact form (front-end only) -------- */
  var cform = $("#cform");
  if (cform) {
    cform.addEventListener("submit", function (e) {
      e.preventDefault();
      var ok = true;
      ["fname", "phone", "email"].forEach(function (id) {
        var input = $("#" + id);
        var field = input.closest(".field");
        var valid = input.value.trim() !== "" && (input.type !== "email" || /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(input.value));
        field.classList.toggle("is-invalid", !valid);
        input.setAttribute("aria-invalid", String(!valid));
        if (!valid) ok = false;
      });
      if (!ok) { var bad = $(".is-invalid input", cform); if (bad) bad.focus(); return; }
      var done = $("#cformDone");
      var btn = $("button[type=submit]", cform);
      if (btn) { btn.disabled = true; btn.style.opacity = ".7"; }
      setTimeout(function () {
        if (done) done.hidden = false;
        cform.reset();
        if (btn) { btn.disabled = false; btn.style.opacity = ""; }
        if (done) done.scrollIntoView({ behavior: reduceMotion ? "auto" : "smooth", block: "center" });
      }, 600);
    });
    // clear invalid state on input
    $$("input,textarea", cform).forEach(function (el) {
      el.addEventListener("input", function () { el.closest(".field").classList.remove("is-invalid"); el.removeAttribute("aria-invalid"); });
    });
  }

  /* -------- Newsletter (front-end only) -------- */
  var nform = $("#nform");
  if (nform) {
    nform.addEventListener("submit", function (e) {
      e.preventDefault();
      var input = $("input[type=email]", nform);
      if (!input.value.trim() || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(input.value)) { input.focus(); return; }
      var btn = $("button[type=submit]", nform);
      btn.textContent = "Subscribed ✓";
      btn.disabled = true;
      input.value = "";
      setTimeout(function () { btn.textContent = "Subscribe Now"; btn.disabled = false; }, 2600);
    });
  }

  /* "Branch Address" in the contact hero is now a plain #contact anchor, so it
     is handled by the smooth-anchor scroll above and works without JS too. */

  /* -------- Testimonials carousel -------- */
  var track = $("#tTrack"), tPrev = $("#tPrev"), tNext = $("#tNext");
  if (track && track.children.length) {
    var tIdx = 0;
    var perView = function () { return window.innerWidth <= 680 ? 1 : (window.innerWidth <= 980 ? 2 : 3); };
    var maxIdx = function () { return Math.max(0, track.children.length - perView()); };
    var applySlide = function () {
      tIdx = Math.min(tIdx, maxIdx());
      var cs = getComputedStyle(track);
      var gap = parseFloat(cs.columnGap || cs.gap) || 26;
      var cardW = track.children[0].getBoundingClientRect().width + gap;
      track.style.transform = "translateX(" + (-tIdx * cardW) + "px)";
      if (tPrev) tPrev.disabled = tIdx <= 0;
      if (tNext) tNext.disabled = tIdx >= maxIdx();
    };
    if (tPrev) tPrev.addEventListener("click", function () { tIdx = Math.max(0, tIdx - 1); applySlide(); });
    if (tNext) tNext.addEventListener("click", function () { tIdx = Math.min(maxIdx(), tIdx + 1); applySlide(); });
    var rT;
    window.addEventListener("resize", function () { clearTimeout(rT); rT = setTimeout(applySlide, 120); });
    applySlide();
  }

  /* -------- Reusable auto-moving slider ([data-autoslide]) -------- */
  function initAutoSlide(root) {
    if (!root) return;
    var track = $(".aslide__track", root);
    var dotsBox = $(".aslide__dots", root);
    if (!track) return;

    // tear down a previous instance (content can be re-rendered from the admin store)
    if (root._auto) { clearInterval(root._auto.timer); window.removeEventListener("resize", root._auto.onResize); }

    var items = Array.prototype.slice.call(track.children);
    var idx = 0, timer = null;
    var interval = parseInt(root.getAttribute("data-interval"), 10) || 4200;

    function perView() {
      var v = parseInt(getComputedStyle(root).getPropertyValue("--per"), 10);
      return v > 0 ? v : 1;
    }
    function maxIdx() { return Math.max(0, items.length - perView()); }

    function apply() {
      idx = Math.min(idx, maxIdx());
      if (!items.length) return;
      var cs = getComputedStyle(track);
      var gap = parseFloat(cs.columnGap || cs.gap) || 0;
      var step = items[0].getBoundingClientRect().width + gap;
      track.style.transform = "translateX(" + (-idx * step) + "px)";
      if (dotsBox) {
        Array.prototype.slice.call(dotsBox.children).forEach(function (d, i) {
          d.classList.toggle("is-on", i === idx);
        });
      }
    }

    function buildDots() {
      if (!dotsBox) return;
      dotsBox.innerHTML = "";
      var n = maxIdx() + 1;
      if (n <= 1) return;                       // nothing to slide
      for (var i = 0; i < n; i++) {
        var b = document.createElement("button");
        b.className = "aslide__dot" + (i === idx ? " is-on" : "");
        b.type = "button";
        b.setAttribute("aria-label", "Go to slide " + (i + 1));
        (function (n2) { b.addEventListener("click", function () { idx = n2; apply(); restart(); }); })(i);
        dotsBox.appendChild(b);
      }
    }

    function next() { idx = idx >= maxIdx() ? 0 : idx + 1; apply(); }
    function stop() { clearInterval(timer); timer = null; }
    function start() {
      stop();
      if (reduceMotion || maxIdx() < 1 || document.hidden) return;
      timer = setInterval(next, interval);
    }
    function restart() { start(); }

    // optional prev/next arrows (a slider without them just skips this)
    var navPrev = $(".aslide__nav--prev", root);
    var navNext = $(".aslide__nav--next", root);
    function step(dir) {
      if (dir > 0) idx = idx >= maxIdx() ? 0 : idx + 1;
      else idx = idx <= 0 ? maxIdx() : idx - 1;
      apply(); restart();
    }
    if (navPrev) navPrev.addEventListener("click", function () { step(-1); });
    if (navNext) navNext.addEventListener("click", function () { step(1); });

    root.addEventListener("mouseenter", stop);
    root.addEventListener("mouseleave", start);
    root.addEventListener("focusin", stop);
    root.addEventListener("focusout", start);
    document.addEventListener("visibilitychange", function () { document.hidden ? stop() : start(); });

    // touch swipe
    var x0 = null;
    track.addEventListener("touchstart", function (e) { x0 = e.touches[0].clientX; stop(); }, { passive: true });
    track.addEventListener("touchend", function (e) {
      if (x0 === null) return;
      var dx = e.changedTouches[0].clientX - x0;
      if (Math.abs(dx) > 40) {
        if (dx < 0) idx = idx >= maxIdx() ? 0 : idx + 1;
        else idx = idx <= 0 ? maxIdx() : idx - 1;
        apply();
      }
      x0 = null; start();
    });

    var rT;
    function onResize() { clearTimeout(rT); rT = setTimeout(function () { buildDots(); apply(); }, 140); }
    window.addEventListener("resize", onResize);

    root._auto = { timer: null, onResize: onResize };
    buildDots(); apply(); start();
    root._auto.timer = timer;
    // keep the handle fresh so stop/start work after re-init
    Object.defineProperty(root._auto, "timer", { get: function () { return timer; }, set: function (v) { timer = v; }, configurable: true });
  }
  window.VFIAutoSlide = initAutoSlide;
  $$("[data-autoslide]").forEach(initAutoSlide);

  /* -------- Region hub: jump to the chosen destination -------- */
  var dsForm = $("#destSearch"), dsSel = $("#destSelect");
  if (dsForm && dsSel) {
    dsForm.addEventListener("submit", function (e) {
      e.preventDefault();
      var t = dsSel.value && document.getElementById(dsSel.value);
      if (!t) { dsSel.focus(); return; }
      var h = header ? header.offsetHeight : 0;
      window.scrollTo({ top: t.getBoundingClientRect().top + window.scrollY - h - 20, behavior: reduceMotion ? "auto" : "smooth" });
    });
  }

  /* -------- Tabs ([data-tabs]) -------- */
  $$("[data-tabs]").forEach(function (box) {
    var btns = $$(".tabs__btn", box), panels = $$(".tabs__panel", box);
    btns.forEach(function (btn) {
      btn.addEventListener("click", function () {
        var key = btn.getAttribute("data-tab");
        btns.forEach(function (b) { b.classList.toggle("is-on", b === btn); });
        panels.forEach(function (p) { p.classList.toggle("is-on", p.getAttribute("data-panel") === key); });
      });
    });
  });

  /* -------- Job filter (VFI Partner page) --------
     Filters the job cards by department tab and by the location select.
     Runs after render.js may have rebuilt the cards from the admin store,
     so the card list is read fresh on every filter pass. */
  var jobTabs = $(".pjobs__tabs"), jobSel = $("#jobLoc"), jobGrid = $(".pjobs__grid");
  if (jobTabs && jobGrid) {
    var jobNone = $(".pjobs__none");
    function filterJobs() {
      var on = $(".pjobs__tab.is-on", jobTabs);
      var dept = on ? on.getAttribute("data-tab") : "All";
      var loc = jobSel ? jobSel.value : "";
      var shown = 0;
      $$(".pjob", jobGrid).forEach(function (card) {
        var cd = card.getAttribute("data-dept") || "All";
        var cl = card.querySelector('[data-pf="location"]');
        cl = cl ? cl.textContent.trim() : "";
        var ok = (dept === "All" || cd === dept) && (!loc || cl === loc);
        card.hidden = !ok;
        if (ok) shown++;
      });
      if (jobNone) jobNone.hidden = shown > 0;
    }
    $$(".pjobs__tab", jobTabs).forEach(function (btn) {
      btn.addEventListener("click", function () {
        $$(".pjobs__tab", jobTabs).forEach(function (b) { b.classList.toggle("is-on", b === btn); });
        filterJobs();
      });
    });
    if (jobSel) jobSel.addEventListener("change", filterJobs);
    window.VFIFilterJobs = filterJobs;
  }

  /* -------- Collapsible contents sidebar (destination pages) -------- */
  var doc = $("#doc"), tocBtn = $("#tocToggle"), tocList = $("#tocList");
  if (doc && tocBtn) {
    function setToc(open) {
      doc.classList.toggle("is-collapsed", !open);
      tocBtn.classList.toggle("is-open", open);
      tocBtn.setAttribute("aria-expanded", String(open));
      var lbl = $(".toc-toggle__label", tocBtn);
      if (lbl) lbl.textContent = open ? "Hide contents" : "Contents";
    }
    // starts closed on narrow screens, open on desktop
    setToc(window.innerWidth > 980);
    tocBtn.addEventListener("click", function () {
      setToc(doc.classList.contains("is-collapsed"));
    });
    // tapping a link closes it again on small screens
    if (tocList) $$("a", tocList).forEach(function (a) {
      a.addEventListener("click", function () { if (window.innerWidth <= 980) setToc(false); });
    });

    // highlight the section you're currently reading
    var links = tocList ? $$("a", tocList) : [];
    var targets = links.map(function (a) { return document.getElementById(a.getAttribute("href").slice(1)); });
    if (links.length && "IntersectionObserver" in window) {
      var spy = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          if (!en.isIntersecting) return;
          var i = targets.indexOf(en.target);
          if (i < 0) return;
          links.forEach(function (l, j) { l.classList.toggle("is-on", i === j); });
        });
      }, { rootMargin: "-25% 0px -65% 0px" });
      targets.forEach(function (t) { if (t) spy.observe(t); });
    }
  }

  window.addEventListener("scroll", onScroll, { passive: true });
  onScroll();
})();
