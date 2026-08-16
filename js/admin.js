/* =====================================================================
   VFI Admin Panel — logic (vanilla JS)
   ===================================================================== */
(function () {
  "use strict";

  var $ = function (s, c) { return (c || document).querySelector(s); };
  var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };
  var esc = VFI.esc;

  /* ---------------- schema ---------------- */
  var COLORS = [["a", "Blue → Violet"], ["b", "Coral → Gold"], ["c", "Green → Blue"]];

  var SCHEMA = {
    events: {
      one: "Event", many: "Events",
      fields: [
        { name: "title", label: "Event title", type: "text", required: true, placeholder: "UK University Spot Admissions Day" },
        { name: "date", label: "Date", type: "date", required: true, half: true },
        { name: "time", label: "Time", type: "text", half: true, placeholder: "10:00 am – 5:00 pm" },
        { name: "type", label: "Event type", type: "select", half: true, options: ["Spot Assessment", "Webinar", "Fair", "Coaching", "Admissions Day"] },
        { name: "city", label: "City", type: "text", half: true, placeholder: "Dhaka / Online" },
        { name: "desc", label: "Short description", type: "textarea", placeholder: "What happens at this event?" },
        { name: "color", label: "Card colour (used when there is no image)", type: "select", options: COLORS },
        { name: "imgId", label: "Cover image", type: "image", size: "1200 × 600 px (landscape 2:1)" }
      ]
    },
    blogs: {
      one: "Blog post", many: "Blogs",
      fields: [
        { name: "title", label: "Post title", type: "text", required: true, placeholder: "10 scholarships students often miss" },
        { name: "category", label: "Category", type: "text", half: true, placeholder: "Scholarships" },
        { name: "date", label: "Publish date", type: "date", half: true },
        { name: "excerpt", label: "Excerpt", type: "textarea", placeholder: "One or two lines shown on the card." },
        { name: "body", label: "Body / article content", type: "textarea", rows: 16,
          help: "Plain text — this is what readers see on the article page. Leave a BLANK LINE between paragraphs. Start a line with “## ” for a subheading, with “- ” for a bullet point, or with “> ” for a pull quote. HTML is not allowed and is shown as plain text.",
          placeholder: "Open with the point of the article.\n\n## A subheading\nA paragraph under it.\n\n- A bullet point\n- Another bullet point\n\n> A line worth pulling out." },
        { name: "author", label: "Author (optional)", type: "text", half: true, placeholder: "VFI Editorial Team" },
        { name: "readTime", label: "Read time (optional)", type: "text", half: true, placeholder: "6 min read",
          help: "Leave this blank and the article page counts the words for you." },
        { name: "color", label: "Card colour (used when there is no image)", type: "select", options: COLORS },
        { name: "imgId", label: "Cover image", type: "image", size: "1200 × 600 px (landscape 2:1)" }
      ]
    },
    news: {
      one: "News item", many: "News & Updates",
      fields: [
        { name: "title", label: "Headline", type: "text", required: true },
        { name: "excerpt", label: "Summary", type: "textarea" },
        { name: "color", label: "Card colour (used when there is no image)", type: "select", options: COLORS },
        { name: "imgId", label: "Image", type: "image", size: "1200 × 500 px (wide 12:5)" }
      ]
    },
    photos: {
      one: "Photo", many: "Photo Gallery",
      fields: [
        { name: "title", label: "Title", type: "text" },
        { name: "caption", label: "Caption", type: "text" },
        { name: "imgId", label: "Photo", type: "image", size: "1200 × 900 px (4:3)" }
      ]
    },

    /* ---- VFI Partner console collections (flat = no image thumbnail) ---- */
    ppManagers: {
      one: "Regional Manager", many: "Regional Managers", flat: true,
      titleKey: "name", metaKeys: ["role", "city", "phone"],
      fields: [
        { name: "name", label: "Full name", type: "text", required: true, placeholder: "Tahmeed Rahman" },
        { name: "role", label: "Role / title", type: "text", half: true, placeholder: "Regional Manager" },
        { name: "city", label: "City", type: "text", half: true, placeholder: "Dhaka" },
        { name: "phone", label: "Phone", type: "text", half: true, placeholder: "+880 1700-000000" },
        { name: "email", label: "Email", type: "text", half: true, placeholder: "name@vfi-edu.com" }
      ]
    },
    ppUpdates: {
      one: "Update", many: "Important Updates", flat: true,
      titleKey: "title", metaKeys: ["sub", "date"],
      fields: [
        { name: "title", label: "Update title", type: "text", required: true, placeholder: "Applications now open for May 2027 intake" },
        { name: "flag", label: "Flag (emoji or 2-letter code)", type: "text", half: true, placeholder: "🇨🇦" },
        { name: "date", label: "Update date", type: "date", half: true },
        { name: "sub", label: "Sub-line", type: "text", placeholder: "Partner university · Ontario" }
      ]
    },
    ppQuicklinks: {
      one: "Quick Link", many: "Quick Links", flat: true,
      titleKey: "label", metaKeys: ["url"],
      fields: [
        { name: "label", label: "Link label", type: "text", required: true, placeholder: "VFI Represented Universities" },
        { name: "url", label: "Link URL", type: "text", placeholder: "partner-resources.html" }
      ]
    },
    ppDocs: {
      one: "Document", many: "Learning Documents", flat: true,
      titleKey: "title", metaKeys: ["category", "country", "size", "date"],
      fields: [
        { name: "title", label: "Document title", type: "text", required: true, placeholder: "Student Enquiry Form" },
        { name: "country", label: "Country", type: "text", half: true, placeholder: "Australia" },
        { name: "category", label: "Category", type: "text", half: true, placeholder: "Enquiry Form" },
        { name: "size", label: "File size (shown on card)", type: "text", half: true, placeholder: "0.16 MB" },
        { name: "date", label: "Date", type: "date", half: true },
        { name: "url", label: "Download URL (optional)", type: "text", placeholder: "#" }
      ]
    },
    ppEmails: {
      one: "Email Update", many: "Email Updates", flat: true,
      titleKey: "subject", metaKeys: ["date"],
      fields: [
        { name: "subject", label: "Subject", type: "text", required: true, placeholder: "Upcoming Deadlines in UK Universities for Fall 2026 Intake" },
        { name: "date", label: "Email date", type: "date" }
      ]
    },
    ppNotifs: {
      one: "Notification", many: "Notifications", flat: true,
      titleKey: "title", metaKeys: ["date"],
      fields: [
        { name: "title", label: "Notification title", type: "text", required: true, placeholder: "Your student's application was submitted" },
        { name: "date", label: "Date", type: "date", half: true },
        { name: "text", label: "Details", type: "textarea", placeholder: "A short line with more detail." }
      ]
    }
  };

  /* ---------------- toast ---------------- */
  var toastEl = $("#toast"), toastT;
  function toast(msg, kind) {
    toastEl.textContent = msg;
    toastEl.className = "toast is-on" + (kind ? " toast--" + kind : "");
    clearTimeout(toastT);
    toastT = setTimeout(function () { toastEl.className = "toast"; }, 2600);
  }

  /* ---------------- view switching ---------------- */
  var TITLES = { dashboard: "Dashboard", events: "Events", blogs: "Blogs", news: "News & Updates", photos: "Photo Gallery", media: "Home Page Images", countries: "Country Pages", regions: "Region Pages", pages: "Pages On/Off", svcpage: "Services Page", partner: "VFI Partner Page", settings: "Site Settings", backup: "Backup",
    ppManagers: "Regional Managers", ppUpdates: "Important Updates", ppQuicklinks: "Quick Links", ppDocs: "Learning Documents", ppEmails: "Email Updates", ppNotifs: "Notifications", ppText: "Console Text" };
  var current = "dashboard";

  function show(view) {
    current = view;
    $$(".ad-view").forEach(function (v) { v.hidden = v.id !== "view-" + view; });
    $$(".ad__navbtn").forEach(function (b) { b.classList.toggle("is-on", b.getAttribute("data-view") === view); });
    /* make sure the submenu group holding the active item is expanded */
    var activeBtn = $('.ad__navbtn[data-view="' + view + '"]');
    var grp = activeBtn && activeBtn.closest ? activeBtn.closest(".ad__group") : null;
    if (grp) setGroup(grp, true);
    $("#adTitle").textContent = TITLES[view] || "Admin";
    // top action button
    var act = $("#adTopAct");
    act.innerHTML = "";
    if (SCHEMA[view] && view !== "photos") {
      var b = document.createElement("button");
      b.className = "btn btn--pri";
      b.innerHTML = '<svg class="ai"><use href="#a-plus"/></svg> New ' + SCHEMA[view].one.toLowerCase();
      b.addEventListener("click", function () { openForm(view, null); });
      act.appendChild(b);
    }
    if (view === "settings") fillSettings();
    if (view === "media") renderSlots();
    if (view === "countries") { renderCountryPicker(); renderCountryEditor(); }
    if (view === "regions") { renderRegionPicker(); renderRegionEditor(); }
    if (view === "pages") renderPageList();
    if (view === "svcpage") renderSvcPage();
    if (view === "partner") renderPartnerPage();
    if (view === "ppText") fillPpText();
    if (SCHEMA[view]) renderList(view);
    $("#side").classList.remove("is-open");
    window.scrollTo(0, 0);
  }

  $$(".ad__navbtn").forEach(function (b) {
    // Only view-switchers carry data-view. The Staff-operations entries are real
    // links out to /manage; without this guard they called show(null) and blanked
    // the panel instead of navigating.
    if (!b.getAttribute("data-view")) return;
    b.addEventListener("click", function () { show(b.getAttribute("data-view")); });
  });

  /* collapsible submenu groups */
  function setGroup(grp, open) {
    grp.classList.toggle("is-open", open);
    var hd = $(".ad__grouphd", grp);
    if (hd) hd.setAttribute("aria-expanded", String(open));
  }
  $$(".ad__grouphd").forEach(function (hd) {
    hd.addEventListener("click", function () {
      var grp = hd.closest(".ad__group");
      setGroup(grp, !grp.classList.contains("is-open"));
    });
  });
  $$("[data-quick]").forEach(function (b) {
    b.addEventListener("click", function () {
      var k = b.getAttribute("data-quick");
      show(k);
      if (k !== "photos") openForm(k, null);
    });
  });
  $("#adBurger").addEventListener("click", function () { $("#side").classList.toggle("is-open"); });

  /* ---------------- counts ---------------- */
  function refreshCounts() {
    ["events", "blogs", "news", "photos",
     "ppManagers", "ppUpdates", "ppQuicklinks", "ppDocs", "ppEmails", "ppNotifs"].forEach(function (k) {
      var n = VFI.list(k).length;
      $$('[data-count="' + k + '"]').forEach(function (el) { el.textContent = n; });
    });
  }

  /* ---------------- lists ---------------- */
  function metaFor(kind, it) {
    var schema = SCHEMA[kind];
    if (schema && schema.metaKeys) {
      return schema.metaKeys.map(function (k) {
        return k === "date" ? VFI.fmtDate(it.date) : it[k];
      }).filter(Boolean);
    }
    if (kind === "events") {
      return [it.type, VFI.fmtDate(it.date), it.time, it.city].filter(Boolean);
    }
    if (kind === "blogs") return [it.category, VFI.fmtDate(it.date)].filter(Boolean);
    if (kind === "news") return [];
    return [];
  }

  /* Where each collection is actually EDITED.
     This panel can only read content (it renders the public content bundle and
     has no write endpoints at all). The staff panel has real CRUD for every one
     of these, so rather than show Edit/Delete buttons that silently do nothing,
     each list points at its working editor. */
  var EDITOR = {
    events: "/manage/content/events",
    blogs: "/manage/content/blogs",
    news: "/manage/content/news-items",
    photos: "/manage/content/photos",
    ppManagers: "/manage/content/pp-managers",
    ppUpdates: "/manage/content/pp-updates",
    ppQuicklinks: "/manage/content/pp-quicklinks",
    ppDocs: "/manage/content/pp-docs",
    ppEmails: "/manage/content/pp-emails",
    ppNotifs: "/manage/content/pp-notifs"
  };

  function editorBanner(kind) {
    var url = EDITOR[kind];
    if (!url) return "";
    var what = (SCHEMA[kind] && SCHEMA[kind].many) ? SCHEMA[kind].many.toLowerCase() : "items";
    return '<div class="ad__notice ad__notice--editor">' +
      "<b>This list is read-only here.</b> Add, edit, reorder or delete " + esc(what) +
      ' in the staff panel — changes appear on the website immediately. ' +
      '<a class="ad__noticelink" href="' + url + '">Open the ' + esc(what) + ' editor &rarr;</a>' +
      "</div>";
  }

  function renderList(kind) {
    if (kind === "photos") return renderGallery();
    var host = $("#list-" + kind);
    var items = VFI.list(kind);
    if (!items.length) {
      host.innerHTML = editorBanner(kind) +
        '<div class="empty"><b>No ' + esc(SCHEMA[kind].many.toLowerCase()) + ' yet</b>' +
        "Add the first one in the staff panel.</div>";
      refreshCounts(); return;
    }
    var titleKey = SCHEMA[kind].titleKey || "title";
    var flat = !!SCHEMA[kind].flat;
    var editUrl = EDITOR[kind];
    host.innerHTML = editorBanner(kind) + items.map(function (it) {
      var meta = metaFor(kind, it).map(function (m) { return '<span class="tag">' + esc(m) + "</span>"; }).join("");
      var thumb = flat ? "" : '<span class="row__thumb row__thumb--' + esc(it.color || "a") + '" data-img="' + esc(it.imgId || "") + '"></span>';
      return '<article class="row' + (flat ? " row--flat" : "") + '" data-id="' + esc(it.id) + '">' + thumb +
        '<div class="row__main"><div class="row__title">' + esc(it[titleKey] || "Untitled") + "</div>" +
        '<div class="row__meta">' + meta + "</div></div>" +
        '<div class="row__act">' +
          // a real link to the working editor, not a button wired to nothing
          (editUrl
            ? '<a class="btn btn--sm btn--icon" href="' + editUrl + '" title="Edit in the staff panel"><svg class="ai"><use href="#a-edit"/></svg></a>'
            : "") +
        "</div></article>";
    }).join("");

    $$(".row", host).forEach(function (row) {
      var id = row.getAttribute("data-id");
      $('[data-act="edit"]', row).addEventListener("click", function () { openForm(kind, id); });
      $('[data-act="del"]', row).addEventListener("click", function () {
        var it = VFI.get(kind, id);
        if (!window.confirm('Delete "' + (it && it[titleKey] ? it[titleKey] : "this item") + '"? This cannot be undone.')) return;
        VFI.remove(kind, id);
        renderList(kind); refreshCounts();
        toast("Deleted", "ok");
      });
      hydrateThumb($(".row__thumb", row));
    });
    refreshCounts();
  }

  function hydrateThumb(el) {
    var id = el && el.getAttribute("data-img");
    if (!id) return;
    VFI.getImage(id).then(function (url) {
      if (url) { el.style.backgroundImage = "url(" + url + ")"; el.style.backgroundSize = "cover"; }
    });
  }

  function renderGallery() {
    var host = $("#list-photos");
    var items = VFI.list("photos");
    if (!items.length) {
      host.innerHTML = '<div class="empty"><b>No photos yet</b>Drop images above to build your gallery.</div>';
      refreshCounts(); return;
    }
    host.innerHTML = items.map(function (p) {
      return '<figure class="ph" data-id="' + esc(p.id) + '">' +
        '<div class="ph__img" data-img="' + esc(p.imgId || "") + '"></div>' +
        '<figcaption class="ph__body"><span class="ph__name">' + esc(p.title || p.caption || "Untitled") + "</span>" +
        '<button class="btn btn--sm btn--icon" data-act="edit" title="Edit"><svg class="ai"><use href="#a-edit"/></svg></button>' +
        '<button class="btn btn--sm btn--icon" data-act="del" title="Delete"><svg class="ai"><use href="#a-trash"/></svg></button>' +
        "</figcaption></figure>";
    }).join("");
    $$(".ph", host).forEach(function (fig) {
      var id = fig.getAttribute("data-id");
      var box = $(".ph__img", fig);
      var imgId = box.getAttribute("data-img");
      if (imgId) VFI.getImage(imgId).then(function (u) { if (u) box.style.backgroundImage = "url(" + u + ")"; });
      $('[data-act="edit"]', fig).addEventListener("click", function () { openForm("photos", id); });
      $('[data-act="del"]', fig).addEventListener("click", function () {
        if (!window.confirm("Delete this photo?")) return;
        VFI.remove("photos", id); renderGallery(); refreshCounts(); toast("Photo deleted", "ok");
      });
    });
    refreshCounts();
  }

  /* ---------------- modal form ---------------- */
  var modal = $("#modal"), modalForm = $("#modalForm"), modalTitle = $("#modalTitle");
  var formState = null;

  function closeModal() {
    // discard an image uploaded but never saved
    if (formState && formState.pendingNew && formState.pendingNew !== formState.originalImg) {
      VFI.delImage(formState.pendingNew);
    }
    modal.hidden = true;
    formState = null;
    modalForm.innerHTML = "";
  }
  $$("[data-close]", modal).forEach(function (el) { el.addEventListener("click", closeModal); });
  document.addEventListener("keydown", function (e) { if (e.key === "Escape" && !modal.hidden) closeModal(); });

  function fieldHTML(f, val) {
    var id = "f_" + f.name;
    var inner;
    /* optional one-line explainer shown under the label */
    var help = f.help ? '<small class="imgpick__hint" style="display:block;line-height:1.55;margin:-2px 0 7px">' + esc(f.help) + "</small>" : "";
    if (f.type === "textarea") {
      inner = '<textarea id="' + id + '" name="' + f.name + '" rows="' + (f.rows || 3) + '" placeholder="' + esc(f.placeholder || "") + '">' + esc(val || "") + "</textarea>";
    } else if (f.type === "select") {
      inner = '<select id="' + id + '" name="' + f.name + '">' + (f.options || []).map(function (o) {
        var v = Array.isArray(o) ? o[0] : o, lbl = Array.isArray(o) ? o[1] : o;
        return '<option value="' + esc(v) + '"' + (String(val) === String(v) ? " selected" : "") + ">" + esc(lbl) + "</option>";
      }).join("") + "</select>";
    } else if (f.type === "image") {
      inner = '<div class="imgpick">' +
          '<div class="imgpick__prev" id="prev_' + f.name + '">No image</div>' +
          '<div class="imgpick__act">' +
            '<button type="button" class="btn btn--sm" id="pick_' + f.name + '"><svg class="ai"><use href="#a-up"/></svg> Choose image</button>' +
            '<button type="button" class="btn btn--sm" id="clr_' + f.name + '">Remove</button>' +
            '<span class="imgpick__hint"><b>Recommended: ' + esc(f.size || "1200 × 600 px") + '</b><br/>Bigger photos are resized automatically.</span>' +
          "</div>" +
          '<input type="file" accept="image/*" id="file_' + f.name + '" hidden />' +
        "</div>";
    } else {
      inner = '<input id="' + id + '" name="' + f.name + '" type="' + f.type + '" value="' + esc(val || "") + '" placeholder="' + esc(f.placeholder || "") + '"' + (f.required ? " required" : "") + " />";
    }
    return '<label class="f"' + (f.type === "image" ? ' style="margin-bottom:6px"' : "") + "><span>" + esc(f.label) + "</span>" + help + inner + "</label>";
  }

  function openForm(kind, id) {
    var schema = SCHEMA[kind];
    var item = id ? JSON.parse(JSON.stringify(VFI.get(kind, id) || {})) : {};
    if (!id && !item.color) item.color = "a";
    formState = { kind: kind, id: id, imgId: item.imgId || null, originalImg: item.imgId || null, pendingNew: null };

    modalTitle.textContent = (id ? "Edit " : "New ") + schema.one.toLowerCase();

    // build fields, pairing "half" fields into rows
    var html = "", i = 0;
    while (i < schema.fields.length) {
      var f = schema.fields[i];
      if (f.half && schema.fields[i + 1] && schema.fields[i + 1].half) {
        html += '<div class="frow">' + fieldHTML(f, item[f.name]) + fieldHTML(schema.fields[i + 1], item[schema.fields[i + 1].name]) + "</div>";
        i += 2;
      } else { html += fieldHTML(f, item[f.name]); i++; }
    }
    modalForm.innerHTML = html;

    // wire image pickers
    schema.fields.filter(function (f) { return f.type === "image"; }).forEach(function (f) {
      var prev = $("#prev_" + f.name), input = $("#file_" + f.name);
      function paint(url) {
        if (url) { prev.style.backgroundImage = "url(" + url + ")"; prev.textContent = ""; }
        else { prev.style.backgroundImage = ""; prev.textContent = "No image"; }
      }
      if (formState.imgId) VFI.getImage(formState.imgId).then(paint); else paint(null);
      $("#pick_" + f.name).addEventListener("click", function () { input.click(); });
      $("#clr_" + f.name).addEventListener("click", function () { formState.imgId = null; paint(null); });
      input.addEventListener("change", function () {
        var file = input.files && input.files[0];
        if (!file) return;
        toast("Processing image…");
        VFI.uploadImage(file, 1400, 0.82).then(function (r) {
          if (formState.pendingNew && formState.pendingNew !== formState.originalImg) VFI.delImage(formState.pendingNew);
          formState.pendingNew = r.id;
          formState.imgId = r.id;
          paint(r.dataUrl);
          toast("Image ready", "ok");
        }).catch(function (err) { toast(err.message || "Upload failed", "err"); });
        input.value = "";
      });
    });

    modal.hidden = false;
    var first = modalForm.querySelector("input,textarea,select");
    if (first) first.focus();

    modalForm.onsubmit = function (e) {
      e.preventDefault();
      var out = id ? (VFI.get(kind, id) || {}) : {};
      schema.fields.forEach(function (f) {
        if (f.type === "image") { out[f.name] = formState.imgId; return; }
        var el = modalForm.querySelector('[name="' + f.name + '"]');
        if (el) out[f.name] = el.value.trim();
      });
      var titleKey = schema.titleKey || "title";
      if (!out[titleKey] && kind !== "photos") {
        var tf = schema.fields.filter(function (f) { return f.name === titleKey; })[0];
        toast("Please add " + (tf ? tf.label.toLowerCase() : "a title"), "err"); return;
      }
      if (kind === "photos" && !out.imgId) { toast("Please choose a photo", "err"); return; }
      // replaced image -> remove the old file
      if (formState.originalImg && formState.originalImg !== formState.imgId) VFI.delImage(formState.originalImg);
      formState.pendingNew = null; // keep it, it's saved now
      VFI.put(kind, out);
      closeModal();
      renderList(kind); refreshCounts();
      toast("Saved", "ok");
    };
  }

  /* ---------------- gallery drop zone ---------------- */
  var drop = $("#galleryDrop"), galleryInput = $("#galleryInput");
  drop.addEventListener("click", function () { galleryInput.click(); });
  ["dragenter", "dragover"].forEach(function (t) {
    drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.add("is-over"); });
  });
  ["dragleave", "drop"].forEach(function (t) {
    drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.remove("is-over"); });
  });
  drop.addEventListener("drop", function (e) {
    if (e.dataTransfer && e.dataTransfer.files) addPhotos(e.dataTransfer.files);
  });
  galleryInput.addEventListener("change", function () { addPhotos(galleryInput.files); galleryInput.value = ""; });

  function addPhotos(fileList) {
    var files = Array.prototype.slice.call(fileList).filter(function (f) { return /^image\//.test(f.type); });
    if (!files.length) { toast("No image files found", "err"); return; }
    toast("Uploading " + files.length + " photo" + (files.length > 1 ? "s" : "") + "…");
    files.reduce(function (p, file) {
      return p.then(function () {
        return VFI.uploadImage(file, 1600, 0.82).then(function (r) {
          VFI.put("photos", { title: file.name.replace(/\.[^.]+$/, ""), caption: "", imgId: r.id });
        });
      });
    }, Promise.resolve())
      .then(function () { renderGallery(); refreshCounts(); toast("Uploaded " + files.length + " photo" + (files.length > 1 ? "s" : ""), "ok"); })
      .catch(function (err) { renderGallery(); toast(err.message || "Upload failed", "err"); });
  }

  /* ---------------- home page image slots ---------------- */
  var SLOTS = [
    { key: "hero", label: "Hero visual", hint: "Big round photo on the blue hero", shape: "circle", size: "900 × 900 px", ratio: "square 1:1" },
    { key: "students", label: "For Students", hint: "Circle in the pink band", shape: "circle", size: "800 × 800 px", ratio: "square 1:1" },
    { key: "partners", label: "For Partners", hint: "Circle in the peach band", shape: "circle", size: "800 × 800 px", ratio: "square 1:1" },
    { key: "franchisees", label: "For Franchisees", hint: "Circle in the blue band", shape: "circle", size: "800 × 800 px", ratio: "square 1:1" },
    { key: "universities", label: "For Universities", hint: "Circle in the lavender band", shape: "circle", size: "800 × 800 px", ratio: "square 1:1" },
    { key: "collage1", label: "Multi Country — left card", hint: "Tall card on the left", shape: "rect", size: "600 × 520 px", ratio: "portrait 7:6" },
    { key: "collage2", label: "Multi Country — top card", hint: "Card at the top", shape: "rect", size: "600 × 460 px", ratio: "landscape 4:3" },
    { key: "collage3", label: "Multi Country — bottom card", hint: "Card at the bottom", shape: "rect", size: "600 × 440 px", ratio: "landscape 4:3" }
  ];

  function renderSlots() {
    var host = $("#slotGrid");
    if (!host) return;
    host.innerHTML = SLOTS.map(function (s) {
      return '<div class="slot" data-key="' + esc(s.key) + '">' +
        '<div class="slot__prev slot__prev--' + s.shape + '">Empty</div>' +
        '<div class="slot__label">' + esc(s.label) + "</div>" +
        '<div class="slot__hint">' + esc(s.hint) + "</div>" +
        '<div class="slot__size">' + esc(s.size) + '<span>' + esc(s.ratio) + "</span></div>" +
        '<div class="slot__act">' +
          '<button type="button" class="btn btn--sm btn--pri" data-act="up"><svg class="ai"><use href="#a-up"/></svg> Upload</button>' +
          '<button type="button" class="btn btn--sm" data-act="rm">Remove</button>' +
        "</div>" +
        '<input type="file" accept="image/*" hidden />' +
      "</div>";
    }).join("");

    $$(".slot", host).forEach(function (el) {
      var key = el.getAttribute("data-key");
      var prev = $(".slot__prev", el), input = $("input[type=file]", el);
      function paint(url) {
        if (url) { prev.style.backgroundImage = "url(" + url + ")"; prev.textContent = ""; prev.classList.add("is-set"); }
        else { prev.style.backgroundImage = ""; prev.textContent = "Empty"; prev.classList.remove("is-set"); }
      }
      var cur = VFI.media(key);
      if (cur) VFI.getImage(cur).then(paint); else paint(null);

      $('[data-act="up"]', el).addEventListener("click", function () { input.click(); });
      $('[data-act="rm"]', el).addEventListener("click", function () {
        if (!VFI.media(key)) return;
        if (!window.confirm("Remove this image? The illustrated placeholder comes back.")) return;
        VFI.setMedia(key, null); paint(null); toast("Image removed", "ok");
      });
      input.addEventListener("change", function () {
        var file = input.files && input.files[0];
        input.value = "";
        if (!file) return;
        toast("Processing image…");
        VFI.uploadImage(file, 1200, 0.84).then(function (r) {
          VFI.setMedia(key, r.id);
          paint(r.dataUrl);
          toast("Saved — refresh the home page to see it", "ok");
        }).catch(function (err) { toast(err.message || "Upload failed", "err"); });
      });
    });
  }

  /* ---------------- country pages ---------------- */
  var COUNTRIES = [
    { slug: "usa", name: "USA", file: "study-in-usa.html" },
    { slug: "canada", name: "Canada", file: "study-in-canada.html" },
    { slug: "ireland", name: "Ireland", file: "study-in-ireland.html" },
    { slug: "australia", name: "Australia", file: "study-in-australia.html" },
    { slug: "uk", name: "United Kingdom", file: "study-in-uk.html" },
    { slug: "newzealand", name: "New Zealand", file: "study-in-new-zealand.html" }
  ];

  var C_TEXT = [
    { name: "heroTitle", label: "Hero heading", type: "text" },
    { name: "heroSub", label: "Hero sub-heading", type: "textarea" },
    { name: "overviewLead", label: "Overview intro paragraph", type: "textarea" }
  ];

  var C_SLOTS = [
    { key: "hero", label: "Hero background", shape: "rect", size: "1920 × 700 px" },
    { key: "uni1", label: "University 1 logo", shape: "circle", size: "400 × 400 px" },
    { key: "uni2", label: "University 2 logo", shape: "circle", size: "400 × 400 px" },
    { key: "uni3", label: "University 3 logo", shape: "circle", size: "400 × 400 px" },
    { key: "uni4", label: "University 4 logo", shape: "circle", size: "400 × 400 px" },
    { key: "city1", label: "City photo 1", shape: "rect", size: "800 × 600 px" },
    { key: "city2", label: "City photo 2", shape: "rect", size: "800 × 600 px" },
    { key: "city3", label: "City photo 3", shape: "rect", size: "800 × 600 px" },
    { key: "city4", label: "City photo 4", shape: "rect", size: "800 × 600 px" },
    { key: "reel1", label: "Reel 1", shape: "rect", size: "720 × 1120 px" },
    { key: "reel2", label: "Reel 2", shape: "rect", size: "720 × 1120 px" },
    { key: "reel3", label: "Reel 3", shape: "rect", size: "720 × 1120 px" },
    { key: "reel4", label: "Reel 4", shape: "rect", size: "720 × 1120 px" },
    { key: "why", label: "“Why choose VFI” image", shape: "circle", size: "600 × 600 px" }
  ];

  var C_LISTS = [
    { key: "universities", label: "Universities", add: "Add university",
      fields: [{ n: "name", l: "University name" }, { n: "loc", l: "City / state" },
               { n: "note1", l: "Highlight 1" }, { n: "note2", l: "Highlight 2" }] },
    { key: "scholarships", label: "Scholarships", add: "Add scholarship",
      fields: [{ n: "tag", l: "Tag (e.g. Government)" }, { n: "title", l: "Title" },
               { n: "desc", l: "Description", t: "textarea" }, { n: "amount", l: "Amount" }] },
    { key: "salaries", label: "Career salary table", add: "Add row",
      fields: [{ n: "role", l: "Job profile" }, { n: "pay", l: "Average annual salary" }] },
    { key: "faqs", label: "FAQs", add: "Add FAQ",
      fields: [{ n: "q", l: "Question" }, { n: "a", l: "Answer", t: "textarea" }] }
  ];

  var curCountry = "usa";

  function renderCountryPicker() {
    var host = $("#cPick");
    if (!host) return;
    host.innerHTML = COUNTRIES.map(function (c) {
      return '<button type="button" class="cpick__btn' + (c.slug === curCountry ? " is-on" : "") +
        '" data-slug="' + esc(c.slug) + '">' + esc(c.name) + "</button>";
    }).join("");
    $$(".cpick__btn", host).forEach(function (b) {
      b.addEventListener("click", function () {
        curCountry = b.getAttribute("data-slug");
        renderCountryPicker(); renderCountryEditor();
      });
    });
  }

  function renderCountryEditor() {
    var host = $("#cEditor");
    if (!host) return;
    var c = COUNTRIES.filter(function (x) { return x.slug === curCountry; })[0];
    var saved = VFI.country(curCountry);

    var html = '<div class="panel"><h2>' + esc(c.name) + ' — page text</h2>' +
      '<p class="panel__sub">Leave a field empty to keep the wording already on the page. ' +
      '<a href="' + esc(c.file) + '" target="_blank" rel="noopener">Open the page →</a></p>' +
      '<form id="cTextForm">' +
      C_TEXT.map(function (f) {
        var v = saved[f.name] || "";
        return '<label class="f"><span>' + esc(f.label) + "</span>" +
          (f.type === "textarea"
            ? '<textarea name="' + f.name + '" rows="3">' + esc(v) + "</textarea>"
            : '<input name="' + f.name + '" type="text" value="' + esc(v) + '" />') + "</label>";
      }).join("") +
      '<div class="formact"><button class="btn btn--pri" type="submit"><svg class="ai"><use href="#a-save"/></svg> Save text</button></div>' +
      "</form></div>";

    html += '<div class="panel"><h2>' + esc(c.name) + ' — photos</h2>' +
      '<p class="panel__sub">Upload real photos for this page. Empty slots keep the illustrated placeholder.</p>' +
      '<div class="slots" id="cSlots"></div></div>';

    html += C_LISTS.map(function (L) {
      return '<div class="panel"><h2>' + esc(c.name) + " — " + esc(L.label) + "</h2>" +
        '<p class="panel__sub">Add rows to replace this section on the page. Leave it empty to keep what is already there.</p>' +
        '<div class="rep" data-list="' + esc(L.key) + '"></div>' +
        '<div class="formact"><button class="btn" type="button" data-addrow="' + esc(L.key) + '"><svg class="ai"><use href="#a-plus"/></svg> ' + esc(L.add) + '</button>' +
        '<button class="btn btn--pri" type="button" data-savelist="' + esc(L.key) + '"><svg class="ai"><use href="#a-save"/></svg> Save ' + esc(L.label.toLowerCase()) + '</button></div></div>';
    }).join("");

    host.innerHTML = html;

    // text save
    $("#cTextForm").addEventListener("submit", function (e) {
      e.preventDefault();
      var obj = {};
      $$("#cTextForm [name]").forEach(function (el) { obj[el.name] = el.value.trim(); });
      VFI.saveCountry(curCountry, obj);
      toast("Saved — refresh the page to see it", "ok");
    });

    // image slots
    renderCountrySlots();

    // lists
    C_LISTS.forEach(function (L) {
      // scoped to #cEditor — other views (e.g. the partner page) reuse list keys such as "faqs"
      var box = $('#cEditor .rep[data-list="' + L.key + '"]');
      var items = (saved[L.key] && saved[L.key].length) ? saved[L.key].slice() : [];
      paintRows(box, L, items);
      $('#cEditor [data-addrow="' + L.key + '"]').addEventListener("click", function () {
        var cur = collectRows(box, L);
        cur.push({});
        paintRows(box, L, cur);
      });
      $('#cEditor [data-savelist="' + L.key + '"]').addEventListener("click", function () {
        var rows = collectRows(box, L).filter(function (r) {
          return Object.keys(r).some(function (k) { return r[k]; });
        });
        var o = {}; o[L.key] = rows;
        VFI.saveCountry(curCountry, o);
        toast(rows.length ? "Saved " + rows.length + " row" + (rows.length > 1 ? "s" : "") : "Cleared — page keeps its own content", "ok");
      });
    });
  }

  function paintRows(box, L, items) {
    if (!items.length) { box.innerHTML = '<p class="rep__empty">Nothing added — the page keeps its own content.</p>'; return; }
    box.innerHTML = items.map(function (it, i) {
      return '<div class="reprow"><span class="reprow__n">' + (i + 1) + "</span><div class=\"reprow__f\">" +
        L.fields.map(function (f) {
          var v = it[f.n] || "";
          if (f.t === "image") {
            return '<label class="f"><span>' + esc(f.l) + "</span>" +
              '<span class="rimg" data-f="' + f.n + '" data-imgid="' + esc(v) + '">' +
                '<span class="rimg__prev"></span>' +
                '<button type="button" class="btn btn--sm" data-up>Upload</button>' +
                '<button type="button" class="btn btn--sm btn--icon" data-rm title="Remove"><svg class="ai"><use href="#a-close"/></svg></button>' +
                '<input type="file" accept="image/*" hidden />' +
              "</span></label>";
          }
          var ph = f.p ? ' placeholder="' + esc(f.p) + '"' : "";
          return '<label class="f"><span>' + esc(f.l) + "</span>" +
            (f.t === "textarea"
              ? '<textarea data-f="' + f.n + '" rows="2"' + ph + ">" + esc(v) + "</textarea>"
              : '<input data-f="' + f.n + '" type="text" value="' + esc(v) + '"' + ph + " />") + "</label>";
        }).join("") +
        '</div><button class="btn btn--sm btn--icon" type="button" data-del title="Remove"><svg class="ai"><use href="#a-trash"/></svg></button></div>';
    }).join("");
    $$(".rimg", box).forEach(function (w) {
      var prev = $(".rimg__prev", w), inp = $("input[type=file]", w);
      function paint(u){ prev.style.backgroundImage = u ? "url(" + u + ")" : ""; w.classList.toggle("is-set", !!u); }
      var cur = w.getAttribute("data-imgid");
      if (cur) VFI.getImage(cur).then(paint);
      $("[data-up]", w).addEventListener("click", function(){ inp.click(); });
      $("[data-rm]", w).addEventListener("click", function(){ w.setAttribute("data-imgid",""); paint(null); });
      inp.addEventListener("change", function(){
        var file = inp.files && inp.files[0]; inp.value = "";
        if (!file) return;
        toast("Processing image…");
        VFI.uploadImage(file, 1200, 0.82).then(function(r){ w.setAttribute("data-imgid", r.id); paint(r.dataUrl); toast("Image ready", "ok"); })
          .catch(function(err){ toast(err.message || "Upload failed", "err"); });
      });
    });
    $$("[data-del]", box).forEach(function (b, i) {
      b.addEventListener("click", function () {
        var cur = collectRows(box, L);
        cur.splice(i, 1);
        paintRows(box, L, cur);
      });
    });
  }

  function collectRows(box, L) {
    return $$(".reprow", box).map(function (row) {
      var o = {};
      L.fields.forEach(function (f) {
        var el = row.querySelector('[data-f="' + f.n + '"]');
        if (!el) { o[f.n] = ""; return; }
        o[f.n] = (f.t === "image") ? (el.getAttribute("data-imgid") || "") : el.value.trim();
      });
      return o;
    });
  }

  function renderCountrySlots() {
    var host = $("#cSlots");
    if (!host) return;
    host.innerHTML = C_SLOTS.map(function (s) {
      return '<div class="slot" data-key="' + esc(s.key) + '">' +
        '<div class="slot__prev slot__prev--' + s.shape + '">Empty</div>' +
        '<div class="slot__label">' + esc(s.label) + "</div>" +
        '<div class="slot__size">' + esc(s.size) + "</div>" +
        '<div class="slot__act">' +
          '<button type="button" class="btn btn--sm btn--pri" data-act="up"><svg class="ai"><use href="#a-up"/></svg> Upload</button>' +
          '<button type="button" class="btn btn--sm" data-act="rm">Remove</button>' +
        "</div><input type=\"file\" accept=\"image/*\" hidden /></div>";
    }).join("");

    $$(".slot", host).forEach(function (el) {
      var mediaKey = "country_" + curCountry + "_" + el.getAttribute("data-key");
      var prev = $(".slot__prev", el), input = $("input[type=file]", el);
      function paint(url) {
        if (url) { prev.style.backgroundImage = "url(" + url + ")"; prev.textContent = ""; prev.classList.add("is-set"); }
        else { prev.style.backgroundImage = ""; prev.textContent = "Empty"; prev.classList.remove("is-set"); }
      }
      var cur = VFI.media(mediaKey);
      if (cur) VFI.getImage(cur).then(paint); else paint(null);
      $('[data-act="up"]', el).addEventListener("click", function () { input.click(); });
      $('[data-act="rm"]', el).addEventListener("click", function () {
        if (!VFI.media(mediaKey)) return;
        if (!window.confirm("Remove this image?")) return;
        VFI.setMedia(mediaKey, null); paint(null); toast("Image removed", "ok");
      });
      input.addEventListener("change", function () {
        var file = input.files && input.files[0];
        input.value = "";
        if (!file) return;
        toast("Processing image…");
        VFI.uploadImage(file, 1600, 0.82).then(function (r) {
          VFI.setMedia(mediaKey, r.id);
          paint(r.dataUrl);
          toast("Saved — refresh the page to see it", "ok");
        }).catch(function (err) { toast(err.message || "Upload failed", "err"); });
      });
    });
  }

  /* ---------------- region hub pages (Europe / Asia) ---------------- */
  var REGIONS = [
    { slug: "europe", name: "Europe", file: "europe.html" },
    { slug: "asia", name: "Asia", file: "asia.html" }
  ];
  var R_TEXT = [
    { name: "heroTitle", label: "Hero heading", type: "text" },
    { name: "heroSub", label: "Hero intro paragraph", type: "textarea" }
  ];
  var R_BAND = {
    key: "bands", label: "Country blocks", add: "Add country block",
    fields: [
      { n: "name", l: "Country name" },
      { n: "desc", l: "Description", t: "textarea" },
      { n: "facts", l: "Quick facts (one per line)", t: "textarea" },
      { n: "img1", l: "Photo 1", t: "image" },
      { n: "img2", l: "Photo 2", t: "image" },
      { n: "img3", l: "Photo 3", t: "image" }
    ]
  };
  var curRegion = "europe";

  function renderRegionPicker() {
    var host = $("#rPick");
    if (!host) return;
    host.innerHTML = REGIONS.map(function (r) {
      return '<button type="button" class="cpick__btn' + (r.slug === curRegion ? " is-on" : "") +
        '" data-slug="' + esc(r.slug) + '">' + esc(r.name) + "</button>";
    }).join("");
    $$(".cpick__btn", host).forEach(function (b) {
      b.addEventListener("click", function () {
        curRegion = b.getAttribute("data-slug");
        renderRegionPicker(); renderRegionEditor();
      });
    });
  }

  function renderRegionEditor() {
    var host = $("#rEditor");
    if (!host) return;
    var r = REGIONS.filter(function (x) { return x.slug === curRegion; })[0];
    var saved = VFI.region(curRegion);

    host.innerHTML =
      '<div class="panel"><h2>' + esc(r.name) + ' — hero text</h2>' +
      '<p class="panel__sub">Leave a field empty to keep what is already on the page. ' +
      '<a href="' + esc(r.file) + '" target="_blank" rel="noopener">Open the page →</a></p>' +
      '<form id="rTextForm">' +
      R_TEXT.map(function (f) {
        var v = saved[f.name] || "";
        return '<label class="f"><span>' + esc(f.label) + "</span>" +
          (f.type === "textarea" ? '<textarea name="' + f.name + '" rows="3">' + esc(v) + "</textarea>"
                                 : '<input name="' + f.name + '" type="text" value="' + esc(v) + '" />') + "</label>";
      }).join("") +
      '<div class="formact"><button class="btn btn--pri" type="submit"><svg class="ai"><use href="#a-save"/></svg> Save hero text</button></div></form></div>' +

      '<div class="panel"><h2>' + esc(r.name) + ' — country blocks</h2>' +
      '<p class="panel__sub">Each block becomes one coloured band on the page: country name, description, quick facts and three photos. ' +
      'Add at least one block to take over the section — leave it empty to keep the countries already on the page. ' +
      'Photos are best around <b>800 × 600 px</b>.</p>' +
      '<div class="rep" data-list="bands"></div>' +
      '<div class="formact"><button class="btn" type="button" data-addrow="bands"><svg class="ai"><use href="#a-plus"/></svg> ' + esc(R_BAND.add) + '</button>' +
      '<button class="btn btn--pri" type="button" data-savelist="bands"><svg class="ai"><use href="#a-save"/></svg> Save country blocks</button></div></div>';

    $("#rTextForm").addEventListener("submit", function (e) {
      e.preventDefault();
      var obj = {};
      $$("#rTextForm [name]").forEach(function (el) { obj[el.name] = el.value.trim(); });
      VFI.saveRegion(curRegion, obj);
      toast("Saved — refresh the page to see it", "ok");
    });

    var box = $('.rep[data-list="bands"]');
    paintRows(box, R_BAND, (saved.bands && saved.bands.length) ? saved.bands.slice() : []);
    $('[data-addrow="bands"]').addEventListener("click", function () {
      var cur = collectRows(box, R_BAND); cur.push({}); paintRows(box, R_BAND, cur);
    });
    $('[data-savelist="bands"]').addEventListener("click", function () {
      var rows = collectRows(box, R_BAND).filter(function (x) { return x.name || x.desc; });
      VFI.saveRegion(curRegion, { bands: rows });
      toast(rows.length ? "Saved " + rows.length + " country block" + (rows.length > 1 ? "s" : "") : "Cleared — page keeps its own countries", "ok");
    });
  }

  /* ---------------- student services page ---------------- */
  var SVC_BLOCK = {
    key: "blocks", label: "Service blocks", add: "Add service block",
    fields: [
      { n: "name", l: "Service name" },
      { n: "anchor", l: "Anchor id (e.g. counselling)" },
      { n: "desc", l: "Description", t: "textarea" },
      { n: "offers", l: "Offerings (one per line)", t: "textarea" },
      { n: "ctaLabel", l: "Second link label (e.g. Know More)" },
      { n: "ctaHref", l: "Second link URL" },
      { n: "img", l: "Photo", t: "image" }
    ]
  };

  function renderSvcPage() {
    var saved = VFI.servicesPage();
    var form = $("#svcTextForm");
    if (form) {
      $$("#svcTextForm [name]").forEach(function (el) { el.value = saved[el.name] || ""; });
      form.onsubmit = function (e) {
        e.preventDefault();
        var obj = {};
        $$("#svcTextForm [name]").forEach(function (el) { obj[el.name] = el.value.trim(); });
        VFI.saveServicesPage(obj);
        toast("Saved — refresh the page to see it", "ok");
      };
    }
    var box = $('#view-svcpage .rep[data-list="blocks"]');
    if (!box) return;
    paintRows(box, SVC_BLOCK, (saved.blocks && saved.blocks.length) ? saved.blocks.slice() : []);
    $('#view-svcpage [data-addrow="blocks"]').onclick = function () {
      var cur = collectRows(box, SVC_BLOCK); cur.push({}); paintRows(box, SVC_BLOCK, cur);
    };
    $('#view-svcpage [data-savelist="blocks"]').onclick = function () {
      var rows = collectRows(box, SVC_BLOCK).filter(function (x) { return x.name || x.desc; });
      VFI.saveServicesPage({ blocks: rows });
      toast(rows.length ? "Saved " + rows.length + " block" + (rows.length > 1 ? "s" : "") : "Cleared — page keeps its own blocks", "ok");
    };
  }

  /* ---------------- VFI partner page ---------------- */
  var P_TEXT = [
    { name: "heroTitle", label: "Hero heading", type: "text" },
    { name: "heroText", label: "Hero intro paragraph", type: "textarea" },
    { name: "heroBtn1", label: "Hero button 1 label", type: "text" },
    { name: "heroBtn2", label: "Hero button 2 label", type: "text" },
    { name: "appTitle", label: "Mobile app heading", type: "text" },
    { name: "appText", label: "Mobile app paragraph", type: "textarea" },
    { name: "featTitle", label: "Features section heading", type: "text" },
    { name: "featLead", label: "Features section intro paragraph", type: "textarea" },
    { name: "ctaTitle", label: "Call to action heading", type: "text" },
    { name: "ctaBtn", label: "Call to action button label", type: "text" },
    { name: "stepsTitle", label: "“How it works” heading", type: "text" },
    { name: "testTitle", label: "Testimonials heading", type: "text" },
    { name: "jobsTitle", label: "Jobs heading", type: "text" },
    { name: "faqTitle", label: "FAQ heading", type: "text" }
  ];

  var P_SLOTS = [
    { key: "partnerHero", label: "Hero platform screenshot", shape: "rect", size: "1200 × 820 px (landscape 3:2)" },
    { key: "partnerApp", label: "Mobile app visual", shape: "rect", size: "900 × 760 px (landscape 6:5)" }
  ];

  var P_LISTS = [
    { key: "features", label: "Features", add: "Add feature",
      fields: [{ n: "title", l: "Feature title" },
               { n: "text", l: "Feature description", t: "textarea" },
               { n: "imgId", l: "Feature screenshot — 1000 × 720 px", t: "image" }] },
    { key: "steps", label: "How it works steps", add: "Add step",
      fields: [{ n: "title", l: "Step title" }, { n: "desc", l: "Step description" }] },
    { key: "testimonials", label: "Testimonials", add: "Add testimonial",
      fields: [{ n: "quote", l: "Quote", t: "textarea" }, { n: "name", l: "Name / company" }] },
    { key: "jobs", label: "Jobs", add: "Add job",
      fields: [{ n: "title", l: "Job title" }, { n: "location", l: "Location" },
               { n: "type", l: "Job type (e.g. Full-time)" },
               { n: "dept", l: "Department", p: "Engineering" }] },
    { key: "faqs", label: "FAQs", add: "Add FAQ",
      fields: [{ n: "q", l: "Question" }, { n: "a", l: "Answer", t: "textarea" }] }
  ];

  /* builds the sidebar button and the whole view, straight after the Services Page one */
  function buildPartnerView() {
    var navRef = $('.ad__navbtn[data-view="svcpage"]');
    if (navRef && !$('.ad__navbtn[data-view="partner"]')) {
      var btn = document.createElement("button");
      btn.className = "ad__navbtn";
      btn.setAttribute("data-view", "partner");
      btn.innerHTML = '<svg class="ai"><use href="#a-cog"/></svg> VFI Partner Page';
      // wired here because the shared nav binding above already ran
      btn.addEventListener("click", function () { show("partner"); });
      navRef.parentNode.insertBefore(btn, navRef.nextSibling);
    }

    var viewRef = $("#view-svcpage");
    if (!viewRef || $("#view-partner")) return;

    var html = '<div class="panel"><h2>VFI Partner page — text</h2>' +
      '<p class="panel__sub">Edit the wording on <a href="vfi-partner.html" target="_blank" rel="noopener">vfi-partner.html</a>. ' +
      "Leave a field empty to keep the wording already on the page.</p>" +
      '<form id="partnerTextForm">' +
      P_TEXT.map(function (f) {
        return '<label class="f"><span>' + esc(f.label) + "</span>" +
          (f.type === "textarea"
            ? '<textarea name="' + f.name + '" rows="3"></textarea>'
            : '<input name="' + f.name + '" type="text" />') + "</label>";
      }).join("") +
      '<div class="formact"><button class="btn btn--pri" type="submit"><svg class="ai"><use href="#a-save"/></svg> Save text</button></div>' +
      "</form></div>";

    html += '<div class="panel"><h2>VFI Partner page — images</h2>' +
      '<p class="panel__sub">Upload real screenshots for this page. Empty slots keep the illustrated mock-up.</p>' +
      '<div class="slots" id="pSlots"></div></div>';

    html += P_LISTS.map(function (L) {
      return '<div class="panel"><h2>VFI Partner page — ' + esc(L.label) + "</h2>" +
        '<p class="panel__sub">Add rows to replace this section on the page. Leave it empty to keep what is already there.</p>' +
        '<div class="rep" data-list="' + esc(L.key) + '"></div>' +
        '<div class="formact"><button class="btn" type="button" data-addrow="' + esc(L.key) + '"><svg class="ai"><use href="#a-plus"/></svg> ' + esc(L.add) + "</button>" +
        '<button class="btn btn--pri" type="button" data-savelist="' + esc(L.key) + '"><svg class="ai"><use href="#a-save"/></svg> Save ' + esc(L.label.toLowerCase()) + "</button></div></div>";
    }).join("");

    var sec = document.createElement("section");
    sec.className = "ad-view";
    sec.id = "view-partner";
    sec.hidden = true;
    sec.innerHTML = html;
    viewRef.parentNode.insertBefore(sec, viewRef.nextSibling);
  }

  function renderPartnerPage() {
    var saved = VFI.partnerPage();
    var form = $("#partnerTextForm");
    if (form) {
      $$("#partnerTextForm [name]").forEach(function (el) { el.value = saved[el.name] || ""; });
      form.onsubmit = function (e) {
        e.preventDefault();
        var obj = {};
        $$("#partnerTextForm [name]").forEach(function (el) { obj[el.name] = el.value.trim(); });
        VFI.savePartnerPage(obj);
        toast("Saved — refresh the page to see it", "ok");
      };
    }

    renderPartnerSlots();

    P_LISTS.forEach(function (L) {
      var box = $('#view-partner .rep[data-list="' + L.key + '"]');
      if (!box) return;
      paintRows(box, L, (saved[L.key] && saved[L.key].length) ? saved[L.key].slice() : []);
      $('#view-partner [data-addrow="' + L.key + '"]').onclick = function () {
        var cur = collectRows(box, L); cur.push({}); paintRows(box, L, cur);
      };
      $('#view-partner [data-savelist="' + L.key + '"]').onclick = function () {
        var rows = collectRows(box, L).filter(function (r) {
          return Object.keys(r).some(function (k) { return r[k]; });
        });
        var o = {}; o[L.key] = rows;
        VFI.savePartnerPage(o);
        toast(rows.length ? "Saved " + rows.length + " row" + (rows.length > 1 ? "s" : "") : "Cleared — page keeps its own content", "ok");
      };
    });
  }

  function renderPartnerSlots() {
    var host = $("#pSlots");
    if (!host) return;
    host.innerHTML = P_SLOTS.map(function (s) {
      return '<div class="slot" data-key="' + esc(s.key) + '">' +
        '<div class="slot__prev slot__prev--' + s.shape + '">Empty</div>' +
        '<div class="slot__label">' + esc(s.label) + "</div>" +
        '<div class="slot__size">' + esc(s.size) + "</div>" +
        '<div class="slot__act">' +
          '<button type="button" class="btn btn--sm btn--pri" data-act="up"><svg class="ai"><use href="#a-up"/></svg> Upload</button>' +
          '<button type="button" class="btn btn--sm" data-act="rm">Remove</button>' +
        "</div><input type=\"file\" accept=\"image/*\" hidden /></div>";
    }).join("");

    $$(".slot", host).forEach(function (el) {
      var key = el.getAttribute("data-key");
      var prev = $(".slot__prev", el), input = $("input[type=file]", el);
      function paint(url) {
        if (url) { prev.style.backgroundImage = "url(" + url + ")"; prev.textContent = ""; prev.classList.add("is-set"); }
        else { prev.style.backgroundImage = ""; prev.textContent = "Empty"; prev.classList.remove("is-set"); }
      }
      var cur = VFI.media(key);
      if (cur) VFI.getImage(cur).then(paint); else paint(null);
      $('[data-act="up"]', el).addEventListener("click", function () { input.click(); });
      $('[data-act="rm"]', el).addEventListener("click", function () {
        if (!VFI.media(key)) return;
        if (!window.confirm("Remove this image? The illustrated mock-up comes back.")) return;
        VFI.setMedia(key, null); paint(null); toast("Image removed", "ok");
      });
      input.addEventListener("change", function () {
        var file = input.files && input.files[0];
        input.value = "";
        if (!file) return;
        toast("Processing image…");
        VFI.uploadImage(file, 1400, 0.84).then(function (r) {
          VFI.setMedia(key, r.id);
          paint(r.dataUrl);
          toast("Saved — refresh the page to see it", "ok");
        }).catch(function (err) { toast(err.message || "Upload failed", "err"); });
      });
    });
  }

  /* ---------------- page visibility ---------------- */
  var SITE_PAGES = [
    { g: "Main pages", items: [
      ["index.html", "Home", true], ["about.html", "About Us"], ["contact.html", "Contact Us"],
      ["gallery.html", "Photo Gallery"], ["events.html", "Upcoming Events"], ["blogs.html", "Blog"],
      /* blog-post.html renders every article from ?id=; switching it off would
         break all blog links, so it is listed for completeness but locked on. */
      ["blog-post.html", "Blog Article (template)", true],
      ["login.html", "Student Login"] ] },
    { g: "Student account", items: [
      ["student-profile.html", "Student Profile"], ["student-tracking.html", "Application Tracking"],
      /* Sub-flows of Student Login — listed for completeness, always on. */
      ["student-forgot.html", "Student · Reset Password", true],
      ["student-verify.html", "Student · Email Verification", true] ] },
    { g: "Services", items: [
      ["services.html", "Services"], ["test-preparation.html", "Test Preparation"],
      ["scholarships.html", "Scholarships"], ["internships.html", "Internships"],
      ["allied-services.html", "Allied Services"], ["universities.html", "Search Universities"],
      ["vfi-partner.html", "VFI Partner"],
      ["vfi-partner-login.html", "VFI Partner Login"],
      /* Sub-flows of VFI Partner Login — listed for completeness, always on. */
      ["vfi-partner-forgot.html", "VFI Partner · Reset Password", true],
      ["vfi-partner-verify.html", "VFI Partner · Email Verification", true] ] },
    { g: "Study destinations", items: [
      ["destinations.html", "Study Destinations"], ["study-in-usa.html", "Study in USA"],
      ["study-in-canada.html", "Study in Canada"], ["study-in-uk.html", "Study in the UK"],
      ["study-in-ireland.html", "Study in Ireland"], ["study-in-australia.html", "Study in Australia"],
      ["study-in-new-zealand.html", "Study in New Zealand"],
      ["europe.html", "Study in Europe"], ["asia.html", "Study in Asia"] ] },
    { g: "Company", items: [
      ["careers.html", "Careers"], ["news.html", "News & Press"],
      ["csr.html", "Corporate Social Responsibility"] ] },
    { g: "Partners & institutions", items: [
      ["for-institutions.html", "For Institutions"], ["for-partners.html", "For Partners"],
      ["for-franchisee.html", "For Franchisee"] ] },
    { g: "Legal", items: [
      ["terms.html", "Terms & Conditions"], ["privacy.html", "Privacy Policy"],
      ["payment-terms.html", "Payment Terms"] ] }
  ];

  function renderPageList() {
    var host = $("#pageList");
    if (!host) return;
    host.innerHTML = SITE_PAGES.map(function (grp) {
      return '<h3 class="pgroup">' + esc(grp.g) + "</h3>" + grp.items.map(function (it) {
        var file = it[0], label = it[1], locked = !!it[2];
        var on = VFI.pageEnabled(file);
        return '<div class="pgrow' + (locked ? " is-locked" : "") + '" data-file="' + esc(file) + '">' +
          '<div class="pgrow__main"><b>' + esc(label) + "</b><span>" + esc(file) + "</span></div>" +
          (locked
            ? '<span class="pgrow__lock">Always on</span>'
            : '<button type="button" class="tgl' + (on ? " is-on" : "") + '" role="switch" aria-checked="' + on +
              '" aria-label="Show ' + esc(label) + '"><span class="tgl__dot"></span></button>') +
          "</div>";
      }).join("");
    }).join("");

    $$(".pgrow .tgl", host).forEach(function (btn) {
      btn.addEventListener("click", function () {
        var file = btn.closest(".pgrow").getAttribute("data-file");
        var next = !btn.classList.contains("is-on");
        VFI.setPage(file, next);
        btn.classList.toggle("is-on", next);
        btn.setAttribute("aria-checked", String(next));
        toast(next ? "Page switched on" : "Page hidden from the site", "ok");
      });
    });
  }

  /* ---------------- settings ---------------- */
  function fillSettings() {
    var s = VFI.settings();
    $$("#settingsForm [name]").forEach(function (el) { el.value = s[el.name] != null ? s[el.name] : ""; });
  }
  $("#settingsForm").addEventListener("submit", function (e) {
    e.preventDefault();
    var obj = {};
    $$("#settingsForm [name]").forEach(function (el) { obj[el.name] = el.value.trim(); });
    VFI.saveSettings(obj);
    toast("Settings saved", "ok");
  });

  /* ---------------- partner console text ---------------- */
  function fillPpText() {
    var p = VFI.partnerPortal();
    $$("#ppTextForm [name]").forEach(function (el) { el.value = p[el.name] != null ? p[el.name] : ""; });
  }
  var ppTextForm = $("#ppTextForm");
  if (ppTextForm) ppTextForm.addEventListener("submit", function (e) {
    e.preventDefault();
    var obj = {};
    $$("#ppTextForm [name]").forEach(function (el) { obj[el.name] = el.value.trim(); });
    VFI.savePartnerPortal(obj);
    toast("Console text saved — refresh the console to see it", "ok");
  });

  /* ---------------- backup ---------------- */
  $("#btnExport").addEventListener("click", function () {
    VFI.exportAll().then(function (payload) {
      var blob = new Blob([JSON.stringify(payload)], { type: "application/json" });
      var a = document.createElement("a");
      a.href = URL.createObjectURL(blob);
      a.download = "vfi-backup-" + new Date().toISOString().slice(0, 10) + ".json";
      document.body.appendChild(a); a.click(); document.body.removeChild(a);
      setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
      toast("Backup downloaded", "ok");
    }).catch(function (e) { toast(e.message || "Export failed", "err"); });
  });

  $("#btnImport").addEventListener("click", function () { $("#importInput").click(); });
  $("#importInput").addEventListener("change", function () {
    var f = this.files && this.files[0];
    this.value = "";
    if (!f) return;
    if (!window.confirm("Import this backup? It replaces the content currently stored in this browser.")) return;
    var fr = new FileReader();
    fr.onload = function () {
      var payload;
      try { payload = JSON.parse(fr.result); } catch (e) { return toast("That file isn't valid JSON", "err"); }
      VFI.importAll(payload).then(function (n) {
        refreshCounts(); show(current);
        toast("Imported (" + n + " image" + (n === 1 ? "" : "s") + ")", "ok");
      }).catch(function (e) { toast(e.message || "Import failed", "err"); });
    };
    fr.readAsText(f);
  });

  $("#btnReset").addEventListener("click", function () {
    if (!window.confirm("Reset all content back to the original demo data?")) return;
    VFI.reset();
    refreshCounts(); show(current);
    toast("Content reset", "ok");
  });

  /* ---------------- boot ---------------- */
  if (!VFI.storageOK()) {
    var w = $("#adWarn");
    w.hidden = false;
    w.innerHTML = "<b>Storage is blocked in this browser.</b> Nothing you save here will stick. " +
      "Open the site through a local server (for example <code>python -m http.server 8000</code>) instead of double-clicking the file.";
  }
  /* Sign out. POST /api/admin/logout has existed since Phase 1 with no caller,
     so an admin session could only be ended by clearing the cookie by hand.
     VFIApi (js/api.js) handles the CSRF cookie for us. */
  var signOut = $("#adSignOut");
  if (signOut) {
    signOut.addEventListener("click", function () {
      signOut.disabled = true;
      var done = function () { window.location.href = "admin-login.html"; };
      if (window.VFIApi) {
        window.VFIApi.post("/api/admin/logout", {}, {}).then(done)["catch"](done);
      } else {
        done();
      }
    });
  }

  buildPartnerView();
  refreshCounts();
  show("dashboard");
})();
