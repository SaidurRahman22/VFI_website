/* ==========================================================================
   partner-auth.js — the whole VFI Partner access flow

     vfi-partner-login.html    sign in + three-step agency registration
     vfi-partner-forgot.html   password reset  (ask → check your inbox)
     vfi-partner-verify.html   email verification (6-box one-time code)

   One script serves all three. Every block below is gated on the elements
   it needs, so a page only runs its own behaviour; the shared parts —
   validation, character rules, messages, the focus glow, ripples and the
   resend cooldown — are written once.

     vfi-partner-reset.html    set a new password from an emailed token link

   LIVE (Phase 6): every flow posts to the Laravel backend through window.VFIApi
   (same-origin cookie session + CSRF). Registration returns an opaque flow_id —
   the email address never rides in the URL; the OTP is verified server-side (a
   wrong code is rejected); the email-change is authorised by the flow_id, not a
   ?email= param. See docs/phases/phase-6-*.md and
   backend/app/Http/Controllers/Partner.
   ========================================================================== */
(function () {
  "use strict";

  var $ = function (sel, ctx) { return (ctx || document).querySelector(sel); };
  var $$ = function (sel, ctx) {
    return Array.prototype.slice.call((ctx || document).querySelectorAll(sel));
  };

  /* ------------------------------------------------------------------
     "page switched off" guard.
     These pages carry their own copy because they deliberately skip
     render.js — there is no shared header or footer here. Each one names
     itself on <body data-pa-page="…">, so the same guard covers all three.
     ------------------------------------------------------------------ */
  var PAGE = (document.body && document.body.getAttribute("data-pa-page")) || "vfi-partner-login.html";

  try {
    if (window.VFI && VFI.pageEnabled && !VFI.pageEnabled(PAGE)) {
      var off = document.getElementById("main");
      if (off) {
        document.body.className = "";
        off.className = "";
        off.innerHTML = '<section class="section"><div class="container"><div class="pageoff">' +
          "<h1>This page is currently unavailable</h1>" +
          "<p>It has been switched off by the site administrator. Please check back soon.</p>" +
          '<a href="vfi-partner.html" class="btn btn--pblue btn--lg">Back to VFI Partner</a>' +
          "</div></div></section>";
        return;
      }
    }
  } catch (e) { /* storage blocked — show the page */ }

  /* ------------------------------------------------------------ motion */
  var MOTION = window.matchMedia ? window.matchMedia("(prefers-reduced-motion: reduce)") : null;
  function calm() { return !!(MOTION && MOTION.matches); }

  /* ----------------------------------------------------------- elements */
  var card = $("#paCard");
  var glow = $("#paGlow");
  var tabsWrap = $("#paTabs");
  var tabs = $$(".pa-tab", tabsWrap || document);
  var ink = tabsWrap ? $(".pa-tabs__ink", tabsWrap) : null;
  var views = $("#paViews");
  var forms = { signin: $("#paSignin"), register: $("#paRegister") };
  var msg = $("#paMsg");
  var title = $("#paTitle");
  var sub = $("#paSub");

  var steps = $("#paSteps");
  var stepEls = $$(".pa-step", steps || document);
  var progFill = $("#paProgFill");
  var progItems = $$(".pa-prog__item");
  var stepNote = $("#paStepNote");
  var current = 1;
  var heightTimer = 0;

  var done = $("#paDone");
  var doneTitle = $("#paDoneTitle");
  var doneTxt = $("#paDoneTxt");
  var doneBack = $("#paDoneBack");

  var meter = $("#paRgPassMeter");
  var meterLbl = $("#paRgPassLbl");

  var DASH = "—";

  var COPY = {
    signin: {
      t: "Partner sign in",
      s: "Welcome back. Pick up your applications exactly where you left them."
    },
    register: {
      t: "Register your agency",
      s: "Three short steps. Our partner team reviews every application before an account goes live."
    }
  };

  var STEP_NOTE = [
    "Step 1 of 3 " + DASH + " tell us about your agency",
    "Step 2 of 3 " + DASH + " who should we talk to?",
    "Step 3 of 3 " + DASH + " secure the account"
  ];

  var STRENGTH = [
    "Use 8+ characters with a number and a capital letter.",
    "Too easy to guess " + DASH + " add length and variety.",
    "Getting there " + DASH + " mix in numbers or symbols.",
    "Good. A symbol would make it stronger.",
    "Strong password."
  ];

  var MISSING = {
    agency: "Enter your registered agency name.",
    country: "Choose the country you operate from.",
    city: "Enter the city you operate from.",
    person: "Tell us who we should speak to.",
    email: "Enter your work email address.",
    phone: "Enter a number we can reach you on.",
    pass: "Enter your password.",
    newpass: "Choose a password.",
    confirm: "Re-enter the password to confirm it."
  };

  var EMAIL = /^[^\s@]+@[^\s@]+\.[a-z]{2,}$/i;

  /* =================================================================
     TABS
     ================================================================= */
  function moveInk() {
    if (!ink || !tabsWrap || tabsWrap.hidden) return;
    var on = null;
    tabs.forEach(function (t) { if (t.classList.contains("pa-on")) on = t; });
    if (!on || !on.offsetWidth) return;
    ink.style.width = on.offsetWidth + "px";
    ink.style.transform = "translate3d(" + on.offsetLeft + "px,0,0)";
  }

  function showView(which) {
    if (!forms[which]) return;
    hideDone();
    tabs.forEach(function (t) {
      var on = t.getAttribute("data-pa-view") === which;
      t.classList.toggle("pa-on", on);
      t.setAttribute("aria-selected", on ? "true" : "false");
      t.setAttribute("tabindex", on ? "0" : "-1");
    });
    Object.keys(forms).forEach(function (k) {
      if (forms[k]) forms[k].classList.toggle("pa-on", k === which);
    });
    moveInk();
    if (title) title.textContent = COPY[which].t;
    if (sub) sub.textContent = COPY[which].s;
    hideMsg();
    clearErrors(forms[which]);
    try {
      history.replaceState(null, "", which === "register" ? "#register" : "#signin");
    } catch (e) {}
  }

  tabs.forEach(function (t) {
    t.addEventListener("click", function () { showView(t.getAttribute("data-pa-view")); });
  });

  /* arrow-key / Home / End navigation, as expected of role="tablist" */
  if (tabsWrap) {
    tabsWrap.addEventListener("keydown", function (e) {
      var i = tabs.indexOf(document.activeElement);
      if (i < 0) return;
      var next = -1;
      if (e.key === "ArrowRight" || e.key === "ArrowDown") next = (i + 1) % tabs.length;
      else if (e.key === "ArrowLeft" || e.key === "ArrowUp") next = (i + tabs.length - 1) % tabs.length;
      else if (e.key === "Home") next = 0;
      else if (e.key === "End") next = tabs.length - 1;
      if (next < 0) return;
      e.preventDefault();
      tabs[next].focus();
      showView(tabs[next].getAttribute("data-pa-view"));
    });
  }

  $$("[data-pa-goto]").forEach(function (b) {
    b.addEventListener("click", function () {
      showView(b.getAttribute("data-pa-goto"));
      var t = $('.pa-tab[data-pa-view="' + b.getAttribute("data-pa-goto") + '"]');
      if (t) t.focus();
    });
  });

  moveInk();
  if (tabsWrap) {
    /* the ink only starts animating once it has been placed, so the very
       first paint does not slide in from the left edge */
    window.setTimeout(function () { tabsWrap.classList.add("pa-ready"); }, 60);
  }
  window.addEventListener("resize", moveInk);
  window.addEventListener("load", moveInk);
  if (document.fonts && document.fonts.ready && document.fonts.ready.then) {
    document.fonts.ready.then(moveInk);
  }

  /* ------------------------------------------------------------------
     Hash routing. vfi-partner.html links straight to
     "vfi-partner-login.html#register", so that hash has to land on the
     registration wizard at step 1. On a fresh load the wizard is already
     at step 1; when the hash changes on a page that is *already* open we
     reset it explicitly so the link always means "start registering".
     ------------------------------------------------------------------ */
  function viewFromHash() {
    var h = (location.hash || "").toLowerCase();
    if (h === "#register" || h === "#signup") return "register";
    if (h === "#signin" || h === "#login") return "signin";
    return null;
  }

  var startView = viewFromHash();
  if (startView) showView(startView);

  window.addEventListener("hashchange", function () {
    var want = viewFromHash();
    if (!want || !forms[want]) return;
    /* already on that tab? leave any progress alone */
    if (forms[want].classList.contains("pa-on") && done && done.hidden) return;
    showView(want);
    if (want === "register") setStep(1, 0);
  });

  /* =================================================================
     MESSAGES (polite live region)
     ================================================================= */
  function showMsg(text, kind) {
    if (!msg) return;
    msg.textContent = text;
    msg.className = "pa-msg pa-msg--" + (kind || "bad");
    msg.hidden = false;
  }
  function hideMsg() {
    if (!msg) return;
    msg.hidden = true;
    msg.textContent = "";
  }

  /* =================================================================
     VALIDATION
     ================================================================= */
  function fieldOf(el) {
    return el && el.closest ? el.closest(".pa-field") : null;
  }

  function setError(input, text) {
    var f = fieldOf(input);
    if (!f) return;
    f.classList.add("pa-bad");
    var slot = $(".pa-field__err", f);
    if (slot) slot.textContent = text;
    input.setAttribute("aria-invalid", "true");
  }

  function clearError(input) {
    var f = fieldOf(input);
    if (!f) return;
    f.classList.remove("pa-bad");
    var slot = $(".pa-field__err", f);
    if (slot) slot.textContent = "";
    input.removeAttribute("aria-invalid");
  }

  function clearErrors(scope) {
    if (!scope) return;
    $$(".pa-bad", scope).forEach(function (f) { f.classList.remove("pa-bad"); });
    $$(".pa-field__err", scope).forEach(function (s) { s.textContent = ""; });
    $$("[aria-invalid]", scope).forEach(function (i) { i.removeAttribute("aria-invalid"); });
  }

  function validate(input) {
    var rule = input.getAttribute("data-pa-rule");
    var raw = input.value || "";
    var v = raw.replace(/^\s+|\s+$/g, "");

    if (rule === "agree") {
      if (!input.checked) {
        setError(input, "Please accept the terms before you continue.");
        return false;
      }
      clearError(input);
      return true;
    }

    if (!v) {
      setError(input, MISSING[rule] || "This field is required.");
      return false;
    }
    if (rule === "email" && !EMAIL.test(v)) {
      setError(input, "That does not look like a valid email address.");
      return false;
    }
    if (rule === "phone" && v.replace(/[^\d]/g, "").length < 8) {
      setError(input, "Use at least 8 digits, including your country code.");
      return false;
    }
    if ((rule === "agency" || rule === "person" || rule === "city") && v.length < 2) {
      setError(input, "That looks too short " + DASH + " use at least 2 characters.");
      return false;
    }
    if (rule === "newpass" && raw.length < 8) {
      setError(input, "Use at least 8 characters.");
      return false;
    }
    if (rule === "confirm") {
      var pw = $("#paRgPass");
      if (pw && raw !== pw.value) {
        setError(input, "The two passwords do not match.");
        return false;
      }
    }
    clearError(input);
    return true;
  }

  /* validates a scope and puts focus on the first field that failed */
  function validateScope(scope) {
    var ok = true;
    var first = null;
    $$("[data-pa-rule]", scope).forEach(function (i) {
      if (!validate(i)) {
        ok = false;
        if (!first) first = i;
      }
    });
    if (first) {
      try { first.focus(); } catch (e) {}
    }
    return ok;
  }

  /* clear an error as soon as the user starts fixing it */
  $$("[data-pa-rule]").forEach(function (i) {
    var ev = (i.type === "checkbox" || i.tagName === "SELECT") ? "change" : "input";
    i.addEventListener(ev, function () {
      /* these listeners are attached before the character rules below, so
         strip first — otherwise a re-validation could judge a value that
         is about to lose a character */
      scrub(i);
      var f = fieldOf(i);
      if (f && f.classList.contains("pa-bad")) validate(i);
    });
    i.addEventListener("blur", function () {
      if (i.type === "checkbox") return;
      if ((i.value || "").replace(/^\s+|\s+$/g, "")) validate(i);
    });
  });

  /* =================================================================
     PER-FIELD CHARACTER RULES

     data-pa-chars="digits|name|email" limits what a field can ever hold:

       digits  phone numbers and one-time codes
       name    people, agencies, cities — letters, space, hyphen,
               apostrophe, period. Latin, Greek, Cyrillic, Devanagari and
               Bengali letters all count, because our partners' names do
               not stop at ASCII.
       email   anything except whitespace

     Enforced three times over, because each layer misses a different way
     in:

       keypress  stops a disallowed key before it lands, which also means
                 the caret never has to be repaired;
       paste     cleans the clipboard before it is inserted;
       input     the backstop for autofill, drag-and-drop, IME composition
                 and bfcache restores. It rewrites the value only when
                 something genuinely has to come out, and puts the caret
                 back where the user left it.

     type="number" is deliberately never used for a phone: it brings
     spinners, silently drops leading zeros, and refuses to expose
     selectionStart so the caret cannot be preserved at all.
     ================================================================= */
  var NAME_OK = " A-Za-z\\u00C0-\\u024F\\u0370-\\u03FF\\u0400-\\u04FF\\u0900-\\u097F\\u0980-\\u09FF'\\u2019.\\-";

  var CHARS = {
    digits: { strip: /[^0-9]/g, one: /^[0-9]$/ },
    name: {
      strip: new RegExp("[^" + NAME_OK + "]", "g"),
      one: new RegExp("^[" + NAME_OK + "]$")
    },
    email: { strip: /\s+/g, one: /^\S$/ }
  };

  function charRule(el) {
    if (!el || !el.getAttribute) return null;
    return CHARS[el.getAttribute("data-pa-chars")] || null;
  }

  /* selectionStart is null — or throws — on input types that do not
     support text selection, type="email" among them */
  function caretOf(el) {
    try { return typeof el.selectionStart === "number" ? el.selectionStart : null; }
    catch (e) { return null; }
  }
  function caretEndOf(el) {
    try { return typeof el.selectionEnd === "number" ? el.selectionEnd : null; }
    catch (e) { return null; }
  }
  function putCaret(el, at) {
    if (at === null) return;
    try { el.setSelectionRange(at, at); } catch (e) {}
  }

  function fire(el, type) {
    var ev;
    try {
      ev = new Event(type, { bubbles: true, cancelable: false });
    } catch (e) {
      ev = document.createEvent("Event");
      ev.initEvent(type, true, false);
    }
    el.dispatchEvent(ev);
  }

  /* Strips the field to its allowed characters. Returns true only when it
     actually had to change something — staying idempotent matters, because
     it is also called from handlers that re-fire input. */
  function scrub(el) {
    var r = charRule(el);
    if (!r) return false;
    var v = el.value || "";
    var clean = v.replace(r.strip, "");
    if (clean === v) return false;
    var at = caretOf(el);
    var head = (at === null) ? null : v.slice(0, at).replace(r.strip, "").length;
    el.value = clean;
    putCaret(el, head);
    return true;
  }

  function onCharsInput(e) { scrub(e.target); }

  function onCharsKeypress(e) {
    var r = charRule(e.target);
    if (!r) return;
    if (e.ctrlKey || e.metaKey || e.altKey) return;   /* shortcuts stay alive */
    var k = e.key;
    if (typeof k !== "string" || k.length !== 1) return;  /* Enter, Tab, arrows … */
    if (r.one.test(k)) return;
    e.preventDefault();
  }

  function onCharsPaste(e) {
    var el = e.target;
    var r = charRule(el);
    if (!r) return;
    /* the code group splits a pasted code across six boxes itself */
    if (el.hasAttribute && el.hasAttribute("data-pa-otp")) return;

    var dt = e.clipboardData || window.clipboardData;
    if (!dt) return;                       /* no clipboard: the input handler cleans up */
    var txt = "";
    try { txt = dt.getData("text") || dt.getData("Text") || ""; } catch (err) { return; }

    e.preventDefault();
    var clean = String(txt).replace(r.strip, "");
    var v = el.value || "";
    var s = caretOf(el);
    var end = caretEndOf(el);

    if (s === null || end === null) {
      el.value = (v + clean).replace(r.strip, "");
    } else {
      el.value = (v.slice(0, s) + clean + v.slice(end)).replace(r.strip, "");
      putCaret(el, s + clean.length);
    }
    fire(el, "input");                     /* keep validation and meters in step */
  }

  function initChars(scope) {
    $$("[data-pa-chars]", scope || document).forEach(function (el) {
      if (el._paChars) return;             /* same _wired guard the rest of the site uses */
      el._paChars = true;
      el.addEventListener("keypress", onCharsKeypress);
      el.addEventListener("paste", onCharsPaste);
      el.addEventListener("input", onCharsInput);
      scrub(el);                           /* clean whatever the browser restored */
    });
  }
  /* runs before any page module, so the strip always happens first and
     every later input handler sees an already-clean value */
  initChars();

  /* =================================================================
     PHONE: the dialling-code <select> mirrored into its printed value
     ================================================================= */
  var dialSel = $("#paRgDial");
  var dialVal = $("#paRgDialVal");
  if (dialSel && dialVal) {
    var syncDial = function () { dialVal.textContent = dialSel.value || "+880"; };
    dialSel.addEventListener("change", syncDial);
    syncDial();                            /* respects a value restored on reload */
  }

  /* the number a real request would send: dialling code + local digits */
  function fullPhone() {
    var ph = $("#paRgPhone");
    if (!ph || !ph.value) return "";
    return (dialSel ? dialSel.value + " " : "") + ph.value;
  }

  /* =================================================================
     RESEND COOLDOWN — shared by the reset and verification pages
     ================================================================= */
  function makeCooldown(secs, btn, wrap, txt, ring) {
    var left = 0;
    var timer = 0;

    function paint() {
      if (ring) ring.style.setProperty("--pa-p", String(Math.round((left / secs) * 100)));
      if (txt) txt.textContent = "available in " + left + "s";
    }
    function release() {
      window.clearInterval(timer);
      timer = 0;
      left = 0;
      if (wrap) wrap.hidden = true;
      if (btn) btn.removeAttribute("aria-disabled");
    }
    function tick() {
      left--;
      if (left <= 0) { release(); return; }
      paint();
    }
    return {
      start: function () {
        window.clearInterval(timer);
        left = secs;
        if (btn) btn.setAttribute("aria-disabled", "true");
        if (wrap) wrap.hidden = false;
        paint();
        timer = window.setInterval(tick, 1000);
      },
      locked: function () { return left > 0; },
      left: function () { return left; }
    };
  }

  /* =================================================================
     PASSWORD AFFORDANCES
     ================================================================= */
  $$("[data-pa-eye]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var input = $(".pa-field__input", btn.parentNode);
      if (!input) return;
      var showing = input.type === "text";
      input.type = showing ? "password" : "text";
      btn.setAttribute("aria-label", showing ? "Show password" : "Hide password");
      var use = $("use", btn);
      if (use) use.setAttribute("href", showing ? "#pa-eye" : "#pa-eye-off");
      input.focus();
    });
  });

  /* caps-lock warning */
  $$(".pa-field--pw").forEach(function (f) {
    var input = $(".pa-field__input", f);
    var warn = $(".pa-field__caps", f);
    if (!input || !warn) return;
    var check = function (e) {
      if (typeof e.getModifierState !== "function") return;
      warn.hidden = !e.getModifierState("CapsLock");
    };
    input.addEventListener("keyup", check);
    input.addEventListener("keydown", check);
    input.addEventListener("blur", function () { warn.hidden = true; });
  });

  /* Empties every password field and puts it back to a masked, hidden
     state. Note the type check: once "show password" has been used the
     input is type="text", so selecting on [type=password] would miss it. */
  function resetPasswords(form) {
    $$(".pa-field--pw", form || document).forEach(function (f) {
      var input = $(".pa-field__input", f);
      var btn = $("[data-pa-eye]", f);
      var caps = $(".pa-field__caps", f);
      if (input) {
        input.value = "";
        input.type = "password";
      }
      if (btn) {
        btn.setAttribute("aria-label", "Show password");
        var use = $("use", btn);
        if (use) use.setAttribute("href", "#pa-eye");
      }
      if (caps) caps.hidden = true;
    });
  }

  /* strength meter (registration step 3 only) */
  function resetMeter() {
    if (meter) meter.setAttribute("data-pa-lvl", "0");
    if (meterLbl) meterLbl.textContent = STRENGTH[0];
  }

  var newPw = $("#paRgPass");
  if (newPw && meter) {
    newPw.addEventListener("input", function () {
      var v = newPw.value;
      var score = 0;
      if (v.length >= 8) score++;
      if (v.length >= 12) score++;
      if (/[A-Z]/.test(v) && /[a-z]/.test(v)) score++;
      if (/\d/.test(v) && /[^\w\s]/.test(v)) score++;
      score = v ? (Math.min(score, 4) || 1) : 0;
      meter.setAttribute("data-pa-lvl", String(score));
      if (meterLbl) meterLbl.textContent = STRENGTH[score];

      /* keep the confirm field honest while the password changes */
      var conf = $("#paRgPass2");
      var cf = fieldOf(conf);
      if (conf && conf.value && cf && cf.classList.contains("pa-bad")) validate(conf);
    });
  }

  /* a link inside the terms label should open the link, not tick the box */
  $$(".pa-check a").forEach(function (a) {
    a.addEventListener("click", function (e) { e.stopPropagation(); });
  });

  /* =================================================================
     FOCUS GLOW — a soft light that follows whatever has focus
     ================================================================= */
  function positionGlow(el) {
    if (!card || !glow || !el || !el.getBoundingClientRect) return;
    var c = card.getBoundingClientRect();
    var r = el.getBoundingClientRect();
    if (!r.width && !r.height) return;
    var x = Math.round(r.left - c.left + r.width / 2);
    var y = Math.round(r.top - c.top + r.height / 2);
    glow.style.transform = "translate3d(" + x + "px," + y + "px,0)";
    card.classList.add("pa-glow");
  }

  if (card) {
    card.addEventListener("focusin", function (e) { positionGlow(e.target); });
    card.addEventListener("focusout", function () {
      window.setTimeout(function () {
        if (!card.contains(document.activeElement)) card.classList.remove("pa-glow");
      }, 80);
    });
  }

  /* =================================================================
     BUTTON RIPPLE
     ================================================================= */
  function ripple(btn, e) {
    if (calm()) return;
    var r = btn.getBoundingClientRect();
    var s = document.createElement("span");
    s.className = "pa-ripple";
    s.style.left = (e && e.clientX ? e.clientX - r.left : r.width / 2) + "px";
    s.style.top = (e && e.clientY ? e.clientY - r.top : r.height / 2) + "px";
    btn.appendChild(s);
    window.setTimeout(function () {
      if (s.parentNode) s.parentNode.removeChild(s);
    }, 700);
  }

  $$(".pa-btn").forEach(function (b) {
    b.addEventListener("click", function (e) { ripple(b, e); });
  });

  /* =================================================================
     REGISTRATION WIZARD
     ================================================================= */
  function currentStepEl() {
    var el = null;
    stepEls.forEach(function (s) {
      if (Number(s.getAttribute("data-pa-step")) === current) el = s;
    });
    return el || forms.register;
  }

  function paintStep(n) {
    stepEls.forEach(function (s) {
      s.classList.toggle("pa-on", Number(s.getAttribute("data-pa-step")) === n);
    });
    progItems.forEach(function (it) {
      var i = Number(it.getAttribute("data-pa-dot"));
      it.classList.toggle("pa-on", i === n);
      it.classList.toggle("pa-past", i < n);
    });
    if (progFill && stepEls.length > 1) {
      progFill.style.width = ((n - 1) / (stepEls.length - 1)) * 100 + "%";
    }
    if (stepNote) stepNote.textContent = STEP_NOTE[n - 1] || "";
  }

  function endHeight() {
    if (!steps) return;
    steps.style.height = "";
    steps.classList.remove("pa-clip");
  }

  function setStep(n, dir) {
    if (!steps || n < 1 || n > stepEls.length) return;

    var from = steps.offsetHeight;
    steps.style.height = "";
    steps.classList.remove("pa-fwd", "pa-rev");
    void steps.offsetWidth;            /* flush, so the slide restarts cleanly */

    current = n;
    paintStep(n);

    if (calm() || !dir) { endHeight(); return; }

    steps.classList.add(dir > 0 ? "pa-fwd" : "pa-rev");

    var to = steps.offsetHeight;
    if (!from || from === to) { endHeight(); return; }

    /* clip only while the height animates, then release it again so no
       clip region can ever sit over a focused field's shadow */
    steps.classList.add("pa-clip");
    steps.style.height = from + "px";
    void steps.offsetHeight;
    steps.style.height = to + "px";
    window.clearTimeout(heightTimer);
    heightTimer = window.setTimeout(endHeight, 620);
  }

  paintStep(current);   /* make the wizard's starting state explicit */

  if (steps) {
    steps.addEventListener("transitionend", function (e) {
      if (e.target !== steps || e.propertyName !== "height") return;
      window.clearTimeout(heightTimer);
      endHeight();
    });
  }

  $$("[data-pa-back]").forEach(function (b) {
    b.addEventListener("click", function () {
      hideMsg();
      setStep(Number(b.getAttribute("data-pa-back")), -1);
      var f = $(".pa-field__input", currentStepEl());
      if (f) f.focus();
    });
  });

  /* =================================================================
     SUBMIT — local only
     ================================================================= */
  /* the real payloads (the old `collect()` never existed — assemble from the
     markup: register spans all three [data-pa-step] subtrees) */
  function collectSignin(form) {
    return {
      email: (form.elements.email.value || "").trim(),
      password: form.elements.password.value,
      remember: form.elements.remember ? !!form.elements.remember.checked : true
    };
  }
  function collectRegister() {
    return {
      agency: ($("#paRgAgency").value || "").trim(),
      country: $("#paRgCountry").value,
      city: ($("#paRgCity").value || "").trim(),
      person: ($("#paRgPerson").value || "").trim(),
      email: ($("#paRgEmail").value || "").trim(),
      dial: dialSel ? dialSel.value : "+880",
      phone: ($("#paRgPhone").value || "").trim(),
      password: $("#paRgPass").value,
      password_confirmation: $("#paRgPass2").value,
      agree: $("#paRgAgree") ? !!$("#paRgAgree").checked : false
    };
  }

  function submitDemo(form, kind) {
    hideMsg();
    var scope = (kind === "register") ? currentStepEl() : form;
    var btn = $('button[type="submit"]', scope);
    if (btn) {
      btn.classList.add("pa-busy");
      btn.setAttribute("aria-busy", "true");
    }
    var release = function () {
      if (btn) { btn.classList.remove("pa-busy"); btn.removeAttribute("aria-busy"); }
      resetPasswords(form);
      resetMeter();
    };

    var body = (kind === "register") ? collectRegister() : collectSignin(form);

    window.VFIApi.post("/api/partner/" + (kind === "register" ? "register" : "signin"), body, { noRedirect: true })
      .then(function (data) {
        release();
        if (kind === "signin") { window.location.href = "partner-dashboard.html"; return; }
        /* registration → verify page, carrying only an opaque flow_id (the email
           address never rides in the URL); stash the masked address for display */
        try {
          if (data && data.email_masked && window.sessionStorage) {
            sessionStorage.setItem("vfi_pflow_" + data.flow_id, data.email_masked);
          }
        } catch (e) { /* storage blocked — verify page falls back to the API */ }
        window.location.href = "vfi-partner-verify.html?flow=" + encodeURIComponent(data.flow_id);
      })
      .catch(function (err) {
        release();
        var msg;
        if (err && err.status === 422 && err.body && err.body.errors) {
          msg = "Some details need another look — please check them and try again.";
        } else {
          msg = (err && err.body && err.body.message) ||
                (err && err.status ? "We couldn't complete that. Please check your details and try again."
                                   : "We couldn't reach the server. Please try again in a moment.");
        }
        showMsg(msg, "bad");
      });
  }

  if (forms.signin) {
    forms.signin.addEventListener("submit", function (e) {
      e.preventDefault();
      if (!validateScope(forms.signin)) {
        showMsg("Please fix the highlighted field and try again.", "bad");
        return;
      }
      submitDemo(forms.signin, "signin");
    });
  }

  if (forms.register) {
    /* one submit handler drives the whole wizard, so pressing Enter in a
       step-1 or step-2 field advances instead of submitting the form */
    forms.register.addEventListener("submit", function (e) {
      e.preventDefault();
      var scope = currentStepEl();
      if (!validateScope(scope)) {
        showMsg("Please fix the highlighted field before you continue.", "bad");
        return;
      }
      hideMsg();
      if (current < stepEls.length) {
        setStep(current + 1, 1);
        var f = $(".pa-field__input", currentStepEl());
        if (f) f.focus();
        return;
      }
      submitDemo(forms.register, "register");
    });
  }

  /* =================================================================
     LOCAL "FORM CHECKED" STATE — never a sign-in
     ================================================================= */
  var DONE_COPY = {
    signin: {
      head: "Sign-in form checked",
      sub: "This is the point where a real partner portal would take over.",
      t: "Your details are well formed",
      p: "Both fields passed every check this page can run in the browser."
    },
    register: {
      head: "Registration form checked",
      sub: "This is the point where a real partner portal would take over.",
      t: "All three steps are complete",
      p: "Your agency, contact and security details passed every check this page can run in the browser."
    }
  };

  var doneVerify = $("#paDoneVerify");

  function showDone(kind) {
    if (!done) return;
    var c = DONE_COPY[kind] || DONE_COPY.signin;
    if (tabsWrap) tabsWrap.hidden = true;
    if (views) views.hidden = true;
    hideMsg();
    if (title) title.textContent = c.head;
    if (sub) sub.textContent = c.sub;
    if (doneTitle) doneTitle.textContent = c.t;
    if (doneTxt) doneTxt.textContent = c.p;

    /* Registration only: read back the two composed values so it is
       visible that the dialling code is part of the number, and hand the
       address on to the verification page. */
    var rgEmail = $("#paRgEmail");
    var addr = rgEmail ? (rgEmail.value || "").replace(/^\s+|\s+$/g, "") : "";
    if (kind === "register") {
      var num = fullPhone();
      if (doneTxt && num) {
        doneTxt.textContent = c.p + " A real console would write to " + (addr || "your work email") +
          " and call " + num + ".";
      }
      if (doneVerify) {
        doneVerify.href = "vfi-partner-verify.html";   /* flow_id is carried by the live path, not this */
        doneVerify.hidden = false;
      }
    } else if (doneVerify) {
      doneVerify.hidden = true;
    }

    done.hidden = false;
    if (doneTitle) {
      try { doneTitle.focus(); } catch (e) {}
    }
  }

  function hideDone() {
    if (!done || done.hidden) return;
    done.hidden = true;
    if (tabsWrap) tabsWrap.hidden = false;
    if (views) views.hidden = false;
    moveInk();
  }

  if (doneBack) {
    doneBack.addEventListener("click", function () {
      var which = (forms.register && forms.register.classList.contains("pa-on")) ? "register" : "signin";
      hideDone();
      if (title) title.textContent = COPY[which].t;
      if (sub) sub.textContent = COPY[which].s;
      clearErrors(forms[which]);
      if (which === "register") setStep(1, 0);
      var f = $(".pa-field__input", which === "register" ? currentStepEl() : forms.signin);
      if (f) f.focus();
    });
  }

  /* =================================================================
     PASSWORD RESET — vfi-partner-forgot.html

       (a) ask for the address  →  (b) "check your inbox", showing it back,
       with a 30-second resend cooldown and a way back to (a).

     Nothing is looked up and no email is sent.
     ================================================================= */
  (function () {
    var ask = $("#paFgAsk");
    var sentView = $("#paFgSent");
    if (!ask || !sentView) return;

    var email = $("#paFgEmail");
    var addrOut = $("#paFgAddr");
    var sentTitle = $("#paFgSentTitle");
    var fgTitle = $("#paFgTitle");
    var fgSub = $("#paFgSub");
    var resend = $("#paFgResend");
    var wrong = $("#paFgWrong");
    var codeLink = $("#paFgCode");

    var cool = makeCooldown(30, resend, $("#paFgCool"), $("#paFgCoolTxt"), $("#paFgCoolRing"));

    var ASK_T = fgTitle ? fgTitle.textContent : "";
    var ASK_S = fgSub ? fgSub.textContent : "";
    var SENT_T = "Reset link on its way";
    var SENT_S = "Nothing was really emailed " + DASH + " this page has no server behind it " + DASH +
      " but this is the screen a partner would land on.";

    var sentTo = "";

    function toSent(address) {
      sentTo = address;
      if (addrOut) addrOut.textContent = address;
      /* reset now arrives as an emailed token link (vfi-partner-reset.html); no
         address ever rides in a URL */
      if (codeLink) codeLink.href = "vfi-partner-login.html";
      ask.classList.remove("pa-on");
      sentView.classList.add("pa-on");
      if (fgTitle) fgTitle.textContent = SENT_T;
      if (fgSub) fgSub.textContent = SENT_S;
      cool.start();
      if (sentTitle) { try { sentTitle.focus(); } catch (e) {} }
    }

    function toAsk() {
      hideMsg();
      sentView.classList.remove("pa-on");
      ask.classList.add("pa-on");
      if (fgTitle) fgTitle.textContent = ASK_T;
      if (fgSub) fgSub.textContent = ASK_S;
      if (email) {
        if (sentTo) email.value = sentTo;
        try { email.focus(); email.select(); } catch (e) {}
      }
    }

    ask.addEventListener("submit", function (e) {
      e.preventDefault();
      hideMsg();
      if (!validateScope(ask)) {
        showMsg("Please fix the highlighted field and try again.", "bad");
        return;
      }
      var btn = $('button[type="submit"]', ask);
      if (btn) {
        btn.classList.add("pa-busy");
        btn.setAttribute("aria-busy", "true");
      }
      var address = (email.value || "").replace(/^\s+|\s+$/g, "");

      /* Fire-and-forget + identical UI whether or not the address exists (the
         server answers the same, so this can't become an enumeration oracle). */
      window.VFIApi.post("/api/partner/password/forgot", { email: address }, { noRedirect: true })
        .catch(function () { /* never reveal success/failure */ });

      window.setTimeout(function () {
        if (btn) {
          btn.classList.remove("pa-busy");
          btn.removeAttribute("aria-busy");
        }
        toSent(address);
      }, calm() ? 250 : 900);
    });

    if (resend) {
      resend.addEventListener("click", function () {
        if (cool.locked()) {
          showMsg("Hold on " + DASH + " you can ask for another email in " + cool.left() + " seconds.", "bad");
          return;
        }
        window.VFIApi.post("/api/partner/password/forgot",
          { email: (email.value || "").replace(/^\s+|\s+$/g, "") }, { noRedirect: true })
          .catch(function () {});
        showMsg("We've sent it again — give it a minute to arrive.", "ok");
        cool.start();
      });
    }

    if (wrong) wrong.addEventListener("click", toAsk);
  })();

  /* =================================================================
     EMAIL VERIFICATION — vfi-partner-verify.html

     Six single-character boxes behaving as one control: typing advances,
     Backspace on an empty box retreats, arrows move, and a pasted code
     fills every box at once wherever it is dropped. Any six digits are
     accepted; there is nothing to check them against.
     ================================================================= */
  (function () {
    var form = $("#paVfForm");
    var group = $("#paVfOtp");
    if (!form || !group) return;

    var boxes = $$(".pa-otp__in", group);
    if (!boxes.length) return;

    var field = $("#paVfField");
    var errSlot = $("#paVfErr");
    var addrOut = $("#paVfAddr");
    var vfTitle = $("#paVfTitle");
    var vfSub = $("#paVfSub");
    var doneBox = $("#paVfDone");
    var doneTtl = $("#paVfDoneTitle");
    var doneTx = $("#paVfDoneTxt");
    var doneBk = $("#paVfDoneBack");
    var goBtn = $("#paVfGo");
    var resend = $("#paVfResend");
    var wrong = $("#paVfWrong");
    var mail = $("#paVfMail");
    var mailIn = $("#paVfEmail");
    var mailCancel = $("#paVfMailCancel");
    var vws = $("#paViews");

    var cool = makeCooldown(30, resend, $("#paVfCool"), $("#paVfCoolTxt"), $("#paVfCoolRing"));

    var T0 = vfTitle ? vfTitle.textContent : "";
    var S0 = vfSub ? vfSub.textContent : "";
    var GENERIC = addrOut ? addrOut.textContent : "the address on your application";

    var address = "";
    var busy = false;

    /* ---------------------------------------------------- the address */
    /* State travels as an opaque flow id — NEVER the email — so nothing
       sensitive lands in the URL, history or referrers. */
    function queryFlow() {
      var m = /[?&]flow=([^&#]*)/.exec(location.search || "");
      if (!m) return "";
      try { return decodeURIComponent(m[1]); } catch (e) { return m[1]; }
    }
    var flowId = queryFlow();

    /* ab•••@domain.com — the server sends it already masked; kept for masking a
       newly-typed address in the change-confirmation toast */
    function maskEmail(a) {
      var at = String(a || "").indexOf("@");
      if (at < 1 || at === String(a).length - 1) return "";
      var local = a.slice(0, at);
      var keep = local.length > 2 ? 2 : 1;
      return local.slice(0, keep) + "•••" + a.slice(at);
    }

    function paintMasked(masked) {
      if (addrOut) addrOut.textContent = masked || GENERIC;
    }

    /* resolve the masked address for display without any email in the URL:
       prefer the value stashed at registration, else ask the server by flow_id */
    (function resolveAddress() {
      var stashed = "";
      try { if (window.sessionStorage) stashed = sessionStorage.getItem("vfi_pflow_" + flowId) || ""; } catch (e) { /* blocked */ }
      if (stashed) { paintMasked(stashed); return; }
      if (flowId && window.VFIApi) {
        window.VFIApi.get("/api/partner/verify/context?flow_id=" + encodeURIComponent(flowId), { noRedirect: true })
          .then(function (data) { paintMasked(data && data.email_masked); })
          .catch(function () { paintMasked(""); });
      } else {
        paintMasked("");
      }
    })();

    /* ------------------------------------------------------ the boxes */
    function code() {
      var s = "";
      boxes.forEach(function (b) { s += (b.value || "").charAt(0) || ""; });
      return s;
    }
    function complete() { return code().length === boxes.length; }

    function paintBoxes() {
      boxes.forEach(function (b) { b.classList.toggle("pa-filled", !!b.value); });
    }

    /* Never input.select() here: in Chrome select() also *focuses*, so a
       deferred select and the auto-advance below chase each other round
       the group forever. setSelectionRange only moves the caret. */
    function selectBox(b) {
      try { b.setSelectionRange(0, (b.value || "").length); } catch (e) {}
    }

    function focusBox(i) {
      if (i < 0 || i >= boxes.length) return;
      try { boxes[i].focus(); } catch (e) {}
      selectBox(boxes[i]);
    }

    function clearCode(refocus) {
      boxes.forEach(function (b) { b.value = ""; });
      paintBoxes();
      if (refocus) focusBox(0);
    }

    function badCode(text) {
      if (field) field.classList.add("pa-bad");
      if (errSlot) errSlot.textContent = text;
      boxes.forEach(function (b) { b.setAttribute("aria-invalid", "true"); });
    }
    function goodCode() {
      if (field) field.classList.remove("pa-bad");
      if (errSlot) errSlot.textContent = "";
      boxes.forEach(function (b) { b.removeAttribute("aria-invalid"); });
    }

    boxes.forEach(function (b, i) {
      /* Selecting on focus is what makes a full box replaceable: with
         maxlength="1" and the caret parked after the digit, a keystroke
         would otherwise be swallowed. Deferred by a tick so a click's own
         caret placement does not undo it, and guarded on the box still
         holding focus so a stale timer cannot drag focus backwards. */
      b.addEventListener("focus", function () {
        window.setTimeout(function () {
          if (document.activeElement === b) selectBox(b);
        }, 0);
      });

      b.addEventListener("input", function () {
        /* the digits-only rule has already run: this only has to deal
           with length and with where the caret goes next */
        var v = b.value || "";
        if (v.length > 1) b.value = v.charAt(v.length - 1);
        paintBoxes();
        if (field && field.classList.contains("pa-bad")) goodCode();
        if (b.value && i < boxes.length - 1) focusBox(i + 1);
        maybeVerify();
      });

      b.addEventListener("keydown", function (e) {
        var k = e.key;
        if (k === "Backspace") {
          if (!b.value && i > 0) {
            e.preventDefault();
            boxes[i - 1].value = "";
            paintBoxes();
            focusBox(i - 1);
          }
          return;
        }
        if (k === "Delete") { b.value = ""; paintBoxes(); return; }
        if (k === "ArrowLeft") { e.preventDefault(); focusBox(i - 1); return; }
        if (k === "ArrowRight") { e.preventDefault(); focusBox(i + 1); return; }
        if (k === "ArrowUp" || k === "ArrowDown") { e.preventDefault(); return; }
        if (k === "Home") { e.preventDefault(); focusBox(0); return; }
        if (k === "End") { e.preventDefault(); focusBox(boxes.length - 1); return; }
      });

      /* The generic paste handler stands aside for [data-pa-otp] so this
         one can spread a code across the whole group. */
      b.addEventListener("paste", function (e) {
        var dt = e.clipboardData || window.clipboardData;
        if (!dt) return;
        var txt = "";
        try { txt = dt.getData("text") || dt.getData("Text") || ""; } catch (err) { return; }
        e.preventDefault();

        var digits = String(txt).replace(/[^0-9]/g, "");
        if (!digits) return;

        /* a whole code always starts at box 1, wherever it was dropped */
        var start = digits.length >= boxes.length ? 0 : i;
        var n = 0;
        var j;
        for (j = start; j < boxes.length && n < digits.length; j++) {
          boxes[j].value = digits.charAt(n);
          n++;
        }
        paintBoxes();
        if (field && field.classList.contains("pa-bad")) goodCode();
        focusBox(Math.min(start + n, boxes.length - 1));
        maybeVerify();
      });
    });

    /* ----------------------------------------------------- verifying */
    function maybeVerify() {
      if (busy || !complete()) return;
      busy = true;                          /* one attempt per completion */
      window.setTimeout(function () {
        busy = false;
        if (complete()) verify();
      }, calm() ? 0 : 200);
    }

    function verify() {
      if (busy) return;
      hideMsg();

      if (!complete()) {
        badCode("Enter all six digits of the code.");
        showMsg("The code is not complete yet.", "bad");
        var i = 0;
        while (i < boxes.length && boxes[i].value) i++;
        focusBox(Math.min(i, boxes.length - 1));
        return;
      }
      goodCode();
      busy = true;
      if (goBtn) {
        goBtn.classList.add("pa-busy");
        goBtn.setAttribute("aria-busy", "true");
      }

      var release = function () {
        busy = false;
        if (goBtn) { goBtn.classList.remove("pa-busy"); goBtn.removeAttribute("aria-busy"); }
      };

      /* Real check. A wrong code comes back {ok:false} with a 200, so it
         resolves (not throws); transport failures land in .catch. */
      window.VFIApi.post("/api/partner/email/verify", { flow_id: flowId, code: code() }, { noRedirect: true })
        .then(function (data) {
          release();
          if (data && data.ok) {
            try { if (flowId && window.sessionStorage) sessionStorage.removeItem("vfi_pflow_" + flowId); } catch (e) {}
            showVerified();
            return;
          }
          badCode("That code is not right, or it has expired.");
          showMsg((data && data.message) || "We couldn't verify that code.", "bad");
          clearCode(true);
        })
        .catch(function (err) {
          release();
          badCode("We couldn't check that code.");
          showMsg((err && err.body && err.body.message) || "We couldn't reach the server. Please try again in a moment.", "bad");
          clearCode(true);
        });
    }

    function showVerified() {
      if (!doneBox) return;
      hideMsg();
      if (vws) vws.hidden = true;
      if (vfTitle) vfTitle.textContent = "Email verified";
      if (vfSub) {
        vfSub.textContent = "Your email address is confirmed. Our partner team reviews every " +
          "application before your account goes live — we'll email you when it's approved.";
      }
      if (doneTx) {
        doneTx.textContent = "Thanks for confirming your email. You'll be able to sign in once your agency is approved.";
      }
      doneBox.hidden = false;
      if (doneTtl) { try { doneTtl.focus(); } catch (e) {} }
    }

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      verify();
    });

    if (doneBk) {
      doneBk.addEventListener("click", function () {
        doneBox.hidden = true;
        if (vws) vws.hidden = false;
        if (vfTitle) vfTitle.textContent = T0;
        if (vfSub) vfSub.textContent = S0;
        goodCode();
        clearCode(true);
      });
    }

    /* ------------------------------------------------------- resend */
    if (resend) {
      resend.addEventListener("click", function () {
        if (cool.locked()) {
          showMsg("Hold on " + DASH + " you can ask for another code in " + cool.left() + " seconds.", "bad");
          return;
        }
        window.VFIApi.post("/api/partner/email/code", { flow_id: flowId }, { noRedirect: true })
          .then(function () {
            goodCode();
            clearCode(true);
            showMsg("A fresh six-digit code is on its way — the last one no longer works.", "ok");
            cool.start();
          })
          .catch(function (err) {
            showMsg((err && err.body && err.body.message) ||
              "We couldn't send a new code just now. Please wait a moment and try again.", "bad");
          });
      });
    }

    /* -------------------------------------------- change the address */
    function showMail(on) {
      if (!mail) return;
      mail.hidden = !on;
      form.classList.toggle("pa-on", !on);
      if (on && mailIn) {
        mailIn.value = "";   /* type the NEW address; the old one is never echoed */
        try { mailIn.focus(); } catch (e) {}
      }
    }

    if (wrong) {
      wrong.addEventListener("click", function () { hideMsg(); showMail(true); });
    }
    if (mailCancel) {
      mailCancel.addEventListener("click", function () {
        hideMsg();
        clearErrors(mail);
        showMail(false);
        focusBox(0);
      });
    }
    if (mail) {
      mail.addEventListener("submit", function (e) {
        e.preventDefault();
        hideMsg();
        if (!validateScope(mail)) {
          showMsg("Please fix the highlighted field and try again.", "bad");
          return;
        }
        var btn = $('button[type="submit"]', mail);
        if (btn) {
          btn.classList.add("pa-busy");
          btn.setAttribute("aria-busy", "true");
        }
        var next = (mailIn.value || "").replace(/^\s+|\s+$/g, "");
        var release = function () {
          if (btn) { btn.classList.remove("pa-busy"); btn.removeAttribute("aria-busy"); }
        };

        /* Hardened: the change is authorised by possession of the server-side
           flow_id (NOT a URL ?email=), restarts the flow + invalidates prior
           codes, and is rate-limited server-side. */
        window.VFIApi.post("/api/partner/email/change", { flow_id: flowId, email: next }, { noRedirect: true })
          .then(function (data) {
            release();
            paintMasked((data && data.email_masked) || maskEmail(next));
            showMail(false);
            goodCode();
            clearCode(true);
            showMsg("A fresh code is on its way to " + ((data && data.email_masked) || maskEmail(next)) + ".", "ok");
            cool.start();
          })
          .catch(function (err) {
            release();
            showMsg((err && err.body && err.body.message) ||
              "We couldn't change that address just now. Please try again in a moment.", "bad");
          });
      });
    }

    /* --------------------------------------------------------- start */
    /* the masked address is resolved by flow_id in resolveAddress() above */
    paintBoxes();
    cool.start();     /* a code was just sent, so resending waits its turn */
  })();

  /* =================================================================
     PAGE — vfi-partner-reset.html : set a new password from a token
     Reached from the emailed reset link (?token=). Three states: the form,
     a success panel, and a dead-end for a missing/invalid/expired token.
     ================================================================= */
  (function () {
    var rsForm = $("#paRsForm");
    if (!rsForm) return;
    var rsPass = $("#paRsPass");
    var rsPass2 = $("#paRsPass2");
    var rsDone = $("#paRsDone");
    var rsBad = $("#paRsBad");
    var rsBadTxt = $("#paRsBadTxt");

    function queryToken() {
      var m = /[?&]token=([^&#]*)/.exec(location.search || "");
      if (!m) return "";
      try { return decodeURIComponent(m[1]); } catch (e) { return m[1]; }
    }
    var rsToken = queryToken();

    function show(which) {
      rsForm.classList.toggle("pa-on", which === "form");
      if (rsDone) rsDone.classList.toggle("pa-on", which === "done");
      if (rsBad) rsBad.classList.toggle("pa-on", which === "bad");
    }
    function fail(msg) {
      if (rsBadTxt && msg) rsBadTxt.textContent = msg;
      show("bad");
    }

    if (!rsToken) { fail("This reset link is missing or malformed. Request a new one below."); }

    rsForm.addEventListener("submit", function (e) {
      e.preventDefault();
      hideMsg();
      var pw = rsPass ? (rsPass.value || "") : "";
      var pw2 = rsPass2 ? (rsPass2.value || "") : "";
      if (pw.length < 8) { showMsg("Your new password needs at least 8 characters.", "bad"); if (rsPass) rsPass.focus(); return; }
      if (pw !== pw2) { showMsg("Those two passwords don't match.", "bad"); if (rsPass2) rsPass2.focus(); return; }

      var btn = $('button[type="submit"]', rsForm);
      if (btn) { btn.classList.add("pa-busy"); btn.setAttribute("aria-busy", "true"); }
      var release = function () { if (btn) { btn.classList.remove("pa-busy"); btn.removeAttribute("aria-busy"); } };

      window.VFIApi.post("/api/partner/password/reset/submit",
        { token: rsToken, password: pw, password_confirmation: pw2 }, { noRedirect: true })
        .then(function () {
          release();
          if (rsPass) rsPass.value = ""; if (rsPass2) rsPass2.value = "";
          show("done");
        })
        .catch(function (err) {
          release();
          if (rsPass) rsPass.value = ""; if (rsPass2) rsPass2.value = "";
          var body = err && err.body;
          if (err && err.status === 422 && body && body.errors && body.errors.password) {
            showMsg(body.errors.password[0], "bad");
            return;
          }
          fail((body && body.message) || "This reset link is invalid or has expired.");
        });
    });
  })();

  /* =================================================================
     BRAND NAME FROM SITE SETTINGS (same as the rest of the site)
     ================================================================= */
  try {
    if (window.VFI && VFI.settings) {
      var st = VFI.settings();
      if (st && st.brand) {
        var nm = $(".pa-lockup__name");
        if (nm) nm.textContent = st.brand;
        var mk = $(".pa-lockup__mark");
        if (mk && mk.firstChild && mk.firstChild.nodeType === 3) {
          mk.firstChild.nodeValue = st.brand.replace(/^\s+/, "").charAt(0).toUpperCase();
        }
      }
    }
  } catch (e) {}
})();
