/* =====================================================================
   VFI Overseas Education — shared chrome (sprite + header + footer)
   Injected on every page so the nav/footer stay in one place.
   Runs before main.js (which binds interactions).
   ===================================================================== */
(function () {
  "use strict";

  var SPRITE = '' +
  '<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false"><defs>' +
  '<symbol id="i-book" viewBox="0 0 24 24"><path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v18H6.5A2.5 2.5 0 0 0 4 22.5V4.5Z"/><path d="M4 4.5A2.5 2.5 0 0 0 6.5 7H20"/></symbol>' +
  '<symbol id="i-doc" viewBox="0 0 24 24"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z"/><path d="M9 13h6M9 17h4"/></symbol>' +
  '<symbol id="i-money" viewBox="0 0 24 24"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/></symbol>' +
  '<symbol id="i-passport" viewBox="0 0 24 24"><rect x="5" y="2" width="14" height="20" rx="2"/><circle cx="12" cy="9" r="3"/><path d="M9 16h6"/></symbol>' +
  '<symbol id="i-plane" viewBox="0 0 24 24"><path d="M17.8 19.2 16 11l3.5-3.5a2.1 2.1 0 0 0-3-3L13 8 4.8 6.2a.9.9 0 0 0-.9 1.4l3.3 4.4-2.4 2.4-2-.6a.7.7 0 0 0-.7 1.1L6 18l2.7 4.3a.7.7 0 0 0 1.1-.7l-.6-2 2.4-2.4 4.4 3.3a.9.9 0 0 0 1.4-.9Z"/></symbol>' +
  '<symbol id="i-compass" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="m16.2 7.8-2.9 6.4-6.4 2.9 2.9-6.4 6.4-2.9Z"/></symbol>' +
  '<symbol id="i-award" viewBox="0 0 24 24"><circle cx="12" cy="9" r="6"/><path d="M8.2 13.5 7 22l5-3 5 3-1.2-8.5"/></symbol>' +
  '<symbol id="i-home" viewBox="0 0 24 24"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9.5"/><path d="M9.5 21v-6h5v6"/></symbol>' +
  '<symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 2 4 5v6c0 5 3.4 8.5 8 11 4.6-2.5 8-6 8-11V5l-8-3Z"/><path d="m9 12 2 2 4-4"/></symbol>' +
  '<symbol id="i-present" viewBox="0 0 24 24"><path d="M3 4h18"/><path d="M4 4v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V4"/><path d="m9 21 3-3 3 3"/><path d="M12 12v4"/></symbol>' +
  '<symbol id="i-check" viewBox="0 0 24 24"><path d="m5 12 4.5 4.5L19 7"/></symbol>' +
  '<symbol id="i-checks" viewBox="0 0 24 24"><path d="m2 12.5 4 4 8-9"/><path d="m10 16.5 1.5 1.5L21 8"/></symbol>' +
  '<symbol id="i-check-c" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8.4 12 2.3 2.3 4.9-4.9"/></symbol>' +
  '<symbol id="i-bed" viewBox="0 0 24 24"><path d="M3 18v-6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v6"/><path d="M3 15h18M4 18v2M20 18v2"/><path d="M7 10V8.5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2V10"/></symbol>' +
  '<symbol id="i-briefcase" viewBox="0 0 24 24"><rect x="3" y="7.5" width="18" height="12" rx="2"/><path d="M8.5 7.5V6a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v1.5"/><path d="M3 13h18"/></symbol>' +
  '<symbol id="i-phone" viewBox="0 0 24 24"><path d="M6.6 3H4.2a1.2 1.2 0 0 0-1.2 1.3C3.4 13 11 20.6 19.7 21a1.2 1.2 0 0 0 1.3-1.2v-2.4a1.2 1.2 0 0 0-1-1.2l-3-.5a1.2 1.2 0 0 0-1.1.5l-1 1.3a13.5 13.5 0 0 1-5.9-5.9l1.3-1a1.2 1.2 0 0 0 .5-1.1l-.5-3a1.2 1.2 0 0 0-1.2-1Z"/></symbol>' +
  '<symbol id="i-mail" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></symbol>' +
  '<symbol id="i-pin" viewBox="0 0 24 24"><path d="M12 21s7-6.2 7-11a7 7 0 0 0-14 0c0 4.8 7 11 7 11Z"/><circle cx="12" cy="10" r="2.5"/></symbol>' +
  '<symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></symbol>' +
  '<symbol id="i-arrow" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></symbol>' +
  '<symbol id="i-chevron" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></symbol>' +
  '<symbol id="i-globe" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.6 2.6 4 5.7 4 9s-1.4 6.4-4 9c-2.6-2.6-4-5.7-4-9s1.4-6.4 4-9Z"/></symbol>' +
  '<symbol id="i-users" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.2"/><path d="M3.5 20a5.5 5.5 0 0 1 11 0"/><path d="M16 5.2a3.2 3.2 0 0 1 0 5.6M17.5 20a5.5 5.5 0 0 0-3-4.9"/></symbol>' +
  '<symbol id="i-cap" viewBox="0 0 24 24"><path d="m2 8 10-4 10 4-10 4L2 8Z"/><path d="M6 10v5c0 1.4 2.7 2.5 6 2.5s6-1.1 6-2.5v-5"/><path d="M22 8v5"/></symbol>' +
  '<symbol id="i-calendar" viewBox="0 0 24 24"><rect x="3" y="4.5" width="18" height="16" rx="2"/><path d="M3 9h18M8 2.5v4M16 2.5v4"/></symbol>' +
  '<symbol id="i-star" viewBox="0 0 24 24"><path d="m12 3 2.7 5.5 6 .9-4.3 4.2 1 6L12 17l-5.4 2.6 1-6L3.3 9.4l6-.9L12 3Z"/></symbol>' +
  '<symbol id="i-quote" viewBox="0 0 24 24"><path d="M9.5 5C6.4 6.2 4.5 9 4.5 12.4V19h6.4v-6.6H7.7c0-2 1.1-3.6 3.1-4.5L9.5 5Zm10 0c-3.1 1.2-5 4-5 7.4V19h6.4v-6.6h-3.2c0-2 1.1-3.6 3.1-4.5L19.5 5Z" fill="currentColor" stroke="none"/></symbol>' +
  '<symbol id="i-play" viewBox="0 0 24 24"><path d="M7 4.5 19 12 7 19.5v-15Z" fill="currentColor" stroke="none"/></symbol>' +
      '<symbol id="i-apple" viewBox="0 0 24 24"><path d="M16.4 12.6c0-2.3 1.9-3.4 2-3.5-1.1-1.6-2.8-1.8-3.4-1.8-1.4-.2-2.8.9-3.5.9s-1.8-.8-3-.8c-1.5 0-2.9.9-3.7 2.3-1.6 2.7-.4 6.8 1.1 9 .8 1.1 1.7 2.3 2.9 2.2 1.2 0 1.6-.7 3-.7s1.8.7 3 .7c1.3 0 2.1-1.1 2.8-2.2.9-1.2 1.3-2.5 1.3-2.5s-2.5-1-2.5-3.6Z" fill="currentColor" stroke="none"/><path d="M14.3 5.6c.6-.8 1-1.9.9-3-.9 0-2 .6-2.7 1.4-.6.7-1.1 1.8-.9 2.9 1 0 2.1-.5 2.7-1.3Z" fill="currentColor" stroke="none"/></symbol>' +
      '<symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-4.2-4.2"/></symbol>' +
  '<symbol id="i-chat" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v10Z"/><path d="M8 9h8M8 13h5"/></symbol>' +
  '<symbol id="i-thumb" viewBox="0 0 24 24"><path d="M7 10v10H4a1 1 0 0 1-1-1v-8a1 1 0 0 1 1-1h3Z"/><path d="M7 10l4-7a2 2 0 0 1 2 2v3h5.5a2 2 0 0 1 2 2.4l-1.4 7A2 2 0 0 1 17 20H7V10Z"/></symbol>' +
  '<symbol id="i-building" viewBox="0 0 24 24"><rect x="4" y="3" width="16" height="18" rx="1.5"/><path d="M8 7h2M14 7h2M8 11h2M14 11h2M8 15h2M14 15h2M10 21v-3h4v3"/></symbol>' +
  '<symbol id="i-img" viewBox="0 0 24 24"><rect x="3" y="4.5" width="18" height="15" rx="2"/><circle cx="8.5" cy="10" r="1.8"/><path d="m4 17 5-4.5 4.5 4 3-2.5L20 18"/></symbol>' +
  '<symbol id="i-trophy" viewBox="0 0 24 24"><path d="M7 4h10v5a5 5 0 0 1-10 0V4Z"/><path d="M7 6H4.5v1.5A3.5 3.5 0 0 0 8 11M17 6h2.5v1.5A3.5 3.5 0 0 1 16 11"/><path d="M12 14v3M9 20h6M10 17h4v3h-4z"/></symbol>' +
  '<symbol id="i-news" viewBox="0 0 24 24"><path d="M4 5h13v14H5.5A1.5 1.5 0 0 1 4 17.5V5Z"/><path d="M17 8h2.5A1.5 1.5 0 0 1 21 9.5v8a1.5 1.5 0 0 1-1.5 1.5H17"/><path d="M7 8h7M7 12h7M7 16h4"/></symbol>' +
  '<symbol id="i-fb" viewBox="0 0 24 24"><path d="M14 8.5V6.8c0-.8.2-1.3 1.4-1.3H17V2.6c-.3 0-1.3-.1-2.4-.1-2.4 0-4 1.5-4 4.1v1.9H8v3h2.6V22h3.2v-8.5h2.6l.4-3H14Z"/></symbol>' +
  '<symbol id="i-ig" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.2" cy="6.8" r="1.2" fill="currentColor" stroke="none"/></symbol>' +
  '<symbol id="i-in" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/><path d="M7 10v7M7 7v.01M11.5 17v-4a2 2 0 0 1 4 0v4M11.5 10v7"/></symbol>' +
  '<symbol id="i-x" viewBox="0 0 24 24"><path d="M4 4l16 16M20 4 4 20"/></symbol>' +
  '<symbol id="i-yt" viewBox="0 0 24 24"><rect x="2.5" y="6" width="19" height="12" rx="4"/><path d="m10 9 5 3-5 3V9Z" fill="currentColor" stroke="none"/></symbol>' +
  '<symbol id="fl-us" viewBox="0 0 24 16"><rect width="24" height="16" fill="#fff"/><g fill="#b22234"><rect width="24" height="1.85"/><rect y="3.7" width="24" height="1.85"/><rect y="7.4" width="24" height="1.85"/><rect y="11.1" width="24" height="1.85"/><rect y="14.15" width="24" height="1.85"/></g><rect width="11" height="8.6" fill="#3c3b6e"/></symbol>' +
  '<symbol id="fl-ca" viewBox="0 0 24 16"><rect width="24" height="16" fill="#fff"/><rect width="6" height="16" fill="#d52b1e"/><rect x="18" width="6" height="16" fill="#d52b1e"/><path d="M12 3.5l.9 2.2 2.3-.5-1.1 2 1.9 1.4-2.3.6.1 2.1-1.8-1.3-1.8 1.3.1-2.1-2.3-.6 1.9-1.4-1.1-2 2.3.5.9-2.2Z" fill="#d52b1e"/></symbol>' +
  '<symbol id="fl-gb" viewBox="0 0 24 16"><rect width="24" height="16" fill="#012169"/><path d="M0 0l24 16M24 0L0 16" stroke="#fff" stroke-width="3.2"/><path d="M0 0l24 16M24 0L0 16" stroke="#c8102e" stroke-width="1.6"/><path d="M12 0v16M0 8h24" stroke="#fff" stroke-width="4.5"/><path d="M12 0v16M0 8h24" stroke="#c8102e" stroke-width="2.4"/></symbol>' +
  '<symbol id="fl-ie" viewBox="0 0 24 16"><rect width="8" height="16" fill="#169b62"/><rect x="8" width="8" height="16" fill="#fff"/><rect x="16" width="8" height="16" fill="#ff883e"/></symbol>' +
  '<symbol id="fl-au" viewBox="0 0 24 16"><rect width="24" height="16" fill="#00247d"/><rect width="12" height="8" fill="#012169"/><path d="M0 0l12 8M12 0L0 8" stroke="#fff" stroke-width="1.4"/><path d="M6 0v8M0 4h12" stroke="#fff" stroke-width="1.8"/><circle cx="18" cy="11" r="1.7" fill="#fff"/><circle cx="6.5" cy="12.5" r="1" fill="#fff"/></symbol>' +
  '<symbol id="fl-nz" viewBox="0 0 24 16"><rect width="24" height="16" fill="#00247d"/><rect width="12" height="8" fill="#012169"/><path d="M0 0l12 8M12 0L0 8" stroke="#fff" stroke-width="1.4"/><path d="M6 0v8M0 4h12" stroke="#fff" stroke-width="1.8"/><circle cx="18" cy="5.5" r="1.1" fill="#cc142b"/><circle cx="20.5" cy="11" r="1.1" fill="#cc142b"/><circle cx="16" cy="11.5" r="1.1" fill="#cc142b"/></symbol>' +
  '<symbol id="fl-eu" viewBox="0 0 24 16"><rect width="24" height="16" fill="#003399"/><g fill="#fc0"><circle cx="12" cy="4" r=".8"/><circle cx="15.4" cy="5" r=".8"/><circle cx="17" cy="8" r=".8"/><circle cx="15.4" cy="11" r=".8"/><circle cx="12" cy="12" r=".8"/><circle cx="8.6" cy="11" r=".8"/><circle cx="7" cy="8" r=".8"/><circle cx="8.6" cy="5" r=".8"/></g></symbol>' +
  '<symbol id="fl-asia" viewBox="0 0 24 16"><rect width="24" height="16" fill="#0ea5b5"/><circle cx="12" cy="8" r="5" fill="none" stroke="#fff" stroke-width="1"/><path d="M7 8h10M12 3v10M8.2 4.8c2.6 1.4 5 1.4 7.6 0M8.2 11.2c2.6-1.4 5-1.4 7.6 0" stroke="#fff" stroke-width=".8" fill="none"/></symbol>' +
  '</defs></svg>';

  function dest(flag, name, href) {
    return '<a href="' + href + '" class="dest"><span class="flag"><svg viewBox="0 0 24 16"><use href="#' + flag + '"/></svg></span>' + name + '</a>';
  }

  var HEADER = '' +
  '<header class="header" id="header">' +
    '<div class="header__inner">' +
      '<a href="index.html" class="brand" aria-label="VFI Overseas Education home">' +
        '<span class="brand__mark"><img src="assets/img/vfi-emblem.png" alt="" /></span>' +
        '<span class="brand__text">VFI<small>overseas education</small></span>' +
      '</a>' +
      '<nav class="nav" id="nav" aria-label="Primary">' +
        '<ul class="nav__list">' +
          '<li class="nav__item has-menu" data-nav="destinations">' +
            '<a href="destinations.html" class="nav__link">Study Destinations <svg class="ic ic--sm nav__caret"><use href="#i-chevron"/></svg></a>' +
            '<div class="megamenu megamenu--full">' +
              '<div class="megamenu__inner dest-menu">' +
                '<div class="dest-grid">' +
                  dest('fl-us', 'USA', 'study-in-usa.html') +
                  dest('fl-ie', 'Ireland', 'study-in-ireland.html') +
                  dest('fl-eu', 'Europe', 'europe.html') +
                  dest('fl-ca', 'Canada', 'study-in-canada.html') +
                  dest('fl-au', 'Australia', 'study-in-australia.html') +
                  dest('fl-asia', 'Asia', 'asia.html') +
                  dest('fl-gb', 'United Kingdom', 'study-in-uk.html') +
                  dest('fl-nz', 'New Zealand', 'study-in-new-zealand.html') +
                  '<a href="universities.html" class="dest dest--btn">Search Universities <svg class="ic ic--sm"><use href="#i-arrow"/></svg></a>' +
                '</div>' +
              '</div>' +
            '</div>' +
          '</li>' +
          '<li class="nav__item has-menu" data-nav="services">' +
            '<a href="services.html" class="nav__link">Services <svg class="ic ic--sm nav__caret"><use href="#i-chevron"/></svg></a>' +
            '<div class="megamenu megamenu--full">' +
              '<div class="megamenu__inner svc-menu">' +
                '<div class="svc-menu__main">' +
                  '<div class="svc-menu__title"><span class="svc-menu__hic"><svg class="ic"><use href="#i-users"/></svg></span> For Students</div>' +
                  '<div class="svc-menu__cols">' +
                    '<ul>' +
                      '<li><a href="services.html#counselling">Counselling</a></li>' +
                      '<li><a href="test-preparation.html">Test Preparation</a></li>' +
                      '<li><a href="test-preparation.html">GMAT Coaching</a></li>' +
                    '</ul>' +
                    '<ul>' +
                      '<li><a href="services.html#selection">Course, Country &amp; University Selection</a></li>' +
                      '<li><a href="services.html#applications">Applications &amp; Admission</a></li>' +
                      '<li><a href="scholarships.html">Scholarships</a></li>' +
                    '</ul>' +
                    '<ul>' +
                      '<li><a href="internships.html">Internship</a></li>' +
                      '<li><a href="services.html#loan">Education Loan</a></li>' +
                      '<li><a href="services.html#visa">Visa Processing</a></li><li><a href="allied-services.html">Allied Services</a></li>' +
                    '</ul>' +
                  '</div>' +
                '</div>' +
                '<div class="svc-menu__side">' +
                  '<a href="for-institutions.html"><span class="svc-menu__ic"><svg class="ic"><use href="#i-building"/></svg></span> For Institutions</a>' +
                  '<a href="for-partners.html"><span class="svc-menu__ic"><svg class="ic"><use href="#i-users"/></svg></span> For Partners</a>' +
                  '<a href="for-franchisee.html"><span class="svc-menu__ic"><svg class="ic"><use href="#i-globe"/></svg></span> For Franchisee</a>' +
                '</div>' +
              '</div>' +
            '</div>' +
          '</li>' +
          '<li class="nav__item" data-nav="events"><a href="events.html" class="nav__link">Upcoming Events</a></li>' +
          '<li class="nav__item" data-nav="contact"><a href="contact.html" class="nav__link">Contact Us</a></li>' +
          '<li class="nav__item has-menu" data-nav="company">' +
            '<a href="about.html" class="nav__link">Company <svg class="ic ic--sm nav__caret"><use href="#i-chevron"/></svg></a>' +
            '<div class="megamenu megamenu--full">' +
              '<div class="megamenu__inner company-menu">' +
                '<a href="about.html"><svg class="ic"><use href="#i-star"/></svg> About Us</a>' +
                '<a href="blogs.html"><svg class="ic"><use href="#i-trophy"/></svg> Blog</a>' +
                '<a href="gallery.html"><svg class="ic"><use href="#i-img"/></svg> Gallery</a>' +
                '<a href="csr.html"><svg class="ic"><use href="#i-shield"/></svg> Social Responsibility</a>' +
                '<a href="news.html"><svg class="ic"><use href="#i-news"/></svg> News &amp; Press</a>' +
                '<a href="careers.html"><svg class="ic"><use href="#i-award"/></svg> Careers <span class="badge-hiring">WE\'RE HIRING!</span></a>' +
              '</div>' +
            '</div>' +
          '</li>' +
        '</ul>' +
        '<div class="nav__utils">' +
          '<a href="vfi-partner.html" class="pill pill--outline">VFI Partner<small>( For Partners )</small></a>' +
          '<a href="login.html" class="pill pill--orange">Student Login</a>' +
          '<a href="contact.html#contact" class="pill pill--white"><svg class="ic ic--sm pill__play"><use href="#i-play"/></svg> Book Online Counselling</a>' +
        '</div>' +
      '</nav>' +
      '<button class="hamburger" id="hamburger" aria-label="Open menu" aria-expanded="false" aria-controls="nav"><span></span><span></span><span></span></button>' +
    '</div>' +
  '</header>' +
  '<div class="nav-scrim" id="navScrim" hidden></div>';

  function fcol(title, links) {
    var lis = links.map(function (l) { return '<li><a href="' + l[1] + '">' + l[0] + '</a></li>'; }).join('');
    return '<div class="footer__col"><h3>' + title + '</h3><ul>' + lis + '</ul></div>';
  }

  var FOOTER = '' +
  '<footer class="footer" id="footer">' +
    '<svg class="footer__wave" viewBox="0 0 1440 70" preserveAspectRatio="none" aria-hidden="true"><path d="M0,70 Q720,-8 1440,70 Z"/></svg>' +
    '<div class="container nl">' +
      '<h2 class="nl__title">Stay updated with VFI</h2>' +
      '<form class="nl__form" id="nform">' +
        '<input type="email" name="nemail" placeholder="Email ID" aria-label="Email address" required />' +
        '<div class="nl__select"><select aria-label="I am interested in">' +
          '<option value="">I\'m Interested in</option><option>Student</option><option>Institute</option><option>Partner</option><option>Franchisee</option>' +
        '</select><svg class="ic ic--sm nl__chev"><use href="#i-chevron"/></svg></div>' +
        '<button type="submit" class="btn btn--enquire">Subscribe Now</button>' +
      '</form>' +
    '</div>' +
    '<div class="container footer__grid">' +
      '<div class="footer__brand">' +
        '<a href="index.html" class="brand brand--light"><span class="brand__mark"><img src="assets/img/vfi-emblem.png" alt="" /></span><span class="brand__text">VFI<small>overseas education</small></span></a>' +
        '<p>One of the fastest-growing study-abroad consultancies — helping students reach world-class universities with honest, end-to-end guidance.</p>' +
        '<div class="socials">' +
          '<a href="#" aria-label="Facebook"><svg class="ic"><use href="#i-fb"/></svg></a>' +
          '<a href="#" aria-label="Instagram"><svg class="ic"><use href="#i-ig"/></svg></a>' +
          '<a href="#" aria-label="LinkedIn"><svg class="ic"><use href="#i-in"/></svg></a>' +
          '<a href="#" aria-label="X"><svg class="ic"><use href="#i-x"/></svg></a>' +
          '<a href="#" aria-label="YouTube"><svg class="ic"><use href="#i-yt"/></svg></a>' +
        '</div>' +
      '</div>' +
      fcol('Company', [['About Us','about.html'],['Careers <span class="badge-hiring">WE\'RE HIRING!</span>','careers.html'],['News &amp; Press','news.html'],['Corporate Social Responsibility','csr.html'],['Blog','blogs.html'],['Gallery','gallery.html'],['Contact Us','contact.html'],['Search Universities','universities.html'],['Upcoming Events <span class="badge-new">NEW!</span>','events.html']]) +
      fcol('Services for Students', [['Counselling','services.html#counselling'],['Test Preparation','test-preparation.html'],['Course, Country &amp; University Selection','services.html#selection'],['Applications &amp; Admission','services.html#applications'],['Scholarships','scholarships.html'],['Internship','internships.html'],['Education Loan','services.html#loan'],['Visa Processing','services.html#visa'],['Allied Services','allied-services.html']]) +
      fcol('Study Destinations', [['United States','study-in-usa.html'],['Canada','study-in-canada.html'],['United Kingdom','study-in-uk.html'],['Ireland','study-in-ireland.html'],['Australia','study-in-australia.html'],['New Zealand','study-in-new-zealand.html'],['Europe','europe.html'],['Asia','asia.html']]) +
      '<div class="footer__col"><h3>Reach us</h3><ul class="footer__contact">' +
        '<li><svg class="ic ic--sm"><use href="#i-pin"/></svg> Gulshan 1, Dhaka 1212, Bangladesh</li>' +
        '<li><svg class="ic ic--sm"><use href="#i-phone"/></svg> <a href="tel:+8801700000000">+880 1700-000000</a></li>' +
        '<li><svg class="ic ic--sm"><use href="#i-mail"/></svg> <a href="mailto:dhaka@vfi-edu.com">dhaka@vfi-edu.com</a></li>' +
      '</ul>' +
      '<h3 style="margin-top:22px">Login to</h3><ul>' +
        '<li><a href="#">CourseFinder</a></li><li><a href="#">EduLoans</a></li><li><a href="#">tryaro</a></li>' +
      '</ul></div>' +
    '</div>' +
    '<div class="container recog">' +
      '<h3 class="recog__title">Industry Recognitions</h3>' +
      '<div class="recog__row">' +
        '<span class="recog__item"><svg class="ic"><use href="#i-shield"/></svg> ICEF</span>' +
        '<span class="recog__item"><svg class="ic"><use href="#i-globe"/></svg> PIER</span>' +
        '<span class="recog__item"><svg class="ic"><use href="#i-cap"/></svg> AAERI</span>' +
        '<span class="recog__item"><svg class="ic"><use href="#i-award"/></svg> Education UK</span>' +
        '<span class="recog__item"><svg class="ic"><use href="#i-trophy"/></svg> British Council</span>' +
      '</div>' +
    '</div>' +
    '<div class="footer__bar"><div class="container footer__bar-inner">' +
      '<p>© <span id="year">2026</span> VFI Overseas Education. All rights reserved.</p>' +
      '<nav class="footer__legal" aria-label="Legal"><a href="terms.html">Terms &amp; Conditions</a><a href="privacy.html">Privacy Policy</a><a href="payment-terms.html">Payment Terms</a><a href="admin.html">Admin</a></nav>' +
    '</div></div>' +
  '</footer>' +
  '<button class="totop" id="toTop" aria-label="Back to top"><svg class="ic"><use href="#i-chevron"/></svg></button>';

  // ---- inject ----
  document.body.insertAdjacentHTML("afterbegin", SPRITE);
  var h = document.getElementById("site-header");
  if (h) h.outerHTML = HEADER;
  var f = document.getElementById("site-footer");
  if (f) f.outerHTML = FOOTER;

  // solid header immediately on light pages (overlay pages start transparent)
  if (document.body.getAttribute("data-header") !== "overlay") {
    var hdr = document.getElementById("header");
    if (hdr) hdr.classList.add("solid");
  }

  // hide links to pages that are switched off in the admin panel
  try {
    if (window.VFI && VFI.pageEnabled) {
      Array.prototype.slice.call(document.querySelectorAll("#header a[href], #footer a[href]")).forEach(function (a) {
        var f = VFI.baseName(a.getAttribute("href"));
        if (!f || f === "index.html" || VFI.pageEnabled(f)) return;
        var li = a.closest("li");
        (li || a).remove();
      });
    }
  } catch (e) { /* never block the page */ }

  // active nav highlight
  var page = document.body.getAttribute("data-page");
  if (page) {
    var item = document.querySelector('.nav__item[data-nav="' + page + '"]');
    if (item) item.classList.add("is-active");
  }
  var y = document.getElementById("year");
  if (y) y.textContent = new Date().getFullYear();
})();
