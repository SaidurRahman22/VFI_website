/* ==========================================================================
   auth.js — the student auth screens

     login.html            sign in / create account
     student-forgot.html   password reset request
     student-verify.html   email verification (6-digit code)

     student-reset.html    choose a new password (from the emailed link)

   One script for all four: they share a card, a status strip, a live region,
   the field validators, the character rules and the success overlay, so they
   share the code that drives them. Each page tells this file who it is with
   `data-sa-page="<filename>"` on <body>; every module below then wires itself
   only if the markup it needs is actually on the page.

   LIVE (Phase 4): every flow posts to the Laravel backend through
   window.VFIApi (same-origin cookie session + CSRF). Registration returns an
   opaque flow_id — the email address never rides in the URL; OTP codes and
   reset tokens are verified server-side (a wrong code is rejected). See
   docs/phases/phase-4-*.md and backend/app/Http/Controllers/Auth.

   Style note: this file matches the rest of the codebase — var, function
   expressions, string concatenation. No const/let, arrow functions or
   template literals.
   ========================================================================== */
(function () {
  "use strict";

  var $ = function (sel, ctx) { return (ctx || document).querySelector(sel); };
  var $$ = function (sel, ctx) {
    return Array.prototype.slice.call((ctx || document).querySelectorAll(sel));
  };

  /* Which of the three pages is this? The attribute is the source of truth so
     the guard below still names the right file when the page is served from a
     directory index or a rewritten path. */
  var PAGE = (document.body && document.body.getAttribute("data-sa-page")) ||
             (window.VFI && VFI.baseName ? VFI.baseName(location.pathname) : "") ||
             "login.html";

  /* ------------------------------------------------------------------------
     "Page switched off" guard.

     These pages deliberately skip site.js and render.js (they have no shared
     header or footer), so each carries its own copy of the notice the admin
     panel expects every page to honour.
     ------------------------------------------------------------------------ */
  try {
    if (window.VFI && VFI.pageEnabled && !VFI.pageEnabled(PAGE)) {
      var offMain = document.getElementById("main");
      if (offMain) {
        offMain.className = "sa-off";
        offMain.innerHTML =
          "<h1>This page is currently unavailable</h1>" +
          "<p>It has been switched off by the site administrator. " +
          "Please check back shortly, or contact your counsellor if you need something urgently.</p>" +
          '<a href="index.html">Back to the home page</a>';
        return;
      }
    }
  } catch (e) { /* storage blocked — carry on and show the page */ }

  /* =========================================================== shared refs */
  var card = $("#saCard");
  var note = $("#saMsg");
  var live = $("#saLive");
  var title = $("#saTitle");
  var sub = $("#saSub");

  /* ============================================================== messages */
  function announce(text) {
    if (!live) return;
    /* clearing first makes repeat messages announce again */
    live.textContent = "";
    setTimeout(function () { live.textContent = text; }, 60);
  }

  function showNote(text, kind) {
    if (!note) return;
    note.textContent = text;
    note.className = "sa-note sa-note--" + (kind || "err") + " sa-on";
    announce(text);
  }
  function hideNote() {
    if (!note) return;
    note.className = "sa-note";
  }

  /* ============================================================ validation */
  function fieldOf(input) {
    if (!input || !input.closest) return null;
    return input.closest(".sa-field") || input.closest(".sa-otp") || input.closest(".sa-check");
  }

  function setError(input, text) {
    var f = fieldOf(input);
    if (!f) return;
    /* re-trigger the shake even if the field was already flagged */
    f.classList.remove("sa-err");
    void f.offsetWidth;
    f.classList.add("sa-err");

    var slot = $(".sa-field__err", f);
    if (slot) {
      var span = $("span", slot);
      if (span) span.textContent = text;
      else slot.textContent = text;
    }
    input.setAttribute("aria-invalid", "true");
  }

  function clearError(input) {
    var f = fieldOf(input);
    if (!f) return;
    f.classList.remove("sa-err");
    input.removeAttribute("aria-invalid");
  }

  function clearErrors(form) {
    if (!form) return;
    $$(".sa-err", form).forEach(function (f) { f.classList.remove("sa-err"); });
    $$("[aria-invalid]", form).forEach(function (i) { i.removeAttribute("aria-invalid"); });
  }

  var EMAIL = /^[^\s@]+@[^\s@]+\.[a-z]{2,}$/i;

  function validate(input) {
    var name = input.name;
    var v = (input.value || "").trim();

    if (input.type === "checkbox") {
      if (input.required && !input.checked) {
        setError(input, "Please accept the terms to continue.");
        return false;
      }
      clearError(input);
      return true;
    }

    if (input.required && !v) {
      setError(input, "This field can't be left empty.");
      return false;
    }
    if (name === "email" && v && !EMAIL.test(v)) {
      setError(input, "That doesn't look like a valid email address.");
      return false;
    }
    if (name === "phone" && v.replace(/[^\d]/g, "").length < 8) {
      setError(input, "Enter a phone number we can actually reach.");
      return false;
    }
    if (name === "name" && v.length < 2) {
      setError(input, "Enter your full name.");
      return false;
    }
    if (name === "password" && input.autocomplete === "new-password" && v.length < 8) {
      setError(input, "Use at least 8 characters.");
      return false;
    }

    clearError(input);
    return true;
  }

  function validateForm(form) {
    var ok = true;
    var first = null;
    $$("input", form).forEach(function (i) {
      if (i.classList.contains("sa-otp__box")) return;   /* the OTP group checks itself */
      if (!validate(i)) {
        ok = false;
        if (!first) first = i;
      }
    });
    if (first) first.focus();
    return ok;
  }

  /* ==========================================================================
     PER-FIELD CHARACTER RULES

     Declared in the markup with data-sa-mask="digits|name|nospace" and
     enforced three ways, because one is never enough:

       keypress  refuses the character before it lands (so the caret never
                 jumps and nothing flickers)
       paste     sanitises the clipboard text and inserts the clean version
       input     the safety net — autofill, drag-and-drop, IME composition and
                 Android soft keyboards all bypass keypress. Disallowed
                 characters are stripped and the caret is put back where the
                 user left it.

     Never `type="number"` for a phone: it brings spinners, it silently drops
     leading zeros, and its value is "" the moment the text is not a valid
     number. `inputmode="numeric"` gets the same keypad with none of that.
     ========================================================================== */
  /* What a name may contain, assembled from escape sequences rather than
     literal glyphs so the rule cannot be broken by a charset guess:
     A-Z a-z, accented Latin (Latin-1 Supplement + Extended-A/B), Bengali,
     then space, hyphen, straight apostrophe, curly apostrophe, period. */
  var NAME_OK = "A-Za-z\\u00C0-\\u024F\\u0980-\\u09FF \\-'\\u2019.";

  var RULES = {
    /* digits only — phone numbers and one-time codes */
    digits: {
      strip: /[^0-9]/g,
      bad: /[^0-9]/,
      hint: "This field takes digits only."
    },
    /* letters, spaces, hyphen, apostrophe, period */
    name: {
      strip: new RegExp("[^" + NAME_OK + "]", "g"),
      bad: new RegExp("[^" + NAME_OK + "]"),
      hint: "Names take letters, spaces, hyphens, apostrophes and periods."
    },
    /* no whitespace anywhere — email addresses */
    nospace: {
      strip: /\s+/g,
      bad: /\s/,
      hint: "An email address can't contain spaces."
    }
  };

  var lastHint = 0;
  function nudge(el, rule) {
    if (el) {
      el.classList.remove("sa-nudge");
      void el.offsetWidth;
      el.classList.add("sa-nudge");
      setTimeout(function () { el.classList.remove("sa-nudge"); }, 520);
    }
    var now = +new Date();
    if (rule && rule.hint && now - lastHint > 1400) {
      lastHint = now;
      announce(rule.hint);
    }
  }

  function shakeTarget(input) {
    if (input.classList.contains("sa-otp__box")) return input;
    return input.closest ? input.closest(".sa-field__wrap") : null;
  }

  /* insert text at the caret without losing undo history where possible */
  function insertText(input, txt) {
    var ok = false;
    try {
      ok = !!(document.execCommand && document.execCommand("insertText", false, txt));
    } catch (e) { ok = false; }
    if (ok) return;

    /* selectionStart throws (or reads null) on type="email" in some engines */
    var s = null, e2 = null;
    try { s = input.selectionStart; e2 = input.selectionEnd; } catch (e) { s = null; }
    var v = input.value;
    if (typeof s === "number") {
      input.value = v.slice(0, s) + txt + v.slice(typeof e2 === "number" ? e2 : s);
      try { input.setSelectionRange(s + txt.length, s + txt.length); } catch (e) { /* email input */ }
    } else {
      input.value = v + txt;
    }
    fireInput(input);
  }

  function fireInput(input) {
    var ev;
    try {
      ev = new Event("input", { bubbles: true });
    } catch (e) {
      ev = document.createEvent("Event");
      ev.initEvent("input", true, false);
    }
    input.dispatchEvent(ev);
  }

  /* strip anything the rule disallows, keeping the caret where it was */
  function scrub(input, rule) {
    var before = input.value;
    if (!before) return false;
    var after = before.replace(rule.strip, "");
    if (after === before) return false;

    var s = null;
    try { s = input.selectionStart; } catch (e) { s = null; }
    input.value = after;
    if (typeof s === "number") {
      var kept = before.slice(0, s).replace(rule.strip, "").length;
      try { input.setSelectionRange(kept, kept); } catch (e) { /* email input */ }
    }
    return true;
  }

  function applyRule(input, rule, opts) {
    var o = opts || {};

    input.addEventListener("keypress", function (e) {
      if (e.ctrlKey || e.metaKey || e.altKey) return;
      var ch = e.key;
      /* only single printable characters — Enter, Tab, arrows have longer keys */
      if (typeof ch !== "string" || ch.length !== 1) return;
      if (rule.bad.test(ch)) {
        e.preventDefault();
        nudge(shakeTarget(input), rule);
      }
    });

    if (!o.skipPaste) {
      input.addEventListener("paste", function (e) {
        var dt = e.clipboardData || window.clipboardData;
        if (!dt) return;                     /* no clipboard API — the input handler cleans up */
        var raw = String(dt.getData("text") || "");
        var clean = raw.replace(rule.strip, "");
        if (clean === raw) return;           /* nothing to strip — let the browser paste it */
        e.preventDefault();
        insertText(input, clean);
        nudge(shakeTarget(input), rule);
      });
    }

    if (!o.skipInput) {
      input.addEventListener("input", function () {
        if (scrub(input, rule)) nudge(shakeTarget(input), rule);
      });
    }
  }

  /* wire every field that declares a rule (the OTP boxes handle their own
     paste, so they opt out of that half — see the OTP module below) */
  $$("[data-sa-mask]").forEach(function (input) {
    var rule = RULES[input.getAttribute("data-sa-mask")];
    if (!rule) return;
    var isOtp = input.classList.contains("sa-otp__box");
    applyRule(input, rule, { skipPaste: isOtp, skipInput: isOtp });
  });

  /* ================================================== password affordances */
  $$(".sa-field__eye").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var input = $("input", btn.parentNode);
      if (!input) return;
      var showing = input.type === "text";
      input.type = showing ? "password" : "text";
      btn.setAttribute("aria-label", showing ? "Show password" : "Hide password");
      btn.setAttribute("aria-pressed", showing ? "false" : "true");
      var use = $("use", btn);
      if (use) use.setAttribute("href", showing ? "#a-eye" : "#a-eye-off");
      input.focus();
    });
  });

  /* Caps-Lock warning */
  $$('input[type="password"]').forEach(function (input) {
    var warn = $(".sa-field__caps", fieldOf(input) || document);
    if (!warn) return;
    var check = function (e) {
      if (typeof e.getModifierState !== "function") return;
      warn.classList.toggle("sa-on", e.getModifierState("CapsLock"));
    };
    input.addEventListener("keyup", check);
    input.addEventListener("keydown", check);
    input.addEventListener("blur", function () { warn.classList.remove("sa-on"); });
  });

  /* ================================================== submit button motion */
  function ripple(btn, e) {
    var rect = btn.getBoundingClientRect();
    var x = (e && e.clientX) ? e.clientX - rect.left : rect.width / 2;
    var y = (e && e.clientY) ? e.clientY - rect.top : rect.height / 2;
    var dot = document.createElement("span");
    dot.className = "sa-ripple";
    dot.style.left = x + "px";
    dot.style.top = y + "px";
    btn.appendChild(dot);
    setTimeout(function () {
      if (dot.parentNode) dot.parentNode.removeChild(dot);
    }, 700);
  }

  $$(".sa-submit").forEach(function (btn) {
    if (btn.tagName !== "BUTTON") return;
    btn.addEventListener("click", function (e) {
      if (btn.classList.contains("sa-busy")) return;
      ripple(btn, e);
    });
  });

  function setBusy(btn) {
    if (!btn) return;
    btn.classList.add("sa-busy");
    btn.setAttribute("aria-busy", "true");
  }

  /* snap the progress fill back to zero without animating the rewind */
  function resetFill(btn) {
    if (!btn) return;
    var fill = $(".sa-submit__fill", btn);
    btn.classList.remove("sa-busy");
    btn.removeAttribute("aria-busy");
    if (!fill) return;
    fill.style.transition = "none";
    void fill.offsetWidth;
    fill.style.transition = "";
  }

  /* ======================================================== success screen */
  var done = $("#saDone");
  var doneTitle = $("#saDoneTitle");
  var doneMsg = $("#saDoneMsg");
  var doneBack = $("#saDoneBack");
  var doneNext = $("#saDoneNext");
  var onHide = [];

  /* Everything the overlay covers gets hidden from screen readers while it is
     up — every card child except the overlay itself and the live region,
     which has to stay announceable. Focus is moved out first, so this is safe. */
  var coverable = [];
  if (card) {
    Array.prototype.slice.call(card.children).forEach(function (el) {
      if (el !== done && el !== live) coverable.push(el);
    });
  }

  function showDone(t, m) {
    if (!done) return;
    if (doneTitle && t) doneTitle.textContent = t;
    if (doneMsg && m) doneMsg.textContent = m;

    coverable.forEach(function (el) { el.setAttribute("aria-hidden", "true"); });
    done.setAttribute("aria-hidden", "false");
    done.classList.add("sa-on");

    if (doneTitle) doneTitle.focus();
    announce((t || "") + ". " + (m || ""));
  }

  function hideDone(refocus) {
    if (!done || !done.classList.contains("sa-on")) return;
    done.classList.remove("sa-on");
    done.setAttribute("aria-hidden", "true");
    coverable.forEach(function (el) { el.removeAttribute("aria-hidden"); });
    onHide.forEach(function (fn) { fn(refocus); });
  }

  if (doneBack) {
    doneBack.addEventListener("click", function () { hideDone(true); });
  }
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape" && done && done.classList.contains("sa-on")) hideDone(true);
  });

  /* ==========================================================================
     RESEND COOLDOWN

     Shared by student-forgot.html and student-verify.html. aria-disabled, not
     [disabled]: the button disables itself on its own click, and the real
     attribute would drop the keyboard user's focus back to <body>.
     ========================================================================== */
  function Cooldown(btn, txtEl, hintEl, opts) {
    var o = opts || {};
    var secs = 0;
    var timer = null;

    function paint() {
      if (!btn) return;
      var waiting = secs > 0;
      btn.setAttribute("aria-disabled", waiting ? "true" : "false");
      if (txtEl) txtEl.textContent = waiting ? o.wait.replace("{s}", String(secs)) : o.idle;
      if (hintEl) {
        hintEl.textContent = waiting
          ? o.waitHint.replace("{s}", String(secs)).replace("{p}", secs === 1 ? "" : "s")
          : (o.idleHint || "");
      }
    }

    function stop() {
      if (timer) { clearInterval(timer); timer = null; }
    }

    return {
      running: function () { return secs > 0; },
      start: function (n) {
        stop();
        secs = n || 30;
        paint();
        timer = setInterval(function () {
          secs--;
          if (secs <= 0) {
            secs = 0;
            stop();
            paint();
            if (o.onReady) o.onReady();
            return;
          }
          paint();
        }, 1000);
      }
    };
  }

  var COOLDOWN_SECONDS = 30;

  /* ============================================================== helpers */
  function query(name) {
    var m = new RegExp("[?&]" + name + "=([^&#]*)").exec(location.search);
    if (!m) return "";
    try { return decodeURIComponent(m[1].replace(/\+/g, " ")); } catch (e) { return ""; }
  }

  /* ab(dot dot dot)@domain.com — enough to recognise, not enough to leak.
     The dots are U+2022 bullets, built from a char code for the same reason
     the name rule is. */
  var DOTS = String.fromCharCode(8226, 8226, 8226);

  function maskEmail(addr) {
    var v = String(addr || "").trim();
    var at = v.indexOf("@");
    if (at < 1 || !EMAIL.test(v)) return "";
    var local = v.slice(0, at);
    var keep = local.length > 2 ? 2 : 1;
    return local.slice(0, keep) + DOTS + v.slice(at);
  }

  /* clear an error the moment the user starts fixing it */
  $$(".sa-form input, .sa-step input").forEach(function (i) {
    i.addEventListener("input", function () {
      var f = fieldOf(i);
      if (f && f.classList.contains("sa-err")) validate(i);
      if (note && note.classList.contains("sa-on")) hideNote();
    });
    i.addEventListener("change", function () {
      if (i.type === "checkbox") validate(i);
    });
    i.addEventListener("blur", function () {
      /* only nag about a field the user actually typed into */
      if (i.type !== "checkbox" && !i.classList.contains("sa-otp__box") && (i.value || "").trim()) validate(i);
    });
  });

  /* ==========================================================================
     PAGE 1 — login.html : sign in / create account
     ========================================================================== */
  var tabsWrap = $("#saTabs");
  var forms = { login: $("#saLoginForm"), register: $("#saRegisterForm") };

  if (tabsWrap && forms.login && forms.register) {
    var tabs = $$(".sa-tab");
    var current = "login";

    var COPY = {
      login: {
        t: "Student sign in",
        s: "Pick up exactly where you left off.",
        done: "Everything checks out",
        doneMsg: "Your details passed every check on this page. Nothing was sent and you have not been signed in — this demo has no server to talk to."
      },
      register: {
        t: "Create your account",
        s: "Two minutes now, and every update lands in one place.",
        done: "Everything checks out",
        doneMsg: "Your details passed every check on this page. No account was created and nothing was stored — this demo has no server to talk to. The next step would be confirming your email address."
      }
    };

    /* ------------------------------------------------------------- tabs */
    var show = function (which) {
      if (!forms[which]) return;
      current = which;

      tabs.forEach(function (b) {
        var on = b.getAttribute("data-auth") === which;
        b.classList.toggle("sa-on", on);
        b.setAttribute("aria-selected", on ? "true" : "false");
        /* roving tabindex — only the selected tab is in the tab order */
        b.setAttribute("tabindex", on ? "0" : "-1");
      });

      Object.keys(forms).forEach(function (k) {
        if (forms[k]) forms[k].classList.toggle("sa-on", k === which);
      });

      tabsWrap.classList.toggle("sa-reg", which === "register");
      if (title) title.textContent = COPY[which].t;
      if (sub) sub.textContent = COPY[which].s;

      hideNote();
      clearErrors(forms[which]);
      hideDone(false);

      try {
        history.replaceState(null, "", which === "register" ? "#register" : "#login");
      } catch (e) { /* file:// or blocked history — harmless */ }
    };

    tabs.forEach(function (b) {
      b.addEventListener("click", function () { show(b.getAttribute("data-auth")); });
    });

    /* arrow / Home / End navigation, as a role="tablist" is expected to have */
    tabsWrap.addEventListener("keydown", function (e) {
      var i = tabs.indexOf(document.activeElement);
      if (i < 0) return;
      var next = null;
      if (e.key === "ArrowRight" || e.key === "ArrowDown") next = tabs[(i + 1) % tabs.length];
      else if (e.key === "ArrowLeft" || e.key === "ArrowUp") next = tabs[(i + tabs.length - 1) % tabs.length];
      else if (e.key === "Home") next = tabs[0];
      else if (e.key === "End") next = tabs[tabs.length - 1];
      if (!next) return;
      e.preventDefault();
      show(next.getAttribute("data-auth"));
      next.focus();
    });

    if (location.hash === "#register") show("register");

    /* ------------------------------------------------- strength meter */
    var LEVELS = ["—", "Weak", "Fair", "Strong", "Excellent"];
    var newPw = $('#saRegisterForm input[name="password"]');
    var meter = $("#saPwMeter");
    var meterVal = $("#saPwVal");

    var scorePassword = function (v) {
      var score = 0;
      if (v.length >= 8) score++;
      if (v.length >= 12) score++;
      if (/[A-Z]/.test(v) && /[a-z]/.test(v)) score++;
      if (/\d/.test(v) && /[^\w\s]/.test(v)) score++;
      if (!v) return 0;
      return Math.min(score, 4) || 1;
    };

    var paintMeter = function (v) {
      if (!meter) return;
      var lvl = scorePassword(v);
      if (meter.getAttribute("data-lvl") === String(lvl)) return;
      meter.setAttribute("data-lvl", String(lvl));
      if (meterVal) {
        meterVal.textContent = LEVELS[lvl];
        /* restart the little pop on the level word */
        meterVal.style.animation = "none";
        void meterVal.offsetWidth;
        meterVal.style.animation = "";
      }
    };

    if (newPw && meter) {
      newPw.addEventListener("input", function () { paintMeter(newPw.value); });
    }

    /* --------------------------------------------- country code + phone
       The dialling code is not decoration: it picks the example the phone
       field shows and the length it is checked against, and it is what gets
       glued to the front of the number on submit. */
    var ccSel = $("#rg-cc");
    var phone = $("#rg-phone");
    var phoneHint = $("#rg-phone-hint");

    /* national-format example, and the shortest plausible national number */
    var CC = {
      "+880": ["1712345678", 10], "+91": ["9876543210", 10], "+977": ["9801234567", 10],
      "+94": ["712345678", 9],    "+92": ["3001234567", 10], "+44": ["7700900123", 10],
      "+1": ["2015550123", 10],   "+61": ["412345678", 9],   "+64": ["211234567", 8],
      "+353": ["851234567", 9],   "+49": ["15112345678", 10], "+971": ["501234567", 9]
    };

    var ccInfo = function () { return CC[ccSel && ccSel.value] || ["1712345678", 8]; };

    var paintCountry = function () {
      if (!phone) return;
      var info = ccInfo();
      phone.placeholder = info[0];
      if (phoneHint) {
        phoneHint.textContent = "Digits only, without the dialling code — for example " +
          ccSel.value + " " + info[0] + ".";
      }
    };

    /* the country-aware half of the phone rule, layered on top of the
       generic length check in validate() */
    var checkPhone = function () {
      if (!phone || !ccSel) return true;
      if (!validate(phone)) return false;
      var digits = (phone.value || "").replace(/[^0-9]/g, "");
      var want = ccInfo()[1];
      if (digits.length && digits.length < want) {
        setError(phone, "A " + ccSel.value + " number is usually " + want + " digits long.");
        return false;
      }
      return true;
    };

    if (ccSel && phone) {
      ccSel.addEventListener("change", function () {
        paintCountry();
        /* re-check a number the user had already typed against the new country */
        if ((phone.value || "").length) checkPhone();
      });
      paintCountry();
      phone.addEventListener("blur", function () {
        if ((phone.value || "").length) checkPhone();
      });
    }

    /* the success overlay's onward link is only meaningful after a signup */
    onHide.push(function (refocus) {
      if (doneNext) doneNext.hidden = true;
      if (refocus) {
        var form = forms[current];
        var firstInput = form ? $("input", form) : null;
        if (firstInput) firstInput.focus();
      }
    });

    /* ----------------------------------------------------------- submit */
    var submit = function (form, kind) {
      hideNote();

      var formOk = validateForm(form);
      if (kind === "register" && !checkPhone()) formOk = false;
      if (!formOk) {
        showNote("A couple of fields need another look — they're marked below.", "err");
        return;
      }

      var btn = $(".sa-submit", form);
      setBusy(btn);
      announce("Checking your details.");

      var emailEl = form.elements.email;
      var email = emailEl ? (emailEl.value || "").trim() : "";

      /* wipe the typed password out of the DOM once we've captured it */
      var clearPw = function () {
        var pw = $('input[name="password"]', form);
        if (pw) { pw.value = ""; if (pw === newPw) paintMeter(""); }
      };

      var body = { email: email, password: form.elements.password.value };
      if (kind === "register") {
        body.name = (form.elements.name.value || "").trim();
        body.cc = form.elements.cc.value;
        body.phone = (form.elements.phone.value || "").trim();
        body.agree = form.elements.agree ? !!form.elements.agree.checked : false;
      } else if (form.elements.remember) {
        body.remember = !!form.elements.remember.checked;
      }

      /* Real request. Success paths navigate away; every failure resurfaces
         the busy button and shows the server's message (generic by design). */
      window.VFIApi.post("/api/" + (kind === "register" ? "register" : "login"), body, { noRedirect: true })
        .then(function (data) {
          resetFill(btn);
          clearPw();
          if (kind === "register") {
            /* stash the masked address so the verify page can show it without
               ever putting the real email in the URL */
            try {
              if (data && data.email_masked && window.sessionStorage) {
                sessionStorage.setItem("vfi_flow_" + data.flow_id, data.email_masked);
              }
            } catch (e) { /* storage blocked — verify page falls back to the API */ }
            location.href = "student-verify.html?flow=" + encodeURIComponent(data.flow_id);
            return;
          }
          location.href = "student-profile.html";   /* signed in → the portal */
        })
        .catch(function (err) {
          resetFill(btn);
          clearPw();
          var msg;
          if (err && err.status === 422 && err.body && err.body.errors) {
            msg = "A couple of fields need another look — please check them and try again.";
          } else {
            msg = (err && err.body && err.body.message) ||
                  (err && err.status ? "We couldn't complete that. Please check your details and try again."
                                     : "We couldn't reach the server. Please try again in a moment.");
          }
          showNote(msg, "err");
        });
    };

    Object.keys(forms).forEach(function (k) {
      if (!forms[k]) return;
      forms[k].addEventListener("submit", function (e) {
        e.preventDefault();
        submit(forms[k], k);
      });
    });
  }

  /* ==========================================================================
     PAGE 2 — student-forgot.html : ask for a reset link

     Two steps in one card. Step 1 takes the address; step 2 confirms it and
     owns the resend cooldown. The card heading is the page's only <h1>, so
     the step change rewrites it rather than introducing a second one.
     ========================================================================== */
  var fpForm = $("#saForgotForm");
  var fpSent = $("#saSentStep");

  if (fpForm && fpSent) {
    var fpEmail = $("#fp-email");
    var fpAddr = $("#saSentAddr");
    var fpResend = $("#saResend");
    var fpResendTxt = $("#saResendTxt");
    var fpResendHint = $("#saResendHint");
    var fpWrong = $("#saWrongAddr");
    var fpSubmit = $(".sa-submit", fpForm);

    var FP_COPY = {
      ask: {
        t: "Reset your password",
        s: "Tell us the email address on your account and we'll send you a link to set a new password."
      },
      sent: {
        t: "Check your inbox",
        s: "If that address belongs to an account, the reset link is on its way."
      }
    };

    var fpCool = Cooldown(fpResend, fpResendTxt, fpResendHint, {
      idle: "Send the email again",
      wait: "Resend in {s}s",
      idleHint: "Nothing arrived? Send it once more.",
      waitHint: "You can ask for another email in {s} second{p}.",
      onReady: function () { announce("You can send the reset email again now."); }
    });

    var fpStep = function (which) {
      var sent = which === "sent";
      fpForm.classList.toggle("sa-on", !sent);
      fpSent.classList.toggle("sa-on", sent);
      if (title) title.textContent = FP_COPY[sent ? "sent" : "ask"].t;
      if (sub) sub.textContent = FP_COPY[sent ? "sent" : "ask"].s;
    };

    /* one place that "sends" the link, so step 1 and the resend button agree */
    var fpSend = function (addr, isResend) {
      if (fpAddr) fpAddr.textContent = addr;
      /* Deliberately fire-and-forget: the server responds identically whether
         or not the address is on file (enumeration safety), so we ignore the
         outcome entirely and always show the same confirmation. */
      window.VFIApi.post("/api/password/reset", { email: addr }, { noRedirect: true })
        .catch(function () { /* never surface success/failure */ });
      fpCool.start(COOLDOWN_SECONDS);
      if (isResend) {
        showNote("We've sent it again — give it a minute to arrive.", "ok");
      } else {
        announce("Reset instructions sent to " + addr + ". Check your inbox.");
      }
    };

    fpForm.addEventListener("submit", function (e) {
      e.preventDefault();
      hideNote();
      if (!validateForm(fpForm)) {
        showNote("We need a valid email address before we can send anything.", "err");
        return;
      }
      var addr = (fpEmail.value || "").trim();
      setBusy(fpSubmit);
      announce("Sending your reset link.");

      setTimeout(function () {
        resetFill(fpSubmit);
        fpStep("sent");
        fpSend(addr, false);
        if (fpResend) fpResend.focus();
      }, 950);
    });

    if (fpResend) {
      fpResend.addEventListener("click", function () {
        if (fpResend.getAttribute("aria-disabled") === "true") return;
        fpSend((fpEmail.value || "").trim(), true);
      });
    }

    if (fpWrong) {
      fpWrong.addEventListener("click", function () {
        hideNote();
        fpStep("ask");
        if (fpEmail) {
          fpEmail.focus();
          try { fpEmail.select(); } catch (e) { /* not selectable — fine */ }
        }
      });
    }
  }

  /* ==========================================================================
     PAGE 3 — student-verify.html : six-box one-time code
     ========================================================================== */
  var otp = $(".sa-otp");
  var otpBoxes = $$(".sa-otp__box");
  var vfForm = $("#saVerifyForm");

  if (otp && vfForm && otpBoxes.length) {
    var vfResend = $("#saResend");
    var vfResendTxt = $("#saResendTxt");
    var vfResendHint = $("#saResendHint");
    var vfSubmit = $(".sa-submit", vfForm);
    var vfTo = $("#saVerifyTo");
    var vfWasFull = false;
    var vfSending = false;

    /* ------------------------------------------- whose address is this? */
    /* State travels as an opaque flow id — never the email — so nothing
       sensitive lands in the URL, history or referrers. */
    var vfFlow = query("flow");

    var paintTo = function (masked) {
      if (!vfTo) return;
      if (masked) {
        vfTo.textContent = "";
        vfTo.appendChild(document.createTextNode("We sent a six-digit code to "));
        var b = document.createElement("b");
        b.textContent = masked;            /* textContent, never innerHTML */
        vfTo.appendChild(b);
        vfTo.appendChild(document.createTextNode("."));
      } else {
        vfTo.textContent = "Enter the six-digit code from the confirmation email we sent you.";
      }
    };

    /* prefer the address stashed at registration; otherwise ask the server by
       flow id (still no raw email anywhere in the URL) */
    var vfStashed = "";
    try { if (window.sessionStorage) vfStashed = sessionStorage.getItem("vfi_flow_" + vfFlow) || ""; } catch (e) { /* blocked */ }
    if (vfStashed) {
      paintTo(vfStashed);
    } else if (vfFlow && window.VFIApi) {
      window.VFIApi.get("/api/verify/context?flow_id=" + encodeURIComponent(vfFlow), { noRedirect: true })
        .then(function (data) { paintTo(data && data.email_masked); })
        .catch(function () { paintTo(""); });
    } else {
      paintTo("");
    }

    /* ------------------------------------------------------- cooldown */
    var vfCool = Cooldown(vfResend, vfResendTxt, vfResendHint, {
      idle: "Send a new code",
      wait: "New code in {s}s",
      idleHint: "Codes expire after 10 minutes.",
      waitHint: "You can ask for a new code in {s} second{p}.",
      onReady: function () { announce("You can request a new code now."); }
    });
    vfCool.start(COOLDOWN_SECONDS);

    if (vfResend) {
      vfResend.addEventListener("click", function () {
        if (vfResend.getAttribute("aria-disabled") === "true") return;
        window.VFIApi.post("/api/verify/resend", { flow_id: vfFlow }, { noRedirect: true })
          .then(function () {
            vfCool.start(COOLDOWN_SECONDS);
            clearBoxes(false);
            showNote("A fresh code is on its way — the last one no longer works.", "ok");
            if (otpBoxes[0]) otpBoxes[0].focus();
          })
          .catch(function (err) {
            var msg = (err && err.body && err.body.message) ||
                      "We couldn't send a new code just now. Please wait a moment and try again.";
            showNote(msg, "err");
          });
      });
    }

    /* --------------------------------------------------------- the boxes */
    function codeValue() {
      var v = "";
      otpBoxes.forEach(function (b) { v += b.value; });
      return v;
    }

    function focusBox(i) {
      var b = otpBoxes[Math.max(0, Math.min(otpBoxes.length - 1, i))];
      if (!b) return;
      b.focus();
      try { b.select(); } catch (e) { /* fine */ }
    }

    function clearBoxes(keepFocus) {
      otpBoxes.forEach(function (b) {
        b.value = "";
        b.classList.remove("sa-has");
      });
      otp.classList.remove("sa-err", "sa-ok");
      vfWasFull = false;
      if (keepFocus) focusBox(0);
    }

    function sync() {
      otpBoxes.forEach(function (b) { b.classList.toggle("sa-has", !!b.value); });
      var full = codeValue().length === otpBoxes.length;
      if (full && !vfWasFull) {
        vfWasFull = true;
        otp.classList.add("sa-ok");
        announce("Code complete. Checking it now.");
        /* let the last digit paint before the card flips */
        setTimeout(function () { checkCode(); }, 280);
      }
      if (!full) {
        vfWasFull = false;
        otp.classList.remove("sa-ok");
      }
    }

    /* fill boxes from `from` with as many digits as there are */
    function spread(from, digits) {
      var i = from;
      var j = 0;
      for (; i < otpBoxes.length && j < digits.length; i++, j++) {
        otpBoxes[i].value = digits.charAt(j);
      }
      otp.classList.remove("sa-err");
      focusBox(i >= otpBoxes.length ? otpBoxes.length - 1 : i);
      sync();
    }

    otpBoxes.forEach(function (box, idx) {
      /* typing a digit advances; a soft keyboard or autofill may deliver
         several at once, which spreads across the remaining boxes */
      box.addEventListener("input", function () {
        var v = (box.value || "").replace(/[^0-9]/g, "");
        if (v.length > 1) {
          box.value = "";
          spread(idx, v);
          return;
        }
        if (v !== box.value) nudge(box, RULES.digits);
        box.value = v;
        otp.classList.remove("sa-err");
        if (v) focusBox(idx + 1);
        sync();
      });

      box.addEventListener("keydown", function (e) {
        if (e.key === "Backspace") {
          if (!box.value) {
            e.preventDefault();
            var prev = otpBoxes[idx - 1];
            if (prev) {
              prev.value = "";
              focusBox(idx - 1);
              sync();
            }
          }
          return;                              /* otherwise let it clear this box */
        }
        if (e.key === "Delete") { box.value = ""; sync(); return; }
        if (e.key === "ArrowLeft") { e.preventDefault(); focusBox(idx - 1); return; }
        if (e.key === "ArrowRight") { e.preventDefault(); focusBox(idx + 1); return; }
        if (e.key === "Home") { e.preventDefault(); focusBox(0); return; }
        if (e.key === "End") { e.preventDefault(); focusBox(otpBoxes.length - 1); return; }
      });

      /* a pasted six-digit code fills the whole row, wherever it was dropped */
      box.addEventListener("paste", function (e) {
        var dt = e.clipboardData || window.clipboardData;
        if (!dt) return;
        e.preventDefault();
        var raw = String(dt.getData("text") || "");
        var digits = raw.replace(/[^0-9]/g, "");
        if (!digits) { nudge(box, RULES.digits); return; }
        if (digits !== raw.replace(/\s/g, "")) nudge(box, null);
        spread(digits.length >= otpBoxes.length ? 0 : idx, digits.slice(0, otpBoxes.length));
      });

      /* Clicking a box that already holds a digit should select it, so the
         next keystroke replaces rather than being refused by maxlength="1".
         The select has to be deferred past mouseup (which would collapse it)
         — and re-checked, because select() also *focuses* in Chrome, so a
         stale timer firing after focus has moved on would drag it back. */
      box.addEventListener("focus", function () {
        setTimeout(function () {
          if (document.activeElement !== box) return;
          try { box.select(); } catch (er) { /* fine */ }
        }, 0);
      });
    });

    /* ------------------------------------------------------------ check */
    function checkCode() {
      if (vfSending) return;
      var v = codeValue();
      if (v.length !== otpBoxes.length) {
        setError(otpBoxes[Math.max(0, v.length)] || otpBoxes[0],
                 "Enter all six digits of the code.");
        showNote("The code isn't complete yet — six digits, please.", "err");
        focusBox(v.length);
        return;
      }

      vfSending = true;
      hideNote();
      setBusy(vfSubmit);
      announce("Checking your code.");

      /* Real check against the server. Wrong codes come back {ok:false} with a
         200, so they resolve (not throw); transport failures land in .catch. */
      window.VFIApi.post("/api/verify", { flow_id: vfFlow, code: v }, { noRedirect: true })
        .then(function (data) {
          vfSending = false;
          resetFill(vfSubmit);
          if (data && data.ok) {
            try { if (vfFlow && window.sessionStorage) sessionStorage.removeItem("vfi_flow_" + vfFlow); } catch (e) { /* fine */ }
            var dn = $("#saDoneNext");
            if (dn) dn.hidden = false;
            showDone("Email confirmed", "Your email address is verified. You can sign in now.");
            return;
          }
          otp.classList.add("sa-err");
          showNote((data && data.message) || "That code didn't match. Check it and try again.", "err");
          clearBoxes(true);
        })
        .catch(function (err) {
          vfSending = false;
          resetFill(vfSubmit);
          otp.classList.add("sa-err");
          showNote((err && err.body && err.body.message) ||
                   "We couldn't check that code. Please try again in a moment.", "err");
          clearBoxes(true);
        });
    }

    vfForm.addEventListener("submit", function (e) {
      e.preventDefault();
      checkCode();
    });

    onHide.push(function (refocus) {
      clearBoxes(false);
      if (refocus) focusBox(0);
    });
  }

  /* ==========================================================================
     PAGE 4 — student-reset.html : choose a new password (from the emailed link)

     Reached from the reset email's link, which carries a single-use ?token=.
     Three states in one card: the form, a success panel, and a dead-end panel
     for a missing / invalid / expired token (with a "request a new link" CTA).
     ========================================================================== */
  var rsForm = $("#saResetForm");

  if (rsForm) {
    var rsPw = $("#rs-pw");
    var rsPw2 = $("#rs-pw2");
    var rsSubmit = $(".sa-submit", rsForm);
    var rsDone = $("#saResetDone");
    var rsBad = $("#saResetBad");
    var rsBadTxt = $("#saResetBadTxt");
    var rsToken = query("token");

    var rsShow = function (which) {
      rsForm.classList.toggle("sa-on", which === "form");
      if (rsDone) rsDone.classList.toggle("sa-on", which === "done");
      if (rsBad) rsBad.classList.toggle("sa-on", which === "bad");
    };
    var rsFail = function (msg) {
      if (rsBadTxt && msg) rsBadTxt.textContent = msg;
      rsShow("bad");
    };

    /* a link with no token can never work — say so before the user types */
    if (!rsToken) {
      rsFail("This reset link is missing or malformed. Request a new one below.");
    }

    /* password reveal is wired globally (see .sa-field__eye handler above) */

    rsForm.addEventListener("submit", function (e) {
      e.preventDefault();
      hideNote();
      var pw = rsPw ? (rsPw.value || "") : "";
      var pw2 = rsPw2 ? (rsPw2.value || "") : "";

      if (pw.length < 8) {
        setError(rsPw, "Use at least 8 characters.");
        showNote("Your new password needs at least 8 characters.", "err");
        return;
      }
      if (pw !== pw2) {
        setError(rsPw2, "This doesn't match the password above.");
        showNote("Those two passwords don't match.", "err");
        return;
      }

      setBusy(rsSubmit);
      announce("Saving your new password.");

      window.VFIApi.post(
        "/api/password/reset/submit",
        { token: rsToken, password: pw, password_confirmation: pw2 },
        { noRedirect: true }
      )
        .then(function () {
          resetFill(rsSubmit);
          if (rsPw) rsPw.value = "";
          if (rsPw2) rsPw2.value = "";
          announce("Your password has been updated.");
          rsShow("done");
        })
        .catch(function (err) {
          resetFill(rsSubmit);
          if (rsPw) rsPw.value = "";
          if (rsPw2) rsPw2.value = "";
          var body = err && err.body;
          if (err && err.status === 422 && body && body.errors && body.errors.password) {
            setError(rsPw, "Choose a different password.");
            showNote(body.errors.password[0], "err");
            return;
          }
          /* invalid or expired token → dead-end panel with a fresh-link CTA */
          rsFail((body && body.message) || "This reset link is invalid or has expired.");
        });
    });
  }

  /* ================================================ brand name from admin */
  try {
    if (window.VFI && VFI.settings) {
      var s = VFI.settings();
      if (s && s.brand) {
        var txt = $(".sa-logo__txt");
        if (txt && txt.childNodes[0]) txt.childNodes[0].nodeValue = s.brand;
        var glyph = $(".sa-logo__glyph");
        if (glyph) glyph.textContent = s.brand.trim().charAt(0).toUpperCase();
      }
      if (s && s.tagline) {
        var tag = $(".sa-logo__txt small");
        if (tag) tag.textContent = s.tagline;
      }
    }
  } catch (e) { /* storage blocked — the markup already has sensible defaults */ }
})();
