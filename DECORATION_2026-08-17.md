# VFI — Decoration Controls

**Date:** 2026-08-17

Controls that are wired to nothing, only show a toast, or are static markup that
never updates from the server. Each needs a decision: **wire it** or **remove it**
so it stops implying a feature exists.


## Partner Console (30)

| Page | Control | Evidence |
|---|---|---|
| All (shell) | Enquiry-type radios (in VFI / not in VFI) | js/portal.js:478 always appends enquiry_type=new |
| All (shell) | "Request Programs via WhatsApp" | js/portal.js:211 no id, no listener anywhere |
| All (shell) | Allied-services nav group expander | js/portal.js:83-92 dead: NAV (71-78) has no group entry |
| Dashboard | Date-range pill + clear ✕ | partner-dashboard.html:79 hardcoded dates, no handler |
| Dashboard | Intake / Year / Countries selects | dashboard:80-82 lack data-pp-filter read at dashboard:286 |
| Dashboard | Apply Filters #ppApplyFilters | dashboard:306 -> refetches kpis with always-empty query |
| Dashboard | KPI card carets (pp-stat__caret) | no "pp-stat" handler in js/portal*.js |
| Dashboard | Deadline panel contents | dashboard:189-192 hardcoded "No upcoming deadlines" |
| Dashboard | Assistant input + send #ppAskSend | dashboard:309 toast only; no assistant route in backend/routes |
| Dashboard | "100 messages left" / "100/100" / "20/20" | dashboard:103,175-176 hardcoded literals |
| Dashboard | Benefits tier bar, 16% fill, dots, "0 unique" | dashboard:162-165 hardcoded; only tierName/benefits from store |
| Dashboard | "View roadmap" link | dashboard:173 -> href="partner-dashboard.html" (itself) |
| Dashboard | Update country chips All/US/AUS/CAN/UK | dashboard:312 toggles is-on only, no refetch |
| Dashboard | Floating assistant FAB | dashboard:235; no "pp-fab" handler in any js/ file |
| Students | Country / Intake / Year / Status selects | students:42-57 no ids; backend supports them (PartnerStudentController:85-92) |
| Students | Date-range clear #ppStuDates | students:105 hides the pill + toast; no from/to sent |
| Enquiries | Document chips | portal-data.js:292 plain <span>; download route (web.php:190) uncalled |
| Search | Student's State select | partner-search.html:185 hardcoded; buildQuery (portal-search.js:111) omits it |
| Search | Discipline Area select | filled at portal-search.js:78 but never added in buildQuery:111-127 |
| Resources | Hardcoded demo docs + "Download" | partner-resources.html:258 toast "Download would start." |
| Wallet | Filter fields + Search #pgWalletSearch | wallet:146 toast only (inside the disabled preview) |
| Allied | "Submit a loan case" #pgAlliedLoan | partner-allied.html:197 -> VFIToast only |
| Allied | "Browse properties" #pgAlliedAcc | partner-allied.html:198 -> VFIToast only |
| Allied | "Explore test prep" #pgAlliedPrep | partner-allied.html:199 -> VFIToast only |
| Interview | Country / Type / Difficulty selects | interview:71-97 ids never read by any script |
| Interview | "Start Mock Interview" #pgIntStart | partner-interview.html:190 -> VFIToast only |
| Interview | "10 / 10 interviews left" badge | interview:56 hardcoded |
| Interview | "Your past interviews" panel | interview:160-174 static empty state, no fetch |
| Email Updates | "View" buttons [data-mail-view] | email-updates:135 -> toast "Opening the update…" |
| Email Updates | Sr.No / Subject / Date sort carets | email-updates:53-55 icons with no handler |

## Staff Panel (/manage) (3)

| Page | Control | Evidence |
|---|---|---|
| Dashboard | AccountWidget + FilamentInfoWidget | AdminPanelProvider.php:46-49; no app/Filament/Widgets dir |
| Student lookup | "Open record" result | StudentLookupTable.php:62 flashes `lookup.student`; nothing reads it |
| Blogs / News / Events / Photos | `img_id` TextInput | BlogForm.php:23 — free-text id, no picker, no validation |

## Public Site & Student Pages (26)

