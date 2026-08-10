/* ==========================================================================
   portal.js — the shared shell for the VFI Partner console.

   Injects the SVG sprite, the teal top bar and the collapsible sidebar into
   every partner-*.html page, then wires: nav highlight, sidebar collapse
   (remembered), the mobile drawer, the notification + account dropdowns, the
   two global modals (Register New Student, Request Program Options), per-field
   character rules, a small toast helper and on-scroll reveals.

   FRONT-END ONLY. This is a demo console — nothing is transmitted or saved,
   every count is zero and every list is empty, exactly like a brand-new
   partner account. Search this file for "REAL REQUEST" for the wiring points.

   ES5 on purpose (var / function / string concat) to match the rest of js/.
   Each page names its nav highlight on <body data-pp-page="…">.
   ========================================================================== */
(function () {
  "use strict";

  var $ = function (s, c) { return (c || document).querySelector(s); };
  var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };

  var PAGE = (document.body && document.body.getAttribute("data-pp-page")) || "dashboard";

  /* ------------------------------------------------ the SVG icon sprite */
  var SPRITE =
    '<svg xmlns="http://www.w3.org/2000/svg" style="position:absolute;width:0;height:0;overflow:hidden" aria-hidden="true">' +
    '<symbol id="pi-home" viewBox="0 0 24 24"><path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/></symbol>' +
    '<symbol id="pi-cap" viewBox="0 0 24 24"><path d="M22 10L12 5 2 10l10 5 10-5z"/><path d="M6 12v5c0 1 3 3 6 3s6-2 6-3v-5"/></symbol>' +
    '<symbol id="pi-doc" viewBox="0 0 24 24"><path d="M14 3H6v18h12V7z"/><path d="M14 3v4h4"/><path d="M9 13h6M9 17h6"/></symbol>' +
    '<symbol id="pi-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4-4"/></symbol>' +
    '<symbol id="pi-wallet" viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18"/><circle cx="17" cy="14" r="1.4"/></symbol>' +
    '<symbol id="pi-enquiry" viewBox="0 0 24 24"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M9 8h1.5a1.6 1.6 0 1 1 1.2 2.6c-.7.4-.9.8-.9 1.4"/><path d="M11 15.5h.01"/></symbol>' +
    '<symbol id="pi-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/></symbol>' +
    '<symbol id="pi-interview" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.2"/><path d="M3.5 20a5.5 5.5 0 0 1 11 0"/><path d="M17 8l2 2 3-3.5"/></symbol>' +
    '<symbol id="pi-allied" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="3.4"/><path d="M12 3.5v3M12 17.5v3M3.5 12h3M17.5 12h3"/></symbol>' +
    '<symbol id="pi-bell" viewBox="0 0 24 24"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></symbol>' +
    '<symbol id="pi-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20a8 8 0 0 1 16 0"/></symbol>' +
    '<symbol id="pi-chev-down" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></symbol>' +
    '<symbol id="pi-chev-left" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></symbol>' +
    '<symbol id="pi-chev-right" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></symbol>' +
    '<symbol id="pi-logout" viewBox="0 0 24 24"><path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3"/><path d="M10 17l-5-5 5-5"/><path d="M5 12h11"/></symbol>' +
    '<symbol id="pi-copy" viewBox="0 0 24 24"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h8"/></symbol>' +
    '<symbol id="pi-print" viewBox="0 0 24 24"><path d="M6 9V3h12v6"/><rect x="4" y="9" width="16" height="8" rx="2"/><path d="M8 17h8v4H8z"/></symbol>' +
    '<symbol id="pi-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>' +
    '<symbol id="pi-wa" viewBox="0 0 24 24"><path d="M20 12a8 8 0 0 1-11.9 7L4 20l1-4.1A8 8 0 1 1 20 12z"/><path d="M9 9.5c.3 2 2 3.7 4 4.2.6.1 1.2-.2 1.4-.8l.2-.6-1.8-.9-.6.7c-1-.4-1.7-1.1-2-2.1l.7-.6-.9-1.8-.6.2c-.6.2-.9.8-.8 1.4z"/></symbol>' +
    '<symbol id="pi-upload" viewBox="0 0 24 24"><path d="M12 15V4"/><path d="M8 8l4-4 4 4"/><path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/></symbol>' +
    '<symbol id="pi-calendar" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 9h18M8 3v4M16 3v4"/></symbol>' +
    '<symbol id="pi-mail" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></symbol>' +
    '<symbol id="pi-phone" viewBox="0 0 24 24"><rect x="7" y="3" width="10" height="18" rx="2"/><path d="M11 18h2"/></symbol>' +
    '<symbol id="pi-x" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></symbol>' +
    '<symbol id="pi-arrow" viewBox="0 0 24 24"><path d="M5 12h14"/><path d="M13 6l6 6-6 6"/></symbol>' +
    '<symbol id="pi-check" viewBox="0 0 24 24"><polyline points="4 12 10 18 20 6"/></symbol>' +
    '<symbol id="pi-send" viewBox="0 0 24 24"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></symbol>' +
    '<symbol id="pi-medal" viewBox="0 0 24 24"><circle cx="12" cy="9" r="6"/><path d="M9 14l-2 7 5-3 5 3-2-7"/></symbol>' +
    '<symbol id="pi-book" viewBox="0 0 24 24"><path d="M4 5a2 2 0 0 1 2-2h12v18H6a2 2 0 0 0-2 2z"/><path d="M4 19a2 2 0 0 1 2-2h12"/></symbol>' +
    '<symbol id="pi-house" viewBox="0 0 24 24"><path d="M4 11l8-7 8 7"/><path d="M6 10v10h5v-6h2v6h5V10"/></symbol>' +
    '<symbol id="pi-loan" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v10M9.5 9.2A2.2 2.2 0 0 1 12 8c1.2 0 2.2.7 2.2 1.6 0 2-4.4 1-4.4 3 0 .9 1 1.6 2.2 1.6a2.2 2.2 0 0 0 2.5-1.2"/></symbol>' +
    '<symbol id="pi-flask" viewBox="0 0 24 24"><path d="M9 3h6M10 3v6l-5 9a2 2 0 0 0 1.8 3h10.4A2 2 0 0 0 19 18l-5-9V3"/><path d="M7.5 15h9"/></symbol>' +
    '<symbol id="pi-spark" viewBox="0 0 24 24"><path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/></symbol>' +
    '<symbol id="pi-list" viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13"/><path d="M3.5 6h.01M3.5 12h.01M3.5 18h.01"/></symbol>' +
    '<symbol id="pi-users" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9"/></symbol>' +
    '<symbol id="pi-download" viewBox="0 0 24 24"><path d="M12 4v11"/><path d="M8 11l4 4 4-4"/><path d="M4 20h16"/></symbol>' +
    '<symbol id="pi-archive" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8"/><path d="M10 12h4"/></symbol>' +
    '<symbol id="pi-globe" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.5 2.5 15 0 18M12 3c-2.5 2.5-2.5 15 0 18"/></symbol>' +
    '<symbol id="pi-folder" viewBox="0 0 24 24"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></symbol>' +
    '<symbol id="pi-file" viewBox="0 0 24 24"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/></symbol>' +
    '</svg>';

  /* ------------------------------------------------------- navigation data */
  var NAV = [
    { key: "dashboard", label: "Dashboard", icon: "pi-home", href: "partner-dashboard.html" },
    { key: "students", label: "Students", icon: "pi-cap", href: "partner-students.html" },
    { key: "applications", label: "Applications", icon: "pi-doc", href: "partner-applications.html" },
    { key: "search", label: "Search Programs", icon: "pi-search", href: "partner-search.html" },
    { key: "enquiries", label: "Enquiries", icon: "pi-enquiry", href: "partner-enquiries.html" },
    { key: "resources", label: "Learning Resources", icon: "pi-info", href: "partner-resources.html" }
  ];

  function navItem(n) {
    var active = n.key === PAGE ? " is-active" : "";
    if (n.group) {
      var openGroup = PAGE === "allied";
      var sub = n.group.map(function (g) {
        return '<a class="pp-nav__sublink" href="' + g.href + '">' + g.label + "</a>";
      }).join("");
      return '<button type="button" class="pp-nav__item pp-nav__toggle' + active + '" aria-expanded="' + (openGroup ? "true" : "false") + '">' +
        '<svg class="ic"><use href="#' + n.icon + '"/></svg>' +
        '<span class="pp-nav__label">' + n.label + "</span>" +
        '<svg class="ic ic--sm pp-nav__caret"><use href="#pi-chev-down"/></svg>' +
        "</button>" +
        '<div class="pp-nav__sub' + (openGroup ? " is-open" : "") + '"><div>' + sub + "</div></div>";
    }
    return '<a class="pp-nav__item' + active + '" href="' + n.href + '">' +
      '<svg class="ic"><use href="#' + n.icon + '"/></svg>' +
      '<span class="pp-nav__label">' + n.label + "</span></a>";
  }

  var TOPBAR =
    '<header class="pp-top">' +
      '<button type="button" class="pp-iconbtn pp-burger" id="ppBurger" aria-label="Open menu"><svg class="ic"><use href="#pi-list"/></svg></button>' +
      '<a class="pp-brand" href="partner-dashboard.html" aria-label="VFI Partner console">' +
        '<span class="pp-brand__mark"><img src="assets/img/vfi-emblem.png" alt="" /></span>' +
        '<span class="pp-brand__txt"><span class="pp-brand__name">VFI Partner</span><span class="pp-brand__tag">Console</span></span>' +
      "</a>" +
      '<div class="pp-top__spacer"></div>' +
      '<div class="pp-top__actions">' +
        '<button type="button" class="pp-iconbtn" id="ppBell" aria-label="Notifications" aria-expanded="false"><svg class="ic"><use href="#pi-bell"/></svg></button>' +
        '<button type="button" class="pp-user" id="ppUser" aria-expanded="false">' +
          '<span class="pp-user__av">H</span><span class="pp-user__name">Hello, Hakim!</span>' +
          '<svg class="ic ic--sm"><use href="#pi-chev-down"/></svg>' +
        "</button>" +
      "</div>" +
    "</header>";

  var SIDEBAR =
    '<aside class="pp-side" id="ppSide"><nav class="pp-nav" aria-label="Console">' +
      NAV.map(navItem).join("") +
    "</nav>" +
    '<div class="pp-side__foot"><a class="pp-nav__item" href="vfi-partner-login.html" id="ppLogout">' +
      '<svg class="ic"><use href="#pi-logout"/></svg><span class="pp-nav__label">Logout</span></a></div>' +
    "</aside>" +
    '<button type="button" class="pp-side__toggle" id="ppToggle" aria-label="Collapse sidebar"><svg class="ic ic--sm"><use href="#pi-chev-left"/></svg></button>';

  var NOTIF_POP =
    '<div class="pp-pop" id="ppNotif" role="dialog" aria-label="Notifications">' +
      '<div class="pp-pop__head">Notifications</div>' +
      '<div class="pp-pop__body"><div class="pp-pop__empty">No notifications found</div></div>' +
      '<div class="pp-pop__foot"><a class="pp-pop__more" href="partner-notifications.html">Show all notifications</a></div>' +
    "</div>";

  var USER_POP =
    '<div class="pp-pop" id="ppMenu" role="menu" style="width:240px">' +
      '<div class="pp-pop__head">Hakim &middot; VFI Partner</div>' +
      '<div class="pp-pop__body" style="max-height:none">' +
        '<a class="pp-menu__item" href="partner-dashboard.html" role="menuitem"><svg class="ic ic--sm"><use href="#pi-home"/></svg>Dashboard</a>' +
        '<a class="pp-menu__item" href="partner-students.html" role="menuitem"><svg class="ic ic--sm"><use href="#pi-cap"/></svg>My Students</a>' +
        '<a class="pp-menu__item" href="partner-wallet.html" role="menuitem"><svg class="ic ic--sm"><use href="#pi-wallet"/></svg>My Wallet</a>' +
        '<button class="pp-menu__item pp-menu__item--danger" id="ppMenuLogout" role="menuitem"><svg class="ic ic--sm"><use href="#pi-logout"/></svg>Logout</button>' +
      "</div>" +
    "</div>";

  /* --------------------------------------------------- country dial codes */
  var CODES = [
    ["BD", "+880", "🇧🇩"], ["IN", "+91", "🇮🇳"],
    ["PK", "+92", "🇵🇰"], ["NP", "+977", "🇳🇵"],
    ["LK", "+94", "🇱🇰"], ["US", "+1", "🇺🇸"],
    ["GB", "+44", "🇬🇧"], ["CA", "+1", "🇨🇦"],
    ["AU", "+61", "🇦🇺"], ["AE", "+971", "🇦🇪"],
    ["DE", "+49", "🇩🇪"], ["IE", "+353", "🇮🇪"],
    ["NZ", "+64", "🇳🇿"], ["SG", "+65", "🇸🇬"],
    ["MY", "+60", "🇲🇾"]
  ];
  function codeOptions() {
    return CODES.map(function (c) {
      var sel = c[0] === "BD" ? " selected" : "";
      return '<option value="' + c[1] + '"' + sel + ">" + c[2] + " " + c[1] + "</option>";
    }).join("");
  }

  /* country lists reused in the modals */
  var COUNTRIES = ["Bangladesh", "India", "Nepal", "Sri Lanka", "Pakistan"];
  var DESTS = ["USA", "Canada", "UK", "Australia", "Ireland", "New Zealand", "Germany", "Europe"];
  function opts(list, ph) {
    return '<option value="" disabled selected>' + (ph || "Select") + "</option>" +
      list.map(function (x) { return "<option>" + x + "</option>"; }).join("");
  }

  /* ------------------------------------------------------------- MODALS */
  var MODAL_REGISTER =
    '<div class="pp-modal" id="ppModalRegister" role="dialog" aria-modal="true" aria-labelledby="ppRegTitle">' +
      '<div class="pp-modal__backdrop" data-pp-close></div>' +
      '<div class="pp-modal__card">' +
        '<div class="pp-modal__head"><h2 class="pp-modal__title" id="ppRegTitle">Register New Student</h2>' +
          '<button class="pp-modal__close" data-pp-close aria-label="Close"><svg class="ic"><use href="#pi-x"/></svg></button></div>' +
        '<form class="pp-modal__body" id="ppRegForm" novalidate>' +
          '<div class="pp-form-msg pp-form-msg--ok" id="ppRegMsg" hidden></div>' +
          '<p class="pp-modal__group">Personal Details</p>' +
          '<div class="pp-form-grid">' +
            field("First Name", true, '<input class="pp-input" name="first" data-pp-only="name" placeholder="Enter First Name" autocomplete="off">') +
            field("Middle Name", false, '<input class="pp-input" name="middle" data-pp-only="name" placeholder="Enter Middle Name" autocomplete="off">') +
            field("Last Name", true, '<input class="pp-input" name="last" data-pp-only="name" placeholder="Enter Last Name" autocomplete="off">') +
          "</div>" +
          '<p class="pp-modal__group">Contact Details</p>' +
          '<div class="pp-form-grid--2" style="display:grid;grid-template-columns:1fr 1fr;gap:16px">' +
            '<div class="pp-field"><label class="pp-field__label">Mobile Number <span class="req">*</span></label>' +
              '<div class="pp-phone"><select class="pp-select pp-phone__code" name="code" aria-label="Country code">' + codeOptions() + "</select>" +
              '<input class="pp-input pp-phone__num" name="mobile" data-pp-only="digits" inputmode="numeric" maxlength="15" placeholder="Mobile Number"></div>' +
              '<span class="pp-field__err" data-err="mobile"></span></div>' +
            field("Email Address", true, '<div class="pp-input-wrap"><input class="pp-input" type="email" name="email" placeholder="Enter Email Address" autocomplete="off"><span class="pp-input-wrap__ic"><svg class="ic ic--sm"><use href="#pi-mail"/></svg></span></div>', "email") +
          "</div>" +
          '<div class="pp-modal__foot" style="border:0;padding:20px 0 0"><button type="submit" class="pp-btn pp-btn--primary pp-btn--lg">Register new student</button></div>' +
        "</form>" +
      "</div>" +
    "</div>";

  var MODAL_PROGRAM =
    '<div class="pp-modal" id="ppModalProgram" role="dialog" aria-modal="true" aria-labelledby="ppProgTitle">' +
      '<div class="pp-modal__backdrop" data-pp-close></div>' +
      '<div class="pp-modal__card pp-modal__card--lg">' +
        '<div class="pp-modal__head"><h2 class="pp-modal__title" id="ppProgTitle">Request For Program Options</h2>' +
          '<button class="pp-modal__close" data-pp-close aria-label="Close"><svg class="ic"><use href="#pi-x"/></svg></button></div>' +
        '<form class="pp-modal__body" id="ppProgForm" novalidate>' +
          '<div class="pp-form-msg pp-form-msg--ok" id="ppProgMsg" hidden></div>' +
          '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:6px">' +
            '<div><p class="pp-modal__group" style="margin:0 0 10px">Select Enquiry Type <span class="req" style="color:var(--pp-danger)">*</span></p>' +
              '<div style="display:flex;gap:22px;flex-wrap:wrap"><label class="pp-radio"><input type="radio" name="etype" value="new" checked> Student not in VFI</label>' +
              '<label class="pp-radio"><input type="radio" name="etype" value="existing"> Student in VFI</label></div></div>' +
            '<button type="button" class="pp-btn pp-btn--wa"><svg class="ic ic--sm"><use href="#pi-wa"/></svg> Request Programs via WhatsApp</button>' +
          "</div>" +
          '<div class="pp-form-grid" style="margin-top:18px">' +
            field("First Name", true, '<input class="pp-input" name="pfirst" data-pp-only="name" placeholder="Enter First Name">') +
            field("Middle Name", false, '<input class="pp-input" name="pmiddle" data-pp-only="name" placeholder="Enter Middle Name">') +
            field("Last Name", true, '<input class="pp-input" name="plast" data-pp-only="name" placeholder="Enter Last Name">') +
            field("Student's Country of Education", true, '<select class="pp-select">' + opts(COUNTRIES, "Select Country") + "</select>") +
            field("Highest Education Level", true, '<select class="pp-select">' + opts(["High School", "Bachelor's", "Master's", "Diploma"], "Select level") + "</select>") +
            field("Study Abroad Destination", true, '<select class="pp-select">' + opts(DESTS, "Select Destination") + "</select>") +
            field("Preferred Study Area", true, '<select class="pp-select">' + opts(["Business", "Engineering", "IT & Computing", "Health Sciences", "Arts & Design"], "Select Study Area") + "</select>") +
            field("Preferred Study Level", true, '<select class="pp-select">' + opts(["UG", "PG", "PhD", "Diploma", "Foundation"], "Select Study Level") + "</select>") +
            field("Program Labels (Optional)", false, '<select class="pp-select">' + opts(["Scholarship Available", "Co-op Programs", "Low Tuition", "STEM"], "Select Program Labels") + "</select>") +
          "</div>" +
          '<p class="pp-modal__group">Academic Documents <span class="req" style="color:var(--pp-danger)">*</span></p>' +
          '<div class="pp-upload"><label class="pp-upload__btn"><svg class="ic ic--sm"><use href="#pi-upload"/></svg> Upload<input type="file" multiple accept=".pdf,.jpg,.png" hidden id="ppProgFiles"></label>' +
            '<span class="pp-upload__hint">Upload academic documents.<br>(Supported: .pdf, .jpg, .png)</span></div>' +
          '<div class="pp-upload__files" id="ppProgFileList"></div>' +
          '<p class="pp-modal__group">Additional Information</p>' +
          '<textarea class="pp-input" rows="3" placeholder="Anything else we should know? (optional)"></textarea>' +
          '<div class="pp-modal__foot" style="border:0;padding:22px 0 0"><button type="submit" class="pp-btn pp-btn--primary pp-btn--lg">Request Program Options</button></div>' +
        "</form>" +
      "</div>" +
    "</div>";

  function field(label, req, control, errKey) {
    return '<div class="pp-field"><label class="pp-field__label">' + label +
      (req ? ' <span class="req">*</span>' : "") + "</label>" + control +
      '<span class="pp-field__err" data-err="' + (errKey || "") + '"></span></div>';
  }

  /* ------------------------------------------------------- build the shell */
  var chrome = document.getElementById("pp-chrome");
  if (chrome) {
    chrome.insertAdjacentHTML("beforebegin", SPRITE + TOPBAR + SIDEBAR);
    chrome.parentNode.removeChild(chrome);
  } else {
    document.body.insertAdjacentHTML("afterbegin", SPRITE + TOPBAR + SIDEBAR);
  }
  document.body.insertAdjacentHTML("beforeend", NOTIF_POP + USER_POP + MODAL_REGISTER + MODAL_PROGRAM +
    '<div class="pp-scrim" id="ppScrim"></div><div class="pp-toasts" id="ppToasts"></div>');

  /* --------------------------------------------------------- sidebar state */
  var body = document.body;
  var TOGGLE_KEY = "pp_collapsed";
  try { if (localStorage.getItem(TOGGLE_KEY) === "1") body.classList.add("pp-collapsed"); } catch (e) {}

  var toggle = $("#ppToggle");
  if (toggle) toggle.addEventListener("click", function () {
    body.classList.toggle("pp-collapsed");
    try { localStorage.setItem(TOGGLE_KEY, body.classList.contains("pp-collapsed") ? "1" : "0"); } catch (e) {}
  });

  /* mobile drawer */
  var scrim = $("#ppScrim");
  function closeDrawer() { body.classList.remove("pp-drawer"); if (scrim) scrim.classList.remove("is-on"); }
  var burger = $("#ppBurger");
  if (burger) burger.addEventListener("click", function () {
    var on = body.classList.toggle("pp-drawer");
    if (scrim) scrim.classList.toggle("is-on", on);
  });
  if (scrim) scrim.addEventListener("click", closeDrawer);

  /* Allied Services expander */
  $$(".pp-nav__toggle").forEach(function (btn) {
    btn.addEventListener("click", function () {
      if (body.classList.contains("pp-collapsed")) { body.classList.remove("pp-collapsed"); }
      var open = btn.getAttribute("aria-expanded") === "true";
      btn.setAttribute("aria-expanded", open ? "false" : "true");
      var sub = btn.nextElementSibling;
      if (sub) sub.classList.toggle("is-open", !open);
    });
  });

  /* --------------------------------------------------------- the dropdowns */
  var pops = { bell: { btn: $("#ppBell"), pop: $("#ppNotif") }, user: { btn: $("#ppUser"), pop: $("#ppMenu") } };
  function closePops(except) {
    Object.keys(pops).forEach(function (k) {
      if (k === except) return;
      if (pops[k].pop) pops[k].pop.classList.remove("is-open");
      if (pops[k].btn) pops[k].btn.setAttribute("aria-expanded", "false");
    });
  }
  Object.keys(pops).forEach(function (k) {
    var o = pops[k];
    if (!o.btn || !o.pop) return;
    o.btn.addEventListener("click", function (e) {
      e.stopPropagation();
      var open = o.pop.classList.contains("is-open");
      closePops();
      o.pop.classList.toggle("is-open", !open);
      o.btn.setAttribute("aria-expanded", open ? "false" : "true");
    });
    o.pop.addEventListener("click", function (e) { e.stopPropagation(); });
  });
  document.addEventListener("click", function () { closePops(); });

  /* ------------------------------------------------------------- logout */
  function logout() {
    try { localStorage.removeItem("pp_collapsed"); } catch (e) {}
    /* Phase 6: revoke the server session, then return to sign-in. */
    var leave = function () { window.location.href = "vfi-partner-login.html"; };
    if (window.VFIApi) {
      window.VFIApi.post("/api/partner/logout", {}, { noRedirect: true }).then(leave)["catch"](leave);
    } else {
      leave();
    }
  }
  var menuLogout = $("#ppMenuLogout");
  if (menuLogout) menuLogout.addEventListener("click", logout);
  /* the sidebar link was a bare <a> that navigated without revoking — wire it */
  var sideLogout = $("#ppLogout");
  if (sideLogout) sideLogout.addEventListener("click", function (e) { e.preventDefault(); logout(); });

  /* =================================================== per-field char rules */
  /* Strip disallowed characters as the user types while keeping the caret in
     place — the alternative (reassigning value) jumps the cursor to the end. */
  var RULES = {
    digits: /[^0-9]/g,
    name: /[^A-Za-z .'’\-]/g,
    alnum: /[^A-Za-z0-9 ]/g
  };
  function applyRule(input) {
    var kind = input.getAttribute("data-pp-only");
    var re = RULES[kind];
    if (!re) return;
    input.addEventListener("input", function () {
      var start = input.selectionStart, before = input.value;
      var after = before.replace(re, "");
      if (after !== before) {
        var removedBefore = before.slice(0, start).length - before.slice(0, start).replace(re, "").length;
        input.value = after;
        var pos = start - removedBefore;
        try { input.setSelectionRange(pos, pos); } catch (e) {}
      }
    });
    input.addEventListener("paste", function (e) {
      e.preventDefault();
      var text = (e.clipboardData || window.clipboardData).getData("text").replace(re, "");
      document.execCommand ? document.execCommand("insertText", false, text) : (input.value += text);
    });
  }
  window.VFIApplyCharRules = function (scope) { $$("[data-pp-only]", scope || document).forEach(applyRule); };
  window.VFIApplyCharRules();

  /* ============================================================= MODALS === */
  var lastFocus = null;
  function openModal(id) {
    var m = document.getElementById(id);
    if (!m) return;
    lastFocus = document.activeElement;
    m.classList.add("is-open");
    body.style.overflow = "hidden";
    closeDrawer();
    var f = m.querySelector("input, select, textarea, button");
    if (f) { try { f.focus(); } catch (e) {} }
  }
  function closeModal(m) {
    if (!m) return;
    m.classList.remove("is-open");
    body.style.overflow = "";
    if (lastFocus) { try { lastFocus.focus(); } catch (e) {} }
  }
  var MODAL_MAP = { "register-student": "ppModalRegister", "request-program": "ppModalProgram" };
  $$("[data-pp-open]").forEach(function (el) {
    el.addEventListener("click", function (e) {
      e.preventDefault();
      openModal(MODAL_MAP[el.getAttribute("data-pp-open")] || el.getAttribute("data-pp-open"));
    });
  });
  document.addEventListener("click", function (e) {
    var t = e.target.closest ? e.target.closest("[data-pp-close]") : null;
    if (t) closeModal(t.closest(".pp-modal"));
  });
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") {
      var open = $(".pp-modal.is-open");
      if (open) { closeModal(open); return; }
      closePops();
      closeDrawer();
    }
  });

  /* upload file list echo */
  var progFiles = $("#ppProgFiles"), progList = $("#ppProgFileList");
  if (progFiles && progList) progFiles.addEventListener("change", function () {
    var names = Array.prototype.map.call(progFiles.files, function (f) { return f.name; });
    progList.textContent = names.length ? names.join(", ") : "";
  });

  /* ---------------------------------------------------------- toast helper */
  window.VFIToast = function (msg, kind) {
    var wrap = $("#ppToasts");
    if (!wrap) return;
    var t = document.createElement("div");
    t.className = "pp-toast";
    t.innerHTML = '<svg class="ic ic--sm"><use href="#pi-' + (kind === "bad" ? "x" : "check") + '"/></svg><span>' + msg + "</span>";
    wrap.appendChild(t);
    requestAnimationFrame(function () { t.classList.add("is-in"); });
    setTimeout(function () { t.classList.remove("is-in"); setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 400); }, 3200);
  };

  /* ------------------------------------------------------- modal submit === */
  function simpleValidate(form) {
    var ok = true;
    $$("input[required], select[required]", form).forEach(function (el) {
      if (!el.value.trim()) { el.classList.add("is-bad"); ok = false; } else el.classList.remove("is-bad");
    });
    /* required-by-* marker fields */
    $$(".pp-field__label .req", form).forEach(function (req) {
      var f = req.closest(".pp-field");
      var ctrl = f ? f.querySelector("input, select") : null;
      if (ctrl && !ctrl.value.trim()) { ctrl.classList.add("is-bad"); ok = false; }
      else if (ctrl) ctrl.classList.remove("is-bad");
    });
    return ok;
  }
  /* The two global modals were driven by ONE shared handler. They are now split
     per form (docs §2 "split first"): a failure in one cannot break the other. */

  /* Register New Student (#ppRegForm) → POST /api/partner/students */
  (function wireStudentForm() {
    var form = document.getElementById("ppRegForm");
    if (!form) return;
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      if (!simpleValidate(form)) { window.VFIToast("Please complete the required fields.", "bad"); return; }
      var btn = form.querySelector('button[type="submit"]');
      if (btn) btn.disabled = true;
      var el = form.elements;
      var body = {
        first_name: el.first.value.trim(),
        middle_name: el.middle ? el.middle.value.trim() : "",
        last_name: el.last.value.trim(),
        dial: el.code.value,
        mobile: el.mobile.value.trim(),
        email: el.email.value.trim()
      };
      window.VFIApi.post("/api/partner/students", body, { noRedirect: true }).then(function () {
        if (btn) btn.disabled = false;
        window.VFIToast("Student registered.", "ok");
        closeModal(form.closest(".pp-modal")); form.reset();
        document.dispatchEvent(new CustomEvent("vfi:student-created"));
      })["catch"](function (err) {
        if (btn) btn.disabled = false;
        var msg = (err && err.body && err.body.message) ||
          (err && err.status === 409 ? "That email is already registered." :
           "We couldn't register that student. Please try again.");
        var box = document.getElementById("ppRegMsg");
        if (box) { box.textContent = msg; box.hidden = false; box.className = "pp-form-msg pp-form-msg--bad"; }
        if (!err || err.status !== 401) window.VFIToast(msg, "bad");
      });
    });
  })();

  /* Request Program Options (#ppProgForm) → POST /api/partner/enquiries (multipart) */
  (function wireEnquiryForm() {
    var form = document.getElementById("ppProgForm");
    if (!form) return;
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      if (!simpleValidate(form)) { window.VFIToast("Please complete the required fields.", "bad"); return; }
      var btn = form.querySelector('button[type="submit"]');
      if (btn) btn.disabled = true;
      var sels = Array.prototype.slice.call(form.querySelectorAll("select"));
      var fd = new FormData();
      /* the modal collects a NEW lead's details; the existing-student picker is
         later product surface, so this always submits a `new` enquiry */
      fd.append("enquiry_type", "new");
      fd.append("first_name", form.elements.pfirst ? form.elements.pfirst.value.trim() : "");
      fd.append("last_name", form.elements.plast ? form.elements.plast.value.trim() : "");
      var keys = ["country_of_education", "highest_education_level", "destination", "preferred_study_area", "preferred_study_level", "program_label"];
      keys.forEach(function (k, i) { if (sels[i] && sels[i].value) fd.append(k, sels[i].value); });
      var ta = form.querySelector("textarea");
      if (ta && ta.value.trim()) fd.append("additional_info", ta.value.trim());
      var files = document.getElementById("ppProgFiles");
      if (files && files.files) { for (var i = 0; i < files.files.length; i++) fd.append("files[]", files.files[i]); }

      window.VFIApi.post("/api/partner/enquiries", fd, { noRedirect: true }).then(function (data) {
        if (btn) btn.disabled = false;
        var extra = data && data.files_rejected ? " (" + data.files_rejected + " file(s) failed the security scan)" : "";
        window.VFIToast("Program request submitted." + extra, "ok");
        closeModal(form.closest(".pp-modal")); form.reset();
        var fl = $("#ppProgFileList"); if (fl) fl.textContent = "";
        document.dispatchEvent(new CustomEvent("vfi:enquiry-created"));
      })["catch"](function (err) {
        if (btn) btn.disabled = false;
        var msg = (err && err.body && err.body.message) || "We couldn't submit that request. Please try again.";
        if (!err || err.status !== 401) window.VFIToast(msg, "bad");
      });
    });
  })();

  /* Console guard: these are static files — the API is the only protection.
     A 401 makes js/api.js redirect to vfi-partner-login.html (docs §1). */
  if (window.VFIApi) {
    window.VFIApi.get("/api/partner/me", {})["catch"](function () { /* 401 → api.js redirected */ });
  }

  /* --------------------------------------------------------- reveal on scroll */
  (function () {
    var els = $$(".pp-anim");
    if (!els.length) return;
    if (!("IntersectionObserver" in window)) { els.forEach(function (el) { el.classList.add("is-in"); }); return; }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add("is-in"); io.unobserve(en.target); } });
    }, { threshold: 0.08, rootMargin: "0px 0px -6% 0px" });
    els.forEach(function (el, i) { el.style.transitionDelay = Math.min(i * 40, 240) + "ms"; io.observe(el); });
  })();

  /* expose a couple of helpers for page scripts */
  window.VFIPortal = { openModal: openModal, toast: window.VFIToast, codeOptions: codeOptions };
})();
