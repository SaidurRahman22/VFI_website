# VFI Overseas Education — Website Theme

A self-contained, ultra-smooth **frontend** theme for a study-abroad consultancy, built to match the layout, colour system, typography and interaction feel of a modern EdTech contact page. No backend, no build step — just open `index.html`.

## Preview

```
d:/project/VFI_website/
├── index.html          # Home  (blue hero, core strengths, 4 offering bands, multi-country,
│                       #        featured events, tech platforms, uni tie-ups, updates, blogs, news)
├── destinations.html   # Study Destinations hub (flag cards)
├── study-in-usa.html        # Destination pages — ~20 sections each, with a collapsible
├── study-in-canada.html     #   contents sidebar, tabbed sections and FAQ accordion.
├── study-in-ireland.html    #   All six share one design system and are editable
├── study-in-australia.html  #   from the admin panel's "Country Pages" section.
├── study-in-uk.html
├── study-in-new-zealand.html
├── services.html       # Services (9 service cards + 7-step + FAQ)
├── events.html         # Upcoming Events (filter + event grid)
├── blogs.html          # Blog (featured + grid)
├── blog-post.html      # Blog article — reads ?id=, renders the full post body
├── about.html          # Company / About (vision, story, stats, team, offices)
├── contact.html        # Dhaka branch (purple hero + enquiry form)
├── gallery.html        # Photo gallery (filled from the admin panel)
├── login.html          # Student sign in / create account  (see "The sign-in screens")
├── student-forgot.html      # Student — reset-password request (link flow)
├── student-verify.html      # Student — email verification (6-digit code)
├── student-profile.html     # Student portal — profile + document checklist
├── student-tracking.html    # Student portal — application progress tracker
├── vfi-partner-login.html   # Partner sign in / agency registration wizard
├── vfi-partner-forgot.html  # Partner — reset-password request (code flow)
├── vfi-partner-verify.html  # Partner — email verification (6-digit code)
├── vfi-partner.html    # VFI Partner — the B2B recruitment-platform landing page
│                       #   (navy hero + layered waves, 7 numbered feature blocks,
│                       #    3 steps, testimonial carousel, job board, FAQ)
├── csr.html            # Corporate Social Responsibility
├── for-institutions.html    # B2B — universities & colleges recruiting students
├── for-partners.html        # B2B — agencies and sub-agents
├── for-franchisee.html      # B2B — entrepreneurs opening a VFI office
├── terms.html          # Legal — Terms & Conditions
├── privacy.html        # Legal — Privacy Policy
├── payment-terms.html  # Legal — Payment Terms
├── admin.html          # ADMIN PANEL — manage events, blogs, news, photos, settings
├── css/
│   ├── style.css       # Design system + every component + responsive rules
│   ├── student-auth.css     # Student sign-in / reset / verify screens (sa- prefix)
│   ├── partner-auth.css     # Partner sign-in / reset / verify screens (pa- prefix)
│   ├── student-portal.css   # Student profile + tracking pages (sp- prefix)
│   └── admin.css       # Admin panel styles
├── js/
│   ├── store.js        # Content store: localStorage (JSON) + IndexedDB (images)
│   ├── site.js         # Shared chrome: SVG sprite + header (mega-menus) + footer, injected on every page
│   ├── main.js         # Header state, mobile drawer, mega-menus, reveal, counters, accordion, carousel, forms
│   ├── render.js       # Renders public pages from the store (incl. the blog article)
│   ├── auth.js         # Student auth: login + student-forgot + student-verify
│   ├── partner-auth.js # Partner auth: vfi-partner-login + -forgot + -verify
│   ├── student-portal.js    # Student portal: profile + application tracking
│   └── admin.js        # Admin panel logic
└── README.md
```

## Admin panel