| Page | Control | Evidence |
|---|---|---|
| All pages (footer) | "Subscribe Now" newsletter form `#nform` | main.js:472-483 fakes "Subscribed ✓"; no route exists |
| All pages (footer) | Social icons (FB/IG/LI/X/YT) | site.js:175-179 `href="#"`; SEED settings all `"#"` (store.js:34) |
| All pages (footer) | "Login to: CourseFinder / EduLoans / tryaro" | site.js:191 three `href="#"` |
| index.html | 3× "See More" (Partners/Franchisee/Institutions) | index.html:131,152,173 `href="#"`; main.js:90 preventDefaults it |
| index.html | "CourseFinder" link | index.html:258 `href="#"` |
| index.html | "EduLoans" link | index.html:288 `href="#"` |
| index.html | "Apply Now" (We're Hiring card) | index.html:332 `href="#"` (careers.html exists, not linked) |
| index.html | University list (NUS, Berkeley…) | index.html:311-318 hardcoded `.unigrid__cell`, no data-render |
| index.html | Press logos (PIE News, PRLOG…) | index.html:382-384 static spans |
| contact.html | "See all events" link | contact.html:222 `href="#"` (events.html exists) |
| contact.html | "All blogs" link | contact.html:249 `href="#"` (blogs.html exists) |
| contact.html | Featured events cards | contact.html:224-237 hardcoded, no `data-render` |
| contact.html | Latest blogs cards | contact.html:251-264 hardcoded, no `data-render`, no links |
| contact.html | Address / phone / email block | contact.html:277-284 static; no `data-set`, render.js:327 skips it |
| universities.html | Stats "1200+ / 100,000+ / 730,000+" | universities.html:60-63 hardcoded `data-count` |
| events.html | Filter chips (All/Webinar/Fair/Coaching) | events.html:33-37; no handler in js/ (only css/style.css:742) |
| news.html | Press-release list + "Read the story" | news.html:38 -> contact.html; no `data-render`, list is static |
| gallery.html | Photo click (no lightbox) | gallery.html:32; `<figure>` only, no handler |
| test-preparation.html | 8× "View More" | test-preparation.html:78-89 `href="#"` |
| about.html | 3× "Know more" (Partners/Franchisees/Institutions) | about.html:70-72 `href="#"` though for-*.html exist |
| study-in-usa.html | 6× reel tiles | study-in-usa.html:384-389 `href="#"` |
| study-in-uk/ca/ie/au/nz | 4× reel tiles each | study-in-uk.html:377-380 `href="#"` (same in other 4) |
| study-in-usa.html | "Get your free USA study guide" | study-in-usa.html:310 -> contact.html; no file is served |
| login.html | `#saDone` overlay ("this demo has no backend") | login.html:329 dead markup; showDone (auth.js:423) never called here |
| student-profile.html | "Save checklist" / "Save visa documents" | student-portal.js:815-820 short-circuits to a toast, no request |
| vfi-partner.html | App Store / Google Play buttons | vfi-partner.html:59,63 `href="#"` |

## Content Admin (admin.html) (35)

| Page | Control | Evidence |
|---|---|---|
| Sidebar | Counter badges (data-count) | admin.js:196-202 VFI.list() -> store.js SEED/localStorage |
| Header | "New <item>" top action | admin.js:150-156 openForm(); save path is local (see below) |
| Dashboard | 4 stat tiles | admin.html:129-132 filled by admin.js:200 from localStorage |
| Dashboard | 4 quick-action buttons | admin.js:186-192 -> openForm -> VFI.put (localStorage) |
| Dashboard | "How this works" list | admin.html:146-151 static; says "No login" — false since the gate |
| Events/Blogs/News | Row "Edit" | admin.js:245 openForm(); reads store.js cache, not the API |
| Events/Blogs/News | Row "Delete" | admin.js:246-252 VFI.remove -> store.js:341 save() -> localStorage |
| Modal | "Save" submit | admin.js:404 VFI.put -> store.js:330,311 localStorage.setItem |
| Modal | "Choose image" / "Remove" | admin.js:372 VFI.uploadImage -> IndexedDB; web.php:66 /media uncalled |
| Photo Gallery | Dropzone + file input | admin.js:413-437 addPhotos -> VFI.put; no POST /api/admin/media |
| Photo Gallery | Photo edit / delete | admin.js:286-290 local only |
| Home Page Images | 8 slot "Upload" | admin.js:491 VFI.setMedia -> store.js:427 localStorage |
| Home Page Images | 8 slot "Remove" | admin.js:483 VFI.setMedia(key,null); web.php:67 media/slot uncalled |
| Country Pages | "Save text" | admin.js:601 VFI.saveCountry -> store.js:357 localStorage |
| Country Pages | 14 image slots up/remove | admin.js:712-728 VFI.setMedia (localStorage/IndexedDB) |
| Country Pages | 4x "Save <list>" | admin.js:624 VFI.saveCountry; web.php:59 singleton/countries uncalled |
| Region Pages | "Save hero text" | admin.js:800 VFI.saveRegion -> store.js:419 localStorage |
| Region Pages | "Add"/"Save country blocks" | admin.js:806-813; per-row image picker also local (admin.js:660-666) |
| Services Page | "Save hero text" | admin.js:839 VFI.saveServicesPage -> store.js:368 localStorage |
| Services Page | "Add"/"Save service blocks" | admin.js:846-853; singleton/servicesPage endpoint never called |
| VFI Partner Page | "Save text" | admin.js:954 VFI.savePartnerPage -> store.js:379 localStorage |
| VFI Partner Page | 2 image slots up/remove | admin.js:1003-1017 VFI.setMedia |
| VFI Partner Page | 5x add / 5x "Save <list>" | admin.js:965-975 VFI.savePartnerPage |
| Pages On/Off | ~40 toggles | admin.js:1083 VFI.setPage -> store.js:401 localStorage |
| Site Settings | "Save settings" | admin.js:1100 VFI.saveSettings -> store.js:437 localStorage |
| Backup | "Download backup" | admin.js:1120 VFI.exportAll -> store.js:570 reads localStorage only |
| Backup | "Choose backup file" | admin.js:1141 VFI.importAll -> store.js:576 writes localStorage |
| Backup | "Reset to demo content" | admin.js:1151 VFI.reset -> store.js:444 cache = SEED |
| Console · Managers | New / Edit / Delete | admin.js:404/246 VFI.put/remove; DB copy = Filament PpManagerResource |
| Console · Updates | New / Edit / Delete | same path; DB copy = Content/PpUpdates/PpUpdateResource.php |
| Console · Quick Links | New / Edit / Delete | same path; DB copy = Content/PpQuicklinks/PpQuicklinkResource.php |
| Console · Learning Docs | New / Edit / Delete | same path; DB copy = Content/PpDocs/PpDocResource.php |
| Console · Email Updates | New / Edit / Delete | same path; DB copy = Content/PpEmails/PpEmailResource.php |
| Console · Notifications | New / Edit / Delete | same path; DB copy = Content/PpNotifs/PpNotifResource.php |
| Console · Console Text | "Save console text" | admin.js:1114 VFI.savePartnerPortal -> store.js:390 localStorage |

---

**Total: 94 decoration controls.**
