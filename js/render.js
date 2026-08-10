/* =====================================================================
   VFI — render public pages from the admin store
   Loads after site.js + main.js. Falls back silently if nothing is saved.
   ===================================================================== */
(function () {
  "use strict";
  if (!window.VFI) return;

  var $ = function (s, c) { return (c || document).querySelector(s); };
  var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };
  var esc = VFI.esc;

  /* paint an element's background from a stored image id */
  function paint(el, imgId) {
    if (!el || !imgId) return;
    VFI.getImage(imgId).then(function (url) {
      if (!url) return;
      el.style.backgroundImage = "url(" + url + ")";
      el.style.backgroundSize = "cover";
      el.style.backgroundPosition = "center";
    });
  }

  /* ---------------- events (events.html) ---------------- */
  function renderEvents(host) {
    var items = VFI.list("events");
    if (!items.length) return;
    var limit = parseInt(host.getAttribute("data-limit"), 10) || items.length;
    items = items.slice(0, limit);
    host.innerHTML = items.map(function (e) {
      return '<article class="event">' +
        '<div class="event__media event__media--' + esc(e.color || "a") + '" data-img="' + esc(e.imgId || "") + '">' +
          '<span class="event__badge"><svg class="ic ic--sm"><use href="#i-calendar"/></svg> ' + esc(VFI.fmtDay(e.date)) + "</span>" +
        "</div>" +
        '<div class="event__body">' +
          '<span class="event__tag">' + esc(e.type || "Event") + "</span>" +
          "<h3>" + esc(e.title) + "</h3>" +
          "<p>" + esc(e.desc || "") + "</p>" +
          '<a href="contact.html" class="link-more">Register <svg class="ic ic--sm"><use href="#i-arrow"/></svg></a>' +
        "</div></article>";
    }).join("");
    $$("[data-img]", host).forEach(function (el) { paint(el, el.getAttribute("data-img")); });
  }

  /* ---------------- featured events (home) ---------------- */
  function renderFeatured(host) {
    var items = VFI.list("events").slice(0, 3);
    if (items.length < 1) return;
    var big = items[0], rest = items.slice(1, 3);
    var html = '<article class="fev fev--big">' +
        '<div class="fev__media fev__media--' + esc(big.color || "a") + '" data-img="' + esc(big.imgId || "") + '">' +
          '<span class="fev__tag">' + esc(big.type || "Event") + "</span>" +
          '<div class="fev__promo"><b>' + esc(big.title) + "</b><span>" + esc(VFI.fmtDate(big.date)) +
            (big.time ? " · " + esc(big.time) : "") + "</span></div>" +
        "</div>" +
        '<div class="fev__body">' +
          '<span class="fev__date">' + esc(VFI.fmtDate(big.date)) + (big.time ? " · " + esc(big.time) : "") + "</span>" +
          "<h3>" + esc(big.title) + "</h3><p>" + esc(big.desc || "") + "</p>" +
          '<a href="events.html" class="see-more">Register Now <svg class="ic ic--sm"><use href="#i-arrow"/></svg></a>' +
        "</div></article>";
    html += '<div class="fevents__col">' + rest.map(function (e) {
      return '<article class="fev fev--row">' +
        '<div class="fev__media fev__media--' + esc(e.color || "b") + '" data-img="' + esc(e.imgId || "") + '"></div>' +
        '<div class="fev__body">' +
          '<span class="fev__date">' + esc(VFI.fmtDate(e.date)) + (e.time ? " · " + esc(e.time) : "") + "</span>" +
          "<h3>" + esc(e.title) + "</h3><p>" + esc(e.desc || "") + "</p>" +
          '<a href="events.html" class="see-more">Register Now <svg class="ic ic--sm"><use href="#i-arrow"/></svg></a>' +
        "</div></article>";
    }).join("") + "</div>";
    host.innerHTML = html;
    $$("[data-img]", host).forEach(function (el) { paint(el, el.getAttribute("data-img")); });
  }

  /* ---------------- blogs ----------------
     Every card links to the article page. The whole card is clickable: the
     anchor sits on the title (so its accessible name is the title) and a
     .bp-stretch overlay covers the card. See the BLOG POST block in style.css. */
  function blogHref(b) { return "blog-post.html?id=" + encodeURIComponent(b.id || ""); }

  function blogCard(b, wide) {
    var link = '<h3><a class="bp-stretch" href="' + esc(blogHref(b)) + '">' + esc(b.title) + "</a></h3>";
    if (wide) {
      return '<article class="blog blog--wide bp-linked">' +
        '<div class="blog__media blog__media--' + esc(b.color || "a") + '" data-img="' + esc(b.imgId || "") + '">' +
          '<span class="blog__overlay">' + esc(b.category || "") + "</span></div>" +
        '<div class="blog__body"><span class="blog__meta">' + esc((b.category || "STUDY ABROAD").toUpperCase()) + "</span>" +
        link + '<p class="blog__date">' + esc(VFI.fmtDate(b.date)) + "</p></div></article>";
    }
    return '<article class="blog bp-linked">' +
      '<div class="blog__media blog__media--' + esc(b.color || "a") + '" data-img="' + esc(b.imgId || "") + '"></div>' +
      '<div class="blog__body"><span class="blog__meta">' + esc(b.category || "Blog") + " · " + esc(VFI.fmtDate(b.date)) + "</span>" +
      link + "<p>" + esc(b.excerpt || "") + "</p></div></article>";
  }

  function renderBlogs(host) {
    var items = VFI.list("blogs");
    if (!items.length) return;
    var limit = parseInt(host.getAttribute("data-limit"), 10) || items.length;
    var wide = host.getAttribute("data-style") === "wide";
    items = items.slice(0, limit);
    host.innerHTML = items.map(function (b) { return blogCard(b, wide); }).join("");
    $$("[data-img]", host).forEach(function (el) { paint(el, el.getAttribute("data-img")); });
  }

  /* ---------------- featured blog card (blogs.html) ----------------
     Fills the card that is already on the page so its design is untouched. */
  function renderFeatureBlog(host) {
    var b = VFI.list("blogs")[0];
    if (!b) return;
    var meta = $(".blog__meta", host);
    var title = $("h2 a", host) || $("h2", host);   /* the h2 holds the card-wide link */
    var p = $(".feature-post__body p", host);
    if (meta) meta.textContent = "Featured · " + (b.category || "Blog");
    if (title) title.textContent = b.title || "";
    if (p) p.textContent = b.excerpt || "";
    $$("a[href]", host).forEach(function (a) {
      if (a.getAttribute("href").indexOf("blog-post.html") === 0) a.setAttribute("href", blogHref(b));
    });
    if (b.imgId) paint($(".feature-post__media", host), b.imgId);
  }

  /* ---------------- news (home) ---------------- */
  function renderNews(host) {
    var items = VFI.list("news");
    if (!items.length) return;
    var html = items.map(function (n) {
      return '<article class="news">' +
        '<div class="news__media news__media--' + esc(n.color === "b" ? "b" : "a") + '" data-img="' + esc(n.imgId || "") + '"></div>' +
        "<h3>" + esc(n.title) + "</h3><p>" + esc(n.excerpt || "") + "</p>" +
        '<a href="blogs.html" class="see-more">Read More <svg class="ic ic--sm"><use href="#i-arrow"/></svg></a>' +
        "</article>";
    }).join("");
    host.innerHTML = html;
    $$("[data-img]", host).forEach(function (el) { paint(el, el.getAttribute("data-img")); });
    // content changed — rebuild the auto-slider around it
    var slider = host.closest ? host.closest("[data-autoslide]") : null;
    if (slider && window.VFIAutoSlide) window.VFIAutoSlide(slider);
  }

  /* ---------------- photo gallery ---------------- */
  function renderPhotos(host) {
    var items = VFI.list("photos");
    if (!items.length) {
      host.innerHTML = '<p class="gal-empty">No photos have been added yet. Open the admin panel to upload some.</p>';
      return;
    }
    host.innerHTML = items.map(function (p) {
      return '<figure class="gcard">' +
        '<div class="gcard__img" data-img="' + esc(p.imgId || "") + '"></div>' +
        (p.title || p.caption ? '<figcaption class="gcard__cap"><b>' + esc(p.title || "") + "</b>" +
          (p.caption ? "<span>" + esc(p.caption) + "</span>" : "") + "</figcaption>" : "") +
        "</figure>";
    }).join("");
    $$("[data-img]", host).forEach(function (el) { paint(el, el.getAttribute("data-img")); });
  }

  /* ---------------- blog article (blog-post.html) ----------------
     Reads ?id=<blogId> and paints the stored post into the page skeleton.
     Body text is PLAIN TEXT and is always escaped — never injected as HTML.
     Convention: blank line = new paragraph · "## " = subheading ·
                 "- " = list item · "> " = pull quote. */
  function param(name) {
    var m = new RegExp("[?&]" + name + "=([^&#]*)").exec(location.search || "");
    if (!m) return "";
    try { return decodeURIComponent(m[1].replace(/\+/g, " ")); } catch (e) { return m[1]; }
  }

  function articleHTML(body) {
    var lines = String(body == null ? "" : body).replace(/\r\n?/g, "\n").split("\n");
    var out = [], para = [], items = [], quote = [];
    function flushPara() {
      if (para.length) { out.push("<p>" + esc(para.join(" ")) + "</p>"); para = []; }
    }
    function flushList() {
      if (items.length) {
        out.push('<ul class="bp-list">' + items.map(function (t) {
          return "<li>" + esc(t) + "</li>";
        }).join("") + "</ul>");
        items = [];
      }
    }
    function flushQuote() {
      if (quote.length) { out.push('<blockquote class="bp-quote"><p>' + esc(quote.join(" ")) + "</p></blockquote>"); quote = []; }
    }
    function flushAll() { flushPara(); flushList(); flushQuote(); }

    lines.forEach(function (raw) {
      var t = String(raw).replace(/^\s+|\s+$/g, "");
      if (!t) { flushAll(); return; }
      if (t.indexOf("## ") === 0) { flushAll(); out.push('<h2 class="bp-h2">' + esc(t.slice(3)) + "</h2>"); return; }
      if (t.indexOf("- ") === 0) { flushPara(); flushQuote(); items.push(t.slice(2)); return; }
      if (t.indexOf("> ") === 0) { flushPara(); flushList(); quote.push(t.slice(2)); return; }
      flushList(); flushQuote(); para.push(t);
    });
    flushAll();
    return out.join("");
  }

  function readingTime(post) {
    if (post.readTime) return post.readTime;
    var n = String(post.body || post.excerpt || "").split(/\s+/).filter(function (w) { return w; }).length;
    return Math.max(1, Math.ceil(n / 200)) + " min read";
  }

  function applyArticle() {
    if (document.body.getAttribute("data-article") !== "blog") return;
    var slot = {};
    $$("[data-bp]").forEach(function (el) { slot[el.getAttribute("data-bp")] = el; });

    var posts = VFI.list("blogs");
    var id = param("id"), post = null;
    for (var i = 0; i < posts.length; i++) if (posts[i].id === id) { post = posts[i]; break; }

    /* ---- unknown or missing id: a friendly dead end, never a blank page ---- */
    if (!post) {
      document.title = "Post not found | VFI Overseas Education";
      if (slot.title) slot.title.textContent = "We can’t find that post";
      if (slot.crumb) slot.crumb.textContent = "Not found";
      if (slot.tag) slot.tag.hidden = true;
      if (slot.meta) slot.meta.hidden = true;
      if (slot.coverwrap) slot.coverwrap.hidden = true;
      if (slot.tools) slot.tools.hidden = true;
      if (slot.body) {
        slot.body.innerHTML = "<p>The link you followed points to a post that has been renamed, " +
          "removed, or never existed. Nothing is broken on your side.</p>" +
          "<p>Everything we have published is on the blog index — the piece you were after is very likely still there.</p>" +
          '<p class="bp-nf"><a class="btn btn--enquire btn--lg" href="blogs.html">Back to all posts ' +
          '<svg class="ic"><use href="#i-arrow"/></svg></a></p>';
      }
      fillRelated(slot, posts.slice(0, 3), "Latest posts");
      return;
    }

    /* ---- head + hero ---- */
    document.title = (post.title || "Article") + " | VFI Overseas Education";
    var metaDesc = $('meta[name="description"]');
    if (metaDesc && post.excerpt) metaDesc.setAttribute("content", post.excerpt);
    if (slot.title) slot.title.textContent = post.title || "";
    if (slot.crumb) slot.crumb.textContent = post.title || "Article";
    if (slot.tag) slot.tag.textContent = post.category || "Study abroad";
    if (slot.date) slot.date.textContent = VFI.fmtDate(post.date);
    if (slot.read) slot.read.textContent = readingTime(post);
    if (slot.author) {
      if (post.author) slot.author.textContent = post.author;
      else if (slot.byline) slot.byline.hidden = true;
    }

    /* ---- cover ---- */
    if (slot.cover) {
      slot.cover.className = "bp-cover bp-cover--" + (post.color || "a");
      if (post.imgId) {
        VFI.getImage(post.imgId).then(function (url) {
          if (!url) return;
          slot.cover.style.backgroundImage = "url(" + url + ")";
          slot.cover.style.backgroundSize = "cover";
          slot.cover.style.backgroundPosition = "center";
          slot.cover.classList.add("has-photo");
        });
      }
    }

    /* ---- body ---- */
    if (slot.body) {
      var html = articleHTML(post.body);
      if (!html) {
        html = (post.excerpt ? "<p>" + esc(post.excerpt) + "</p>" : "") +
          '<p class="bp-note">The full text of this post has not been published yet. ' +
          'Add it in the admin panel under Blogs → Body / article content.</p>';
      }
      slot.body.innerHTML = html;
    }

    /* ---- share links ---- */
    var url = location.href, title = post.title || "VFI Overseas Education";
    var share = {
      fb: "https://www.facebook.com/sharer/sharer.php?u=" + encodeURIComponent(url),
      x: "https://twitter.com/intent/tweet?url=" + encodeURIComponent(url) + "&text=" + encodeURIComponent(title),
      "in": "https://www.linkedin.com/sharing/share-offsite/?url=" + encodeURIComponent(url),
      mail: "mailto:?subject=" + encodeURIComponent(title) + "&body=" + encodeURIComponent(title + " — " + url)
    };
    Object.keys(share).forEach(function (k) {
      var el = slot["share-" + k];
      if (el) el.setAttribute("href", share[k]);
    });

    /* ---- related posts ---- */
    fillRelated(slot, posts.filter(function (b) { return b.id !== post.id; }).slice(0, 3), null);
  }

  function fillRelated(slot, items, heading) {
    var host = slot.related;
    if (!host) return;
    if (!items.length) {
      if (slot.relatedsec) slot.relatedsec.hidden = true;
      return;
    }
    if (heading && slot.relatedhead) slot.relatedhead.textContent = heading;
    host.innerHTML = items.map(function (b) { return blogCard(b, false); }).join("");
    $$("[data-img]", host).forEach(function (el) { paint(el, el.getAttribute("data-img")); });
  }

  /* ---------------- site settings into header/footer ---------------- */
  function applySettings() {
    var s = VFI.settings();
    if (!s) return;

    $$(".brand__text").forEach(function (el) {
      el.innerHTML = esc(s.brand || "VFI") + "<small>" + esc(s.tagline || "") + "</small>";
    });
    var about = $(".footer__brand p");
    if (about && s.about) about.textContent = s.about;

    var contact = $(".footer__contact");
    if (contact) {
      var lis = $$("li", contact);
      if (lis[0] && s.addressShort) lis[0].innerHTML = '<svg class="ic ic--sm"><use href="#i-pin"/></svg> ' + esc(s.addressShort);
      if (lis[1] && s.phone) lis[1].innerHTML = '<svg class="ic ic--sm"><use href="#i-phone"/></svg> <a href="tel:' + esc(s.phone.replace(/[^\d+]/g, "")) + '">' + esc(s.phone) + "</a>";
      if (lis[2] && s.email) lis[2].innerHTML = '<svg class="ic ic--sm"><use href="#i-mail"/></svg> <a href="mailto:' + esc(s.email) + '">' + esc(s.email) + "</a>";
    }

    var socials = $$(".socials a");
    [s.facebook, s.instagram, s.linkedin, s.x, s.youtube].forEach(function (url, i) {
      if (socials[i] && url) socials[i].setAttribute("href", url);
    });

    // contact page details
    $$("[data-set]").forEach(function (el) {
      var key = el.getAttribute("data-set");
      if (!s[key]) return;
      if (el.tagName === "A" && key === "email") { el.href = "mailto:" + s[key]; el.textContent = s[key]; }
      else if (el.tagName === "A" && /phone/.test(key)) { el.href = "tel:" + s[key].replace(/[^\d+]/g, ""); el.textContent = s[key]; }
      else el.textContent = s[key];
    });
  }

  /* ---------------- named image slots (home page visuals) ---------------- */
  function applyMedia() {
    if (!VFI.media) return;
    $$("[data-media]").forEach(function (el) {
      var imgId = VFI.media(el.getAttribute("data-media"));
      if (!imgId) return;
      VFI.getImage(imgId).then(function (url) {
        if (!url) return;
        el.style.backgroundImage = "url(" + url + ")";
        el.style.backgroundSize = "cover";
        el.style.backgroundPosition = "center";
        el.classList.add("has-photo");
        if (el.classList.contains("bhero__photo")) {
          var svg = $(".bhero__svg", el.parentNode);
          if (svg) svg.style.display = "none";
        }
      });
    });
  }

  /* ---------------- country page overrides ---------------- */
  function applyCountry() {
    var slug = document.body.getAttribute("data-country");
    if (!slug || !VFI.country) return;
    var c = VFI.country(slug);
    if (!c) return;

    // plain text fields
    $$("[data-cfield]").forEach(function (el) {
      var v = c[el.getAttribute("data-cfield")];
      if (v) el.textContent = v;
    });

    // universities
    var uHost = $('[data-crender="universities"]');
    if (uHost && c.universities && c.universities.length) {
      uHost.innerHTML = c.universities.map(function (u, i) {
        return '<article class="unic">' +
          '<div class="unic__logo unic__logo--' + (["a", "b", "c"][i % 3]) + '" data-media="country_' + esc(slug) + "_uni" + (i + 1) + '"><svg class="ic"><use href="#i-cap"/></svg></div>' +
          "<h3>" + esc(u.name || "") + "</h3>" +
          '<p class="unic__loc"><svg class="ic ic--sm"><use href="#i-pin"/></svg> ' + esc(u.loc || "") + "</p>" +
          '<ul class="unic__meta">' + (u.note1 ? "<li>" + esc(u.note1) + "</li>" : "") + (u.note2 ? "<li>" + esc(u.note2) + "</li>" : "") + "</ul>" +
          '<div class="unic__cta"><a href="contact.html" class="btn btn--outline btn--sm">Know More</a>' +
          '<a href="contact.html" class="btn btn--enquire btn--sm">Apply Now</a></div></article>';
      }).join("");
    }

    // scholarships
    var sHost = $('[data-crender="scholarships"]');
    if (sHost && c.scholarships && c.scholarships.length) {
      sHost.innerHTML = c.scholarships.map(function (s) {
        return '<article class="schol">' + (s.tag ? '<span class="schol__tag">' + esc(s.tag) + "</span>" : "") +
          "<h3>" + esc(s.title || "") + "</h3><p>" + esc(s.desc || "") + "</p>" +
          (s.amount ? "<b>" + esc(s.amount) + "</b>" : "") + "</article>";
      }).join("");
    }

    // salary table
    var salHost = $('[data-crender="salaries"]');
    if (salHost && c.salaries && c.salaries.length) {
      salHost.innerHTML = c.salaries.map(function (r) {
        return "<tr><td>" + esc(r.role || "") + "</td><td>" + esc(r.pay || "") + "</td></tr>";
      }).join("");
    }

    // FAQs
    var fHost = $('[data-crender="faqs"]');
    if (fHost && c.faqs && c.faqs.length) {
      fHost.innerHTML = c.faqs.map(function (f, i) {
        return '<details class="acc"' + (i === 0 ? " open" : "") + "><summary>" +
          '<span class="acc__title">' + esc(f.q || "") + "</span>" +
          '<svg class="ic acc__chev"><use href="#i-chevron"/></svg></summary>' +
          '<div class="acc__body"><p>' + esc(f.a || "") + "</p></div></details>";
      }).join("");
      if (window.VFIInitAccordions) window.VFIInitAccordions();
    }

    // repaint any image slots inside freshly rendered markup
    applyMedia();
  }

  /* ---------------- region hub overrides ---------------- */
  function applyRegion() {
    var slug = document.body.getAttribute("data-region");
    if (!slug || !VFI.region) return;
    var d = VFI.region(slug);
    if (!d) return;

    $$("[data-rfield]").forEach(function (el) {
      var v = d[el.getAttribute("data-rfield")];
      if (v) el.textContent = v;
    });

    var host = $('[data-rrender="bands"]');
    if (!host || !d.bands || !d.bands.length) return;
    var tones = ["blue", "peach", "pink", "lav"];
    host.innerHTML = d.bands.map(function (b, i) {
      var flip = i % 2 === 1;
      var facts = String(b.facts || "").split("\n").filter(function (x) { return x.trim(); }).map(function (t) {
        return '<li><span class="eqf__ic"><svg class="ic"><use href="#i-checks"/></svg></span>' + esc(t.trim()) + "</li>";
      }).join("");
      var slugify = String(b.name || ("c" + i)).toLowerCase().replace(/[^a-z0-9]+/g, "-");
      var collage = '<div class="ecollage" aria-hidden="true">' +
        ["a", "b", "c"].map(function (t, k) {
          return '<span class="ecollage__t ecollage__t--' + t + '" data-img="' + esc(b["img" + (k + 1)] || "") + '"></span>';
        }).join("") + "</div>";
      var text = '<div class="eband__text"><h3 id="c-' + esc(slugify) + '">' + esc(b.name || "") + "</h3>" +
        "<p>" + esc(b.desc || "") + "</p>" +
        (facts ? '<h4>Quick facts</h4><ul class="eqf">' + facts + "</ul>" : "") +
        '<a href="contact.html" class="see-more">Know More <svg class="ic ic--sm"><use href="#i-arrow"/></svg></a></div>';
      return '<section class="eband eband--' + tones[i % 4] + '"><div class="container eband__inner' +
        (flip ? " eband__inner--flip" : "") + '">' + (flip ? collage + text : text + collage) + "</div></section>";
    }).join("");
    $$("[data-img]", host).forEach(function (el) { paint(el, el.getAttribute("data-img")); });

    var sel = $("#destSelect");
    if (sel) {
      sel.innerHTML = '<option value="">Select Country</option>' + d.bands.map(function (b, i) {
        var sg = String(b.name || ("c" + i)).toLowerCase().replace(/[^a-z0-9]+/g, "-");
        return '<option value="c-' + esc(sg) + '">' + esc(b.name || "") + "</option>";
      }).join("");
    }
  }

  /* ---------------- student services page overrides ---------------- */
  function applyServices() {
    if (!document.body.getAttribute("data-svcpage") || !VFI.servicesPage) return;
    var d = VFI.servicesPage();
    if (!d) return;

    $$("[data-sfield]").forEach(function (el) {
      var v = d[el.getAttribute("data-sfield")];
      if (v) el.textContent = v;
    });

    var host = $('[data-srender="blocks"]');
    if (!host || !d.blocks || !d.blocks.length) return;
    host.innerHTML = d.blocks.map(function (b, i) {
      var flip = i % 2 === 1;
      var anchor = (b.anchor || String(b.name || ("s" + i)).toLowerCase().replace(/[^a-z0-9]+/g, "-"));
      var offers = String(b.offers || "").split("\n").filter(function (x) { return x.trim(); }).map(function (t) {
        return '<li><span class="starlist__ic"><svg class="ic"><use href="#i-star"/></svg></span>' + esc(t.trim()) + "</li>";
      }).join("");
      var media = '<div class="svcrow__media" aria-hidden="true">' +
        '<span class="svcphoto" data-img="' + esc(b.img || "") + '">' +
        '<svg class="ic svcphoto__ic"><use href="#i-star"/></svg></span>' +
        '<span class="svcbadge svcbadge--tr"><svg class="ic"><use href="#i-check-c"/></svg></span>' +
        '<span class="svcbadge svcbadge--bl"><svg class="ic"><use href="#i-thumb"/></svg></span></div>';
      var text = '<div class="svcrow__text"><h3 id="' + esc(anchor) + '">' + esc(b.name || "") + "</h3>" +
        "<p>" + esc(b.desc || "") + "</p>" +
        (offers ? '<h4>Offerings</h4><ul class="starlist">' + offers + "</ul>" : "") +
        '<div class="svcrow__cta"><a href="contact.html" class="btn btn--outline">Enquire Now</a>' +
        (b.ctaLabel ? '<a href="' + esc(b.ctaHref || "contact.html") + '" class="see-more">' + esc(b.ctaLabel) +
          ' <svg class="ic ic--sm"><use href="#i-arrow"/></svg></a>' : "") +
        "</div></div>";
      return '<article class="svcrow' + (flip ? " svcrow--flip" : "") + '">' +
        (flip ? media + text : text + media) + "</article>";
    }).join("");
    $$("[data-img]", host).forEach(function (el) {
      var id = el.getAttribute("data-img");
      if (id) { paint(el, id); el.classList.add("has-photo"); }
    });
  }

  /* ---------------- VFI partner page overrides ---------------- */
  /* every field the repeater rows can fill inside a cloned card */
  var P_FIELDS = ["title", "text", "quote", "name", "desc", "location", "type", "q", "a"];
  var P_LISTS = ["features", "steps", "testimonials", "jobs", "faqs"];

  /* show a stored photo in place of the built-in mock-up */
  function pshot(img, url) {
    if (!img || !url) return;
    img.setAttribute("src", url);
    img.removeAttribute("hidden");
    var wrap = img.closest ? img.closest("[data-pshot]") : null;
    if (wrap) wrap.classList.add("has-img");
  }

  function pimg(img, imgId) {
    if (!img || !imgId) return;
    VFI.getImage(imgId).then(function (url) { pshot(img, url); });
  }

  /* Cards are cloned from the FIRST built-in card, so any ordinal printed in
     decorative markup ("1", "Step 1") would repeat on every row. Only a lone
     1 — the first card's own number — is treated as a counter and rewritten. */
  function renumber(node, n) {
    var els = node.querySelectorAll("*");
    for (var i = 0; i < els.length; i++) {
      var el = els[i];
      if (el.children.length || el.hasAttribute("data-pf")) continue;
      var m = /^(\D*)1(\D*)$/.exec(el.textContent);
      if (m) el.textContent = m[1] + n + m[2];
    }
  }

  function applyPartner() {
    if (!document.body.getAttribute("data-partner") || !VFI.partnerPage) return;
    var d = VFI.partnerPage();
    if (!d) return;

    /* plain text fields — a blank value keeps the wording already on the page */
    $$("[data-pfield]").forEach(function (el) {
      var v = d[el.getAttribute("data-pfield")];
      if (typeof v === "string" && v) el.textContent = v;
    });

    /* repeaters — each container's first child is the template for its rows */
    P_LISTS.forEach(function (key) {
      var host = $('[data-prender="' + key + '"]');
      var rows = d[key];
      if (!host || !rows || !rows.length) return;
      var tpl = host.firstElementChild;
      if (!tpl) return;
      tpl = tpl.cloneNode(true);

      var frag = document.createDocumentFragment();
      rows.forEach(function (it, i) {
        var node = tpl.cloneNode(true);
        renumber(node, i + 1);
        P_FIELDS.forEach(function (f) {
          var v = it[f];
          if (v === undefined || v === null) return;
          var cell = node.querySelector('[data-pf="' + f + '"]');
          if (cell) cell.textContent = v;
        });
        if (key === "features") {
          // keep the alternating left / right layout
          node.classList.toggle("is-flip", i % 2 === 1);
          pimg(node.querySelector("img[data-pimg]"), it.imgId || it.img);
        }
        if (key === "jobs") {
          // the template card carries its own department — set ours or the tab filter mis-sorts it
          node.setAttribute("data-dept", it.dept || "All");
        }
        if (key === "faqs") {
          if (i === 0) node.setAttribute("open", "");
          else node.removeAttribute("open");
        }
        frag.appendChild(node);
      });
      host.innerHTML = "";
      host.appendChild(frag);
      // let site.js / main.js re-hook the fresh cards; never let them abort this pass
      try {
        if (key === "faqs" && window.VFIInitAccordions) window.VFIInitAccordions();
        if (key === "jobs" && window.VFIFilterJobs) window.VFIFilterJobs();
      } catch (e) { console.warn("partner rehook (" + key + ")", e); }
    });

    /* the two fixed image slots */
    ["partnerHero", "partnerApp"].forEach(function (key) {
      pimg($('img[data-pimg="' + key + '"]'), VFI.media ? VFI.media(key) : null);
    });
  }

  /* ---------------- this page switched off? ---------------- */
  function pageOffNotice() {
    if (!VFI.pageEnabled) return false;
    var f = VFI.baseName(location.pathname) || "index.html";
    if (f === "index.html" || VFI.pageEnabled(f)) return false;
    var main = document.getElementById("main");
    if (!main) return false;
    main.innerHTML = '<section class="section"><div class="container"><div class="pageoff">' +
      '<h1>This page is currently unavailable</h1>' +
      "<p>It has been switched off by the site administrator. Please check back soon.</p>" +
      '<a href="index.html" class="btn btn--enquire btn--lg">Back to home</a>' +
      "</div></div></section>";
    return true;
  }

  /* ---------------- boot ---------------- */
  function boot() {
    try { if (pageOffNotice()) return; } catch (e) { /* ignore */ }
    try { applySettings(); } catch (e) { console.warn("settings render", e); }
    try { applyMedia(); } catch (e) { console.warn("media render", e); }
    try { applyCountry(); } catch (e) { console.warn("country render", e); }
    try { applyRegion(); } catch (e) { console.warn("region render", e); }
    try { applyServices(); } catch (e) { console.warn("services render", e); }
    try { applyPartner(); } catch (e) { console.warn("partner render", e); }
    try { applyArticle(); } catch (e) { console.warn("article render", e); }
    var map = { events: renderEvents, fevents: renderFeatured, blogs: renderBlogs, fblog: renderFeatureBlog, news: renderNews, photos: renderPhotos };
    $$("[data-render]").forEach(function (host) {
      var fn = map[host.getAttribute("data-render")];
      if (fn) { try { fn(host); } catch (e) { console.warn("render", e); } }
    });
    /* Re-arm scroll animations last. Freshly rendered cards may carry the
       .reveal class, and without this they would sit at opacity 0 forever
       because no observer is watching them. */
    try { if (window.VFIInitReveal) window.VFIInitReveal(); } catch (e) { /* ignore */ }
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
})();