Open **`admin.html`** (no login — there's also a small "Admin" link in the footer). You can manage:

| Section | What it controls |
|---|---|
| **Events** | The events grid on `events.html` and the Featured Events block on the home page |
| **Blogs** | The blog grid on `blogs.html` and the two blog cards on the home page. Each post has a **Body / article content** field (plain text: `## ` → heading, `- ` → list item, `> ` → pull quote, blank line → paragraph) plus optional author and read-time, all shown on `blog-post.html`. |
| **News & Updates** | The "Latest VFI Updates" cards on the home page |
| **Photo Gallery** | `gallery.html` — drag & drop several photos at once |
| **Home Page Images** | The fixed picture slots on the home page: the hero visual, the four round photos in the coloured "Services & Offerings" bands (For Students / Partners / Franchisees / Universities), and the three "Multi Country Advantage" cards. Leave a slot empty to keep the illustrated placeholder. |
| **Country Pages** | Pick a country (USA, Canada, Ireland, Australia, UK, New Zealand) and edit that destination page: the hero heading and sub-heading, the overview paragraph, **14 photo slots** (hero background, 4 university logos, 4 city photos, 4 reels, the "Why choose VFI" image), and four editable lists — universities, scholarships, the career salary table and FAQs. Leave anything blank and the page keeps its built-in content. |
| **Services Page** | The Student Services page (services.html) — hero text plus the nine service blocks (Counselling, Test Preparation, Selection, Applications & Admission, Scholarships, Internships, Education Loan, Visa Processing, Allied Services). Each block has a name, anchor id, description, offerings list, a second CTA and a photo. |
| **VFI Partner Page** | The `vfi-partner.html` platform page — hero text and buttons, the app block, the seven feature blocks (each with a title, description and screenshot), the CTA, the three steps, testimonials, job openings and FAQs. Two image slots for the hero and app visuals. |
| **Pages On/Off** | Show or hide any of the **40 pages**, grouped as Main pages, Student account, Services, Study destinations, Company, Partners & institutions, and Legal. A hidden page disappears from the menus and footer, and its address shows a short “not available” notice. Home is always on; the blog-article template and the auth reset/verify sub-flows are listed but locked "Always on" (switching them off would break a flow). |
| **Site Settings** | Brand name, tagline, footer about text, phone, email, address, office hours, social links (applied site-wide) |
| **Backup** | Export everything (content + images) to one JSON file, import it back, or reset to the demo content |

### Recommended image sizes

The admin panel shows these on each upload slot, so you don't have to remember them. Images are cropped to fill (centred), so matching the shape matters more than the exact pixels — anything larger is resized down automatically.

| Where | Size | Shape |
|---|---|---|
| Hero visual (blue banner) | 900 × 900 px | square 1:1 |
| For Students / Partners / Franchisees / Universities circles | 800 × 800 px | square 1:1 |
| Multi Country — left card | 600 × 520 px | portrait 7:6 |
| Multi Country — top card | 600 × 460 px | landscape 4:3 |
| Multi Country — bottom card | 600 × 440 px | landscape 4:3 |
| Event cover image | 1200 × 600 px | landscape 2:1 |
| Blog cover image | 1200 × 600 px | landscape 2:1 |
| News item image | 1200 × 500 px | wide 12:5 |
| Gallery photo | 1200 × 900 px | 4:3 |

**Important:** open the site through a local server, not by double-clicking the files — browsers block storage on `file://`:

```bash
python -m http.server 8000     # then visit http://localhost:8000/admin.html
```

**How it stores data:** content is saved as JSON in `localStorage`, and uploaded images go into `IndexedDB` (with a `localStorage` fallback). Photos are automatically resized (max ~1400–1600px, JPEG) before saving, so a 900 KB upload lands at roughly 200 KB.

**Worth knowing:** because there's no backend, content lives in the browser you edit it in — it isn't shared between devices or visitors. Use **Backup → Download backup** to move content to another machine. When you're ready for a real backend, the JSON shape in `js/store.js` maps straight onto a database.

The newsletter band ("Stay updated with VFI") and the Industry Recognitions row are part of the shared footer, so they appear on every page automatically.

**Multi-page architecture:** the header and footer live once in `js/site.js` and are injected into every page via `<div id="site-header">` / `<div id="site-footer">` placeholders. Edit the nav or footer in one place and it updates everywhere. Each page sets `data-page` (active-nav highlight) and, for pages with a purple hero, `data-header="overlay"` (transparent header over the hero → solid white on scroll). Light pages get a solid white header from the top.

Open it directly:

- Double-click `index.html`, **or**
- Serve it (recommended, so fonts/anchors behave perfectly):
  ```bash
  # from the project folder
  python -m http.server 8000
  # then visit http://localhost:8000
  ```

## Scroll animations

Sections animate in as they enter the viewport, driven by one `IntersectionObserver` in [js/main.js](js/main.js).

**Nothing needs to be added to the HTML.** On load, `autoTag()` walks the page and assigns an animation to known components — section heads, card grids, feature blocks, hero visuals, accordions — so all 31 public pages are covered, including any page added later that uses the same classes.

| `data-anim` | Effect | Typically used for |
|---|---|---|
| `up` | Rise + fade | Default for most blocks |
| `left` / `right` | Slide in from the side | Alternating feature rows, split sections |
| `pop` | Scale up with a slight overshoot | Cards in a grid |
| `scale` | Gentle zoom in | Panels, CTAs |
| `blur` | Focus-in from blurred | Hero visuals |
| `clip` | Wipe upward | Gallery photos |
| `fade` | Opacity only | Wrappers whose children carry the motion |

Cards inside a grid stagger by 85 ms each (capped at 8), so a row resolves left-to-right rather than all at once. To override any element, set `data-anim="..."` on it yourself — `autoTag` never touches an element that already has the attribute.

Also included: a **scroll progress bar** across the top, a **subtle parallax** on hero artwork, and a **hover lift** on cards (using the independent `translate` property, so it can't collide with the reveal transform).

**Everything is disabled under `prefers-reduced-motion: reduce`** — no transforms, no transitions, no progress bar. Without JavaScript the `.js` class is never added and all content renders plainly.

Two things worth knowing if you change this code:

- The safety net only reveals elements **currently in the viewport**. An earlier version revealed *everything* after 1200 ms, which meant that by the time you scrolled down, every section was already visible and nothing ever animated.
- `render.js` calls `window.VFIInitReveal()` after rebuilding content from the admin store, so freshly rendered cards get observed. Without it they would sit at opacity 0 permanently.

## VFI Partner (`vfi-partner.html`)

The B2B platform page, reached from the **VFI Partner** pill in the header. Built from the reference design: a navy hero with layered blue waves, an app-download block, seven numbered feature blocks that alternate side and background tint over a large circle, a navy CTA card with a blue offset layer, three steps, a testimonial carousel, a filterable job board and an FAQ.

The screenshots are **abstract CSS mockups**, not real product images — deliberately, so the page ships without placeholder stock art. Upload real screenshots in **Admin → VFI Partner Page** and each mockup is replaced by the image; leave a slot empty and the mockup stays.

The job board filters by department tab and by the location dropdown, and shows a short message when a combination matches nothing.

## The sign-in screens

There are two auth systems, for two different audiences. They are deliberately built to look like **separate products**, on separate palettes, with separate stylesheets and scripts — nothing is shared between them except `store.js`. Each is a set of three pages (sign-in, reset-password, email-verification) driven by one script.

| | Student | Partner |
|---|---|---|
| Sign in / register | [login.html](login.html) | [vfi-partner-login.html](vfi-partner-login.html) |
| Reset password | [student-forgot.html](student-forgot.html) (emails a **link**) | [vfi-partner-forgot.html](vfi-partner-forgot.html) (emails a **code**) |
| Email verification | [student-verify.html](student-verify.html) | [vfi-partner-verify.html](vfi-partner-verify.html) |
| Reached from | **Student Login** pill in the header | **Login to VFI Partner** / **Register with us** on [vfi-partner.html](vfi-partner.html) |
| Stylesheet | `css/student-auth.css` | `css/partner-auth.css` |
| Script | `js/auth.js` | `js/partner-auth.js` |
| Class prefix | `sa-` | `pa-` |
| Register flow | Single form | **3-step wizard** with a progress bar |

All six skip the shared header and footer — a sign-in screen shouldn't carry a mega-menu — so each inlines its own icon sprite and carries its own copy of the "page switched off" notice. All are listed in **Pages On/Off** (the reset/verify sub-flows locked "Always on").

The `sa-` / `pa-` prefixes matter: they mean none of these pages can be reached by any rule in `css/style.css`, so the two designs can diverge freely without one bleeding into the other.

Shared behaviour: inline validation with the first bad field focused, show/hide password, a Caps-Lock warning, an animated password-strength meter, a busy state on submit, an animated SVG success mark, keyboard-navigable tabs (arrow keys), and full `prefers-reduced-motion` support. **Registration** puts a **country-code dropdown** just before the phone field (`+880` default), and **per-field character rules** keep inputs clean — the phone accepts digits only, names reject digits/symbols — enforced on typing, paste and autofill (the phone is `type="tel"`, never `type="number"`, so a leading `0` survives). The **six-digit verification** boxes auto-advance, reject non-digits, accept a pasted code across all boxes, and show the target address masked.

`vfi-partner-login.html#register` opens the registration wizard directly — that's what the **Register with us** button on the partner page uses.

After a successful student sign-in the success panel offers **Go to your portal** → [student-profile.html](student-profile.html); a new sign-up can confirm its email first. The two portal pages (profile + application tracking, `sp-` prefix, `js/student-portal.js`) *do* carry the shared header and footer.

**None of them signs anybody in.** There is no account server. Nothing typed is sent, stored or checked, and every page says so. To connect a real backend, search for `REAL REQUEST` in `js/auth.js` / `js/partner-auth.js` — those blocks (sign-in, register, send/resend reset, resend/check code) are the only things that need to change; the busy state, error surface and success panel around them already work. Remove the demo notice from the HTML at the same time.

## Design system

Everything is driven by CSS custom properties at the top of `css/style.css` — change these to re-skin the whole site:

| Token | Value | Role |
|---|---|---|
| `--hero` | `#6c5ce7` | Purple hero background |
| `--navy` | `#0e1b2c` | Dark sections, footer, headings |
| `--blue` | `#226cf5` | Primary brand blue |
| `--coral` | `#ff6a56` | Coral accent |
| `--enquire-a/-b` | `#ffa14a → #ff6a56` | Orange "Enquire Now" CTA gradient |
| `--gold` | `#f5a623` | Secondary accent |
| `--violet` | `#6c48f0` | Tertiary accent |
| `--muted` | `#64647a` | Body / secondary text |
| tints | `--tint-blue/peach/lavender/coral/mint` | Soft section & icon backgrounds |

**Fonts:** [Quicksand](https://fonts.google.com/specimen/Quicksand) (display/headings) + [DM Sans](https://fonts.google.com/specimen/DM+Sans) (body), loaded from Google Fonts.

## Sections (top → bottom)

1. Transparent header (white over the hero) → solid white on scroll, with mega-menus, the `coursefinder.ai` / `Student Login` / `Book Online Counselling` pills, and a mobile drawer
2. Purple full-bleed hero with a curved bottom wave, breadcrumb, and two CTAs — "Enquire Now" and "Branch Address", both of which scroll down to the contact section
3. "Services we provide" — 2-column minimal icon + title cards (10 services)
4. "Enquire Now" CTA band
5. Dark "Realize your global ambitions" value-props band
6. Test-preparation feature with mock UI card
7. "The VFI edge" animated stat counters
8. 7-step journey accordion (single-open, smooth height)
9. "Our students love us" testimonials carousel (prev/next)
10. Featured events
11. Blog previews
12. Contact section + validated enquiry form
13. Newsletter band with audience chips
14. Multi-column footer + legal bar
15. Floating back-to-top button

## Interactions ("ultra smooth")

- Two-state header: transparent white over the purple hero, solid white with a shadow on scroll
- Hero "Branch Address" button scrolls to the contact section, where the branch details and enquiry form live (a plain `#contact` anchor, so it works without JS too)
- Testimonials carousel with prev/next and responsive cards-per-view (3 → 2 → 1)
- **Auto-moving slider** on "Latest VFI Updates" — advances every 4.2s, pauses on hover/focus and when the tab is hidden, supports touch swipe and clickable dots, and stops entirely under `prefers-reduced-motion`. Reusable: put `data-autoslide` on any `.aslide` block (set speed with `data-interval`, cards-per-view with the `--per` CSS variable).
- **Scroll animations** — see the section below
- `requestAnimationFrame` count-up stats (eased)
- Native `<details>` accordion animated to real height, single-open
- Slide-in mobile drawer with scrim, focus-trap, `Esc` to close, body scroll-lock
- Smooth in-page scrolling with sticky-header offset
- Front-end form validation (contact + newsletter) with success states
- **Full `prefers-reduced-motion` support** — all motion is disabled for users who ask
- **Progressive enhancement** — content is fully visible even if JavaScript is disabled

## Customising

- **Brand name / logo:** search `VFI` in `index.html`; the logo is the `.brand` block (a lettermark + text — swap for an `<img>` if you have a logo file).
- **Contact details:** address, phone and email are **placeholders** (`Gulshan 1, Dhaka`, `+880 1700-000000`, `dhaka@vfi-edu.com`) — replace with your real details in the strip, contact section and footer.
- **Copy & images:** all text is original placeholder copy; event/blog cards use CSS-gradient placeholders (`.event__media--*`, `.blog__media--*`) — drop in real images by replacing the gradient with `background-image`.
- **Icons:** an inline SVG sprite lives at the top of `<body>`; reference any icon with `<svg class="ic"><use href="#i-name"/></svg>`.

## Accessibility & fidelity notes

The theme went through a multi-dimension review (correctness, responsive, a11y, CSS, fidelity). Applied improvements include: a skip-to-content link, a keyboard focus-trap + focus restoration for the mobile drawer (which is now properly hidden from tab/AT when closed), visible focus rings on the newsletter field and audience chips, `aria-invalid` + a polite live-region on the contact form, corrected heading order, `-webkit-backdrop-filter` and `100vh`/font fallbacks, and a smoother animated accordion collapse.

**One deliberate tradeoff:** the bright **coral** primary buttons (white text) and coral accent labels match the reference exactly but sit around ~2.8:1 contrast — below WCAG AA. This was kept for pixel-fidelity. If you'd rather be AA-compliant, add a token `--coral-btn:#c0392b` and set `.btn--primary{--bg:var(--coral-btn)}`, and use `#b23a28` for `.eyebrow`/`.event__tag` text.

## Notes

- 100% static and self-contained; the only external requests are Google Fonts.
- The enquiry and newsletter forms are front-end only (no data is sent) — wire them to your backend/endpoint when ready.
- Tested via headless Chrome at desktop (1440px) and mobile (430px) widths.
