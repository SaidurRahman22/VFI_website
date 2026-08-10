/* =====================================================================
   VFI — content store
   • Content JSON  -> window.VFI_BOOTSTRAP (server-injected) or localStorage
   • Uploaded images -> IndexedDB (falls back to localStorage)
   Loaded FIRST on every page (before site.js), AFTER js/api.js.

   BACKEND SEAM (Phase 0 — docs/phases/phase-0-...md, step 7):
   This is the HTTP-client shell. All ~35 exported names are stable so the 52
   static pages never change. Today it resolves synchronously against an
   injected  <script>window.VFI_BOOTSTRAP={…}</script>  (server data for that
   page) and localStorage — behaviour is unchanged when no bootstrap is present.
   Phase 2 swaps the read/write bodies to call window.VFIApi (js/api.js) while
   keeping every name identical.
   ===================================================================== */
window.VFI = (function () {
  "use strict";

  var LS_KEY = "vfi_content_v1";
  var IMG_PREFIX = "vfi_img_";
  var DB_NAME = "vfi_images", DB_STORE = "imgs", DB_VER = 1;

  /* ---------------- seed content (matches the static pages) ---------------- */
  var SEED = {
    settings: {
      brand: "VFI",
      tagline: "overseas education",
      about: "One of the fastest-growing study-abroad consultancies — helping students reach world-class universities with honest, end-to-end guidance.",
      phone: "+880 1700-000000",
      phone2: "+880 9600-000000",
      email: "dhaka@vfi-edu.com",
      address: "Level 6, VFI House, Gulshan Avenue, Gulshan 1, Dhaka 1212, Bangladesh",
      addressShort: "Gulshan 1, Dhaka 1212, Bangladesh",
      hours: "Saturday – Thursday, 10:00 am – 6:30 pm. Closed Fridays & public holidays.",
      facebook: "#", instagram: "#", linkedin: "#", x: "#", youtube: "#"
    },
    events: [
      { id: "e1", title: "UK University Spot Admissions Day", date: "2026-08-18", time: "10:00 am – 5:00 pm", type: "Spot Assessment", city: "Dhaka",
        desc: "Meet delegates from 20+ UK universities and get on-the-spot offers.", color: "a", imgId: "assets/img/city-uk.jpg" },
      { id: "e2", title: "Study in Canada: Intakes & Scholarships", date: "2026-08-24", time: "5:00 pm – 6:30 pm", type: "Webinar", city: "Online",
        desc: "A live session on pathways, PGWP and funding for Bangladeshi students.", color: "b", imgId: "assets/img/city-canada.jpg" },
      { id: "e3", title: "Global Education Fair — Dhaka", date: "2026-09-02", time: "10:30 am – 5:00 pm", type: "Fair", city: "Dhaka",
        desc: "One roof, dozens of universities across the USA, Australia & Europe.", color: "c", imgId: "assets/img/students-friends.jpg" },
      { id: "e4", title: "IELTS Masterclass: Score Band 8+", date: "2026-09-09", time: "6:00 pm – 7:30 pm", type: "Webinar", city: "Online",
        desc: "Free live coaching with strategies from our top instructors.", color: "b", imgId: "assets/img/students-study.jpg" },
      { id: "e5", title: "USA Virtual Admissions Week", date: "2026-09-16", time: "All week", type: "Spot Assessment", city: "Online",
        desc: "Apply, interview and receive offers from partner US universities.", color: "a", imgId: "assets/img/city-usa.jpg" },
      { id: "e6", title: "GRE & GMAT Strategy Session", date: "2026-09-28", time: "4:00 pm – 6:00 pm", type: "Coaching", city: "Dhaka",
        desc: "Plan your prep timeline and target schools with our coaches.", color: "c", imgId: "assets/img/library.jpg" }
    ],
    /* blogs — "body" is plain text, read by blog-post.html.
       Blank line = new paragraph · "## " = subheading · "- " = list item · "> " = quote */
    blogs: [
      { id: "b1", title: "Your 2026 student visa checklist for the USA", category: "Visa", date: "2026-08-07",
        excerpt: "Everything you need to prepare before your F-1 interview, in order.", color: "a", imgId: "assets/img/city-usa.jpg",
        author: "VFI Editorial Team", readTime: "",
        body:
          "A student visa refusal is rarely the result of one bad answer. It is almost always the result of preparation that started too late — a document requested in the final week, a form filled in from memory, an interview slot booked after the good dates had gone.\n\n" +
          "Work backwards from the first day of your course and the whole process turns into a list of small, ordinary tasks.\n\n" +
          "## Get the I-20 right before anything else\n" +
          "Nothing begins until your university issues the I-20. When it arrives, read it as carefully as you read your offer letter. Check that your name matches your passport exactly, that the programme start date is the one you agreed, and that the funding figure matches the evidence you plan to show. Report any error the same day — a correction takes days, and every later step quotes the numbers on this one document.\n\n" +
          "## Forms and fees, in the order they are needed\n" +
          "- Pay the SEVIS fee using the SEVIS ID printed on your I-20, then save the receipt as both a PDF and a printout.\n" +
          "- Complete the DS-160 in as few sittings as possible and write down the application ID before you close the browser. The photograph must meet the current specification; a rejected photo means starting the form again.\n" +
          "- Pay the visa application fee, then book your appointments. In Dhaka, biometrics and the interview are two separate visits on two different days.\n\n" +
          "## Build a funding story, not a pile of statements\n" +
          "Officers are looking for a source of money that makes sense, not for the largest possible balance. Show where the funds come from, who is providing them and why that person would. A sponsor letter, six months of consistent bank statements, an education loan sanction letter and any scholarship award letter together tell a clearer story than a single fat balance certificate. If there is one unusually large deposit, be ready to explain it in a sentence.\n\n" +
          "## Practise the interview out loud\n" +
          "Three questions do most of the work: why this university, why this course, and what you intend to do with the qualification afterwards. Answer each in your own words, in under thirty seconds, without a rehearsed script. Then practise the awkward follow-up — the gap year, the low semester, the sponsor who is not a parent.\n\n" +
          "> The interview is not a memory test. It is a short conversation in which a stranger decides whether you are a genuine student with a plan.\n\n" +
          "## The final six weeks\n" +
          "- Confirm accommodation and your arrival date with the university's international office.\n" +
          "- Book a flight that lands inside the entry window your visa allows before the programme begins.\n" +
          "- Carry your passport, I-20, fee receipts, admission letter and financial documents in hand luggage — never in a checked bag.\n" +
          "- Keep a scanned copy of everything in a cloud folder you can open from any phone.\n\n" +
          "Fees, forms and processing times change from year to year. Confirm every step against the official embassy and SEVP pages before you pay anything, and if a date slips, tell your university early. Deferring to the next intake is routine, and far better than travelling on paperwork that no longer matches your plan." },

      { id: "b2", title: "10 scholarships Bangladeshi students often miss", category: "Scholarships", date: "2026-07-02",
        excerpt: "High-value awards you can still apply for this intake season.", color: "b", imgId: "assets/img/study-room.jpg",
        author: "VFI Editorial Team", readTime: "",
        body:
          "Most students look for funding the way they look for flights: once, late, and only for the biggest name they can find. The awards that genuinely change a budget are usually smaller, quieter and much closer to the university itself.\n\n" +
          "## Start with the university, not the search engine\n" +
          "A large share of the money handed out every year never appears on a scholarship aggregator. It sits on departmental pages, in offer letters as an automatic tuition reduction, or in a one-line note from an admissions officer. Ask that officer directly which awards your offer already qualifies for and which need a separate form. It is the single highest-return email you will send during your application.\n\n" +
          "## Nine sources students skip\n" +
          "- Faculty and departmental awards attached to one programme rather than the whole university\n" +
          "- Early-acceptance or early-payment discounts, which reward a decision you were going to make anyway\n" +
          "- Regional bursaries reserved for applicants from South Asia\n" +
          "- Alumni association funds in your destination city, often small but rarely contested\n" +
          "- Industry and professional bodies in your field, including your family's employers\n" +
          "- Graduate assistantships and demonstrating roles, which pay in fee waivers as well as wages\n" +
          "- Sport, music and volunteering awards that ignore academic grades entirely\n" +
          "- Hardship and emergency funds, which open after you enrol and are worth knowing about in advance\n" +
          "- Government-funded schemes in the destination country, which usually close a full year before the intake\n\n" +
          "## Treat the application as writing, not paperwork\n" +
          "A scholarship essay answers a narrower question than a personal statement. It asks what you will do with the money and why that is worth funding. Name the project, the research question or the community problem. Give one specific example of work you have already done in that direction, even if it was small.\n\n" +
          "> Panels fund people whose next step they can picture clearly.\n\n" +
          "## Build the calendar backwards\n" +
          "Deadlines cluster eight to twelve months before an intake, which means the strongest applications are written while the applicant is still finishing a degree. Put every deadline in one document, then add two earlier dates for each: one for requesting references, one for a final read by someone who is not in your field.\n\n" +
          "## If you miss a round\n" +
          "Ask whether there is a second round — money set aside for students who decline their place often goes unclaimed. Ask, too, about continuing-student awards. Competition in the second year is far thinner, and a good first-year transcript is a stronger case than anything you can write before you arrive." },

      { id: "b3", title: "How to jump from Band 6.5 to Band 8 in 6 weeks", category: "IELTS", date: "2026-06-21",
        excerpt: "The exact study plan our top scorers follow before test day.", color: "c", imgId: "assets/img/students-study.jpg",
        author: "VFI Editorial Team", readTime: "",
        body:
          "The distance between Band 6.5 and Band 8 is not more effort. It is precision. A 6.5 script is usually understood perfectly well; it simply repeats the same small errors and reaches for the same familiar words. Six focused weeks is enough to close that gap if the practice is aimed at your errors rather than at the clock.\n\n" +
          "## Week zero: diagnose before you study\n" +
          "Sit one complete test under exam conditions — no pauses, no dictionary, timed writing. Then build an error log with four columns: vocabulary, grammar, task response and timing. Every mistake goes in one column. After a single test the pattern is usually obvious, and it is almost never spread evenly across all four.\n\n" +
          "## Weeks one and two: mechanics\n" +
          "- Reading: practise locating structure before detail. Read the headings, then the first line of each paragraph, then hunt for the answer. Most lost marks here are lost to time, not comprehension.\n" +
          "- Listening: use shadowing. Play sixty seconds, transcribe it exactly, then compare. The words you consistently mishear are the ones costing you marks.\n" +
          "- Keep a running list of the five grammar errors you make most. Reread it before every practice session.\n\n" +
          "## Weeks three and four: writing decides the band\n" +
          "Task 2 rewards a clear argument far more than an elaborate one. One idea per paragraph, stated in the first sentence, supported by a specific example rather than a general claim, and closed before the paragraph drifts. Budget forty minutes as five for planning, thirty for writing and five for checking — and actually spend the last five, because most 6.5 scripts contain three or four errors the writer can find unaided.\n\n" +
          "## Weeks five and six: speaking under mild pressure\n" +
          "Record yourself answering part two prompts with a phone timer running. Play it back once for content and once for fluency. Long pauses usually mean you are translating; a slightly simpler sentence delivered smoothly scores better than an ambitious one delivered in fragments.\n\n" +
          "> Examiners reward a clear, natural answer over an impressive one that never quite arrives.\n\n" +
          "## The last seven days\n" +
          "- One full mock early in the week, then stop testing yourself.\n" +
          "- Reread your error log daily. Nothing new, only what you already know you get wrong.\n" +
          "- Sleep properly for three nights before the test. It affects listening more than anything else you can do that week.\n\n" +
          "Band 8 means consistent accuracy, not perfection. If your mocks are landing at 7.5, sit the test anyway — exam-day focus is worth half a band to most candidates, and a retake is always possible." },

      { id: "b4", title: "PGWP explained: work in Canada after study", category: "Canada", date: "2026-06-10",
        excerpt: "Eligibility, duration and how to make the most of it.", color: "a", imgId: "assets/img/city-canada.jpg",
        author: "VFI Editorial Team", readTime: "",
        body:
          "The Post-Graduation Work Permit is the reason many students choose Canada, and also the part of the plan they research last. That is the wrong order. Eligibility is decided by choices you make before you enrol, not after you graduate.\n\n" +
          "## What the permit actually is\n" +
          "It is an open work permit, which means you do not need a job offer to hold it and you are not tied to one employer. Its length is linked to the length of the programme you completed, up to a published maximum. Crucially, it is normally issued once in a lifetime — a second qualification does not usually reset the clock.\n\n" +
          "## The conditions that catch students out\n" +
          "- The institution and the specific programme must both be eligible. Studying at a designated learning institution is not by itself sufficient.\n" +
          "- You must remain a full-time student in each academic session, with only limited authorised exceptions.\n" +
          "- The programme must meet the minimum length requirement.\n" +
          "- You must apply within the window that opens when your completion is confirmed, and hold valid status when you do.\n\n" +
          "Field-of-study and language requirements have been added and revised several times in recent years. Verify the current rules on the official immigration website before you accept an offer — not after, when changing course is expensive.\n\n" +
          "## Make the permit count\n" +
          "- Begin applying for roles in your final semester, not after graduation. Hiring cycles are slower than permit processing.\n" +
          "- Take co-op or internship terms if the programme offers them; local experience is what employers screen for first.\n" +
          "- Have the campus career service rewrite your CV into the local format. It is a free service that most international students use once, too late.\n\n" +
          "> A work permit buys you time. What you do in the first six months of it decides what comes next.\n\n" +
          "## If permanent residence is the goal\n" +
          "Skilled work experience gained on a PGWP feeds directly into the main economic immigration routes, but only if it is documented properly. From your first day, keep your contract, pay records, job title and a written description of your duties. Applicants lose eligible months every year simply because they cannot evidence what they did.\n\n" +
          "None of this is automatic, and the rules genuinely do change between intakes. Treat every timeline in this article as something to confirm against the official source on the day you rely on it." },

      { id: "b5", title: "Writing an SOP that admissions officers remember", category: "Applications", date: "2026-05-28",
        excerpt: "Structure, tone and the mistakes that get applications rejected.", color: "b", imgId: "assets/img/advisor.jpg",
        author: "VFI Editorial Team", readTime: "",
        body:
          "An admissions officer gives a statement of purpose about three minutes. Yours is competing with hundreds that open the same three ways: I have always been passionate, this university is prestigious, I want to serve my country. None of those sentences tells a reader anything they can act on.\n\n" +
          "## Open with a decision, not a feeling\n" +
          "Start at the moment your direction became specific: the project that failed, the module that changed your mind, the job that showed you what you did not want to do. A concrete first paragraph earns the next two minutes of attention. A declaration of lifelong passion does not.\n\n" +
          "## The five things a statement has to do\n" +
          "- Say what you want to study, precisely enough to name a sub-field rather than a department\n" +
          "- Show what you have already done that proves the interest — coursework, a project, a job, a failure you learned from\n" +
          "- Explain what you need from this particular programme: named modules, a lab, a body of research\n" +
          "- Address any gap or weak result once, plainly, without apology or drama\n" +
          "- Describe what happens after graduation, with a first step that sounds like something a real person would do\n\n" +
          "## Be specific about the programme\n" +
          "Name two modules and one researcher whose work overlaps with your interest, and give a single sentence on why for each. This is the paragraph most applicants leave generic, and it is the paragraph that proves you actually read the syllabus rather than the ranking.\n\n" +
          "## Cut the words that do no work\n" +
          "- Delete: passionate, prestigious, esteemed, since childhood, plethora, avail\n" +
          "- Replace claims with evidence — led a team of four beats excellent leadership skills\n" +
          "- Keep the average sentence under twenty-five words, and vary the length\n\n" +
          "> If your statement still makes sense with a different university's name pasted in, it is not finished.\n\n" +
          "## Before you submit\n" +
          "Read it aloud; every sentence you stumble over needs rewriting. Give it to one person inside your field and one outside it — the first checks that it is accurate, the second checks that it is readable. Then confirm the word limit, save it as a PDF named with your own name, and submit it a week before the deadline so a portal failure cannot cost you the application." },

      { id: "b6", title: "Education loans without collateral: a guide", category: "Finance", date: "2026-05-12",
        excerpt: "How to fund your degree and what lenders look for.", color: "c", imgId: "assets/img/handshake.jpg",
        author: "VFI Editorial Team", readTime: "",
        body:
          "An unsecured education loan is assessed on future earning potential rather than on property. That makes it reachable for families with no asset to pledge, and it also makes lenders particular about which course, which university and which co-applicant appear on the file.\n\n" +
          "## What a lender is really assessing\n" +
          "- The institution and the employability of the specific programme, not the country's reputation\n" +
          "- Your academic record and any standardised test scores\n" +
          "- The co-applicant's income stability and credit history, which usually matters more than their total income\n" +
          "- The gap between total cost and the amount requested, including living expenses\n" +
          "- Whether the destination permits part-time work during study\n\n" +
          "## Prepare the file before you approach anyone\n" +
          "Applications are declined for incompleteness far more often than for weakness. Assemble the admission letter and fee structure, the co-applicant's salary slips, tax returns and bank statements, your academic transcripts and test results, and a one-page summary of the total cost. A complete file with an average profile is frequently approved faster than a strong profile with three documents missing.\n\n" +
          "## Read the four numbers that decide the cost\n" +
          "- The interest rate, and whether it is fixed or floating for the life of the loan\n" +
          "- The moratorium period, and whether interest accrues during it — usually it does\n" +
          "- Processing fees and any compulsory insurance added to the principal\n" +
          "- The prepayment penalty, which decides whether early repayment is worth it\n\n" +
          "Ask each lender for the total amount repayable over the full term. Compare those figures, not the headline rates.\n\n" +
          "> The cheapest advertised rate is not always the cheapest loan.\n\n" +
          "## Borrow for the whole plan\n" +
          "Estimate living costs honestly, including the first month before any part-time income begins, and the flight home at the end. Students who borrow only for tuition often return for a second, more expensive top-up loan in the middle of the course, when their bargaining position is weakest.\n\n" +
          "## If you are declined\n" +
          "Ask for the reason in writing. The usual fixes are practical rather than dramatic: a co-applicant with steadier income, a larger self-funded portion, a shorter loan term, or the same application to a lender that knows your destination country better. And whatever you sign, read the sanction letter clause by clause first — nothing about a loan should surprise you later." }
    ],
    news: [
      { id: "n1", title: "Japan's growing demand for international talent", color: "a", imgId: "assets/img/team-office.jpg",
        excerpt: "Japan's demand for international talent is creating new opportunities for students building a global career." },
      { id: "n2", title: "UK university opens international merit scholarships", color: "b", imgId: "assets/img/city-uk.jpg",
        excerpt: "Students seeking financial support for higher education in the UK have a new opportunity for 2026." },
      { id: "n3", title: "Canada confirms new study permit rules for 2026", color: "a", imgId: "assets/img/city-canada.jpg",
        excerpt: "Updated processing timelines and financial requirements every applicant should plan around this intake." },
      { id: "n4", title: "Australia expands post-study work rights", color: "b", imgId: "assets/img/city-australia.jpg",
        excerpt: "Graduates in priority fields gain extra time to build local experience after finishing their degree." },
      { id: "n5", title: "Record number of students head abroad from Dhaka", color: "a", imgId: "assets/img/students-friends.jpg",
        excerpt: "Applications from Bangladesh reached an all-time high this year across our partner universities." }
    ],
    photos: [],
    /* per-country page overrides — empty means "use what is on the page" */
    countries: { usa: {}, canada: {}, ireland: {}, australia: {}, uk: {}, newzealand: {} },
    /* region hub pages (Europe / Asia) — empty means "use what is on the page" */
    regions: { europe: {}, asia: {} },
    /* student services page — empty means "use what is on the page" */
    servicesPage: {},
    /* VFI partner page — a blank string or an empty list means "use what is on the page" */
    partnerPage: {
      heroTitle: "", heroText: "", heroBtn1: "", heroBtn2: "",
      appTitle: "", appText: "",
      featTitle: "", featLead: "",
      ctaTitle: "", ctaBtn: "",
      stepsTitle: "", testTitle: "", jobsTitle: "", faqTitle: "",
      features: [],      /* { title, text, imgId } */
      steps: [],         /* { title, desc } */
      testimonials: [],  /* { quote, name } */
      jobs: [],          /* { title, location, type, dept } */
      faqs: []           /* { q, a } */
    },
    /* ---- VFI Partner console content ----
       Everything here seeds EMPTY. An empty list or a blank string means
       "keep the console page's built-in demo content" — exactly like the
       public-site overrides above. portal-render.js reads these on the
       partner-*.html pages; admin.js edits them. */
    ppManagers: [],    /* dashboard "Contact Regional Manager": { id, name, role, phone, city, email } */
    ppUpdates: [],     /* dashboard "Important Updates":        { id, flag, title, sub, date } */
    ppQuicklinks: [],  /* dashboard "Quick Links":             { id, label, url } */
    ppDocs: [],        /* Learning Resources documents:        { id, country, category, title, date, size, url } */
    ppEmails: [],      /* Email Updates rows:                  { id, subject, date } */
    ppNotifs: [],      /* Notifications:                       { id, title, text, date } */
    partnerPortal: {   /* console text — a blank field keeps the built-in wording */
      partnerName: "", welcome: "", tierName: "",
      benefits: "", loanText: "", accomText: "", testprepText: ""
    },
    /* page visibility — a file listed as false is hidden from the site */
    pages: {},
    /* named image slots used by fixed page sections (home page visuals) */
    media: {
      hero: null,
      students: null, partners: null, franchisees: null, universities: null,
      collage1: null, collage2: null, collage3: null,
      partnerHero: null, partnerApp: null
    }
  };

  /* ---------------- content (localStorage) ---------------- */
  var cache = null;

  function clone(o) { return JSON.parse(JSON.stringify(o)); }

  /* Fill any missing collection/field from SEED so partial or older payloads
     (a localStorage save, or a lean server-injected bootstrap) stay complete.
     Shared by the localStorage reader and the VFI_BOOTSTRAP reader. */
  function normalize(parsed) {
    if (!parsed || typeof parsed !== "object") return null;
    ["settings", "events", "blogs", "news", "photos", "media", "countries", "regions", "pages", "servicesPage", "partnerPage",
     "ppManagers", "ppUpdates", "ppQuicklinks", "ppDocs", "ppEmails", "ppNotifs", "partnerPortal"].forEach(function (k) {
      if (parsed[k] === undefined) parsed[k] = clone(SEED[k]);
    });
    Object.keys(SEED.media).forEach(function (k) {
      if (parsed.media[k] === undefined) parsed.media[k] = null;
    });
    Object.keys(SEED.settings).forEach(function (k) {
      if (parsed.settings[k] === undefined) parsed.settings[k] = SEED.settings[k];
    });
    // posts saved before the article page existed carry no body — take the seed's
    (parsed.blogs || []).forEach(function (b) {
      if (!b || b.body !== undefined) return;
      var seed = null;
      for (var i = 0; i < SEED.blogs.length; i++) if (SEED.blogs[i].id === b.id) { seed = SEED.blogs[i]; break; }
      b.body = seed ? seed.body : "";
      if (b.author === undefined) b.author = seed ? seed.author : "";
      if (b.readTime === undefined) b.readTime = "";
    });
    return parsed;
  }

  function read() {
    try {
      var raw = localStorage.getItem(LS_KEY);
      if (!raw) return null;
      return normalize(JSON.parse(raw));
    } catch (e) { return null; }
  }

  /* Phase 0 seam: the server injects <script>window.VFI_BOOTSTRAP={…}</script>
     before this file so the synchronous accessors below can serve real data
     with no async wait. Absent (today) → this returns null and nothing changes. */
  function bootstrapData() {
    try {
      if (!window.VFI_BOOTSTRAP || typeof window.VFI_BOOTSTRAP !== "object") return null;
      return normalize(clone(window.VFI_BOOTSTRAP));
    } catch (e) { return null; }
  }

  function data() {
    // localStorage (in-session admin edits) wins; then server-injected bootstrap;
    // then the built-in seed. Identical to the old path whenever no bootstrap exists.
    if (!cache) cache = read() || bootstrapData() || clone(SEED);
    return cache;
  }

  function save() {
    try {
      localStorage.setItem(LS_KEY, JSON.stringify(data()));
      return true;
    } catch (e) {
      console.warn("VFI store: save failed", e);
      return false;
    }
  }

  function uid(p) { return (p || "id") + "_" + Date.now().toString(36) + Math.random().toString(36).slice(2, 7); }

  /* ---------------- collections CRUD ---------------- */
  function list(kind) { return (data()[kind] || []).slice(); }

  function get(kind, id) {
    var arr = data()[kind] || [];
    for (var i = 0; i < arr.length; i++) if (arr[i].id === id) return arr[i];
    return null;
  }

  function put(kind, item) {
    var arr = data()[kind];
    if (!arr) { arr = data()[kind] = []; }
    if (!item.id) { item.id = uid(kind.slice(0, 1)); arr.unshift(item); }
    else {
      var found = false;
      for (var i = 0; i < arr.length; i++) if (arr[i].id === item.id) { arr[i] = item; found = true; break; }
      if (!found) arr.unshift(item);
    }
    save();
    return item;
  }

  function remove(kind, id) {
    var arr = data()[kind] || [];
    var item = get(kind, id);
    data()[kind] = arr.filter(function (x) { return x.id !== id; });
    save();
    if (item && item.imgId) delImage(item.imgId);
    return true;
  }

  function country(slug) {
    if (!data().countries) data().countries = {};
    if (!data().countries[slug]) data().countries[slug] = {};
    return data().countries[slug];
  }
  function saveCountry(slug, obj) {
    var c = country(slug);
    Object.keys(obj).forEach(function (k) { c[k] = obj[k]; });
    save();
    return c;
  }

  function servicesPage() {
    if (!data().servicesPage) data().servicesPage = {};
    return data().servicesPage;
  }
  function saveServicesPage(obj) {
    var p = servicesPage();
    Object.keys(obj).forEach(function (k) { p[k] = obj[k]; });
    save();
    return p;
  }

  function partnerPage() {
    if (!data().partnerPage) data().partnerPage = clone(SEED.partnerPage);
    return data().partnerPage;
  }
  function savePartnerPage(obj) {
    var p = partnerPage();
    Object.keys(obj).forEach(function (k) { p[k] = obj[k]; });
    save();
    return p;
  }

  function partnerPortal() {
    if (!data().partnerPortal) data().partnerPortal = clone(SEED.partnerPortal);
    return data().partnerPortal;
  }
  function savePartnerPortal(obj) {
    var p = partnerPortal();
    Object.keys(obj).forEach(function (k) { p[k] = obj[k]; });
    save();
    return p;
  }

  function pageEnabled(file) {
    var p = data().pages || {};
    return p[String(file || "").toLowerCase()] !== false;
  }
  function setPage(file, on) {
    if (!data().pages) data().pages = {};
    data().pages[String(file).toLowerCase()] = !!on;
    save();
    return !!on;
  }
  function baseName(href) {
    if (!href) return "";
    var h = String(href).split("#")[0].split("?")[0];
    var parts = h.split("/");
    return parts[parts.length - 1].toLowerCase();
  }

  function region(slug) {
    if (!data().regions) data().regions = {};
    if (!data().regions[slug]) data().regions[slug] = {};
    return data().regions[slug];
  }
  function saveRegion(slug, obj) {
    var r = region(slug);
    Object.keys(obj).forEach(function (k) { r[k] = obj[k]; });
    save();
    return r;
  }

  function media(key) { return key ? (data().media || {})[key] || null : (data().media || {}); }
  function setMedia(key, imgId) {
    if (!data().media) data().media = {};
    var old = data().media[key];
    data().media[key] = imgId || null;
    save();
    if (old && old !== imgId) delImage(old);
    return imgId || null;
  }

  function settings() { return data().settings; }
  function saveSettings(obj) {
    var s = data().settings;
    Object.keys(obj).forEach(function (k) { s[k] = obj[k]; });
    save();
    return s;
  }

  function reset() {
    cache = clone(SEED);
    save();
    return cache;
  }

  /* ---------------- images: IndexedDB with localStorage fallback ---------------- */
  var dbPromise = null, idbBroken = false;

  function openDB() {
    if (idbBroken) return Promise.reject(new Error("idb unavailable"));
    if (dbPromise) return dbPromise;
    dbPromise = new Promise(function (resolve, reject) {
      var req;
      try { req = indexedDB.open(DB_NAME, DB_VER); }
      catch (e) { idbBroken = true; return reject(e); }
      req.onupgradeneeded = function () {
        var db = req.result;
        if (!db.objectStoreNames.contains(DB_STORE)) db.createObjectStore(DB_STORE);
      };
      req.onsuccess = function () { resolve(req.result); };
      req.onerror = function () { idbBroken = true; reject(req.error || new Error("idb open failed")); };
      req.onblocked = function () { idbBroken = true; reject(new Error("idb blocked")); };
    });
    return dbPromise;
  }

  function idbOp(mode, fn) {
    return openDB().then(function (db) {
      return new Promise(function (resolve, reject) {
        var tx = db.transaction(DB_STORE, mode);
        var store = tx.objectStore(DB_STORE);
        var req = fn(store);
        tx.oncomplete = function () { resolve(req ? req.result : undefined); };
        tx.onerror = function () { reject(tx.error); };
        tx.onabort = function () { reject(tx.error); };
      });
    });
  }

  function putImage(id, dataUrl) {
    return idbOp("readwrite", function (s) { return s.put(dataUrl, id); })
      .then(function () { return id; })
      .catch(function () {
        try { localStorage.setItem(IMG_PREFIX + id, dataUrl); return id; }
        catch (e) { throw new Error("Storage full — try a smaller image."); }
      });
  }

  function getImage(id) {
    if (!id) return Promise.resolve(null);
    /* A path/URL imgId (e.g. a bundled default photo "assets/img/x.jpg")
       resolves to itself — only admin-uploaded ids ("img_…") live in IndexedDB.
       This lets SEED content and the pages ship real default photos that the
       admin can still override with an upload. */
    if (typeof id === "string" && (id.indexOf("/") !== -1 || /^https?:/.test(id) || /\.(jpe?g|png|webp|svg|gif|avif)$/i.test(id))) {
      return Promise.resolve(id);
    }
    return idbOp("readonly", function (s) { return s.get(id); })
      .then(function (v) { return v || localStorage.getItem(IMG_PREFIX + id) || null; })
      .catch(function () { return localStorage.getItem(IMG_PREFIX + id) || null; });
  }

  function delImage(id) {
    if (!id) return Promise.resolve();
    try { localStorage.removeItem(IMG_PREFIX + id); } catch (e) {}
    return idbOp("readwrite", function (s) { return s.delete(id); }).catch(function () {});
  }

  function allImages() {
    // returns {id: dataUrl} for export
    return idbOp("readonly", function (s) { return s.getAllKeys ? s.getAllKeys() : null; })
      .then(function (keys) {
        if (!keys) return {};
        return Promise.all(keys.map(function (k) {
          return getImage(k).then(function (v) { return [k, v]; });
        })).then(function (pairs) {
          var out = {};
          pairs.forEach(function (p) { if (p[1]) out[p[0]] = p[1]; });
          return out;
        });
      })
      .catch(function () {
        var out = {};
        for (var i = 0; i < localStorage.length; i++) {
          var k = localStorage.key(i);
          if (k && k.indexOf(IMG_PREFIX) === 0) out[k.slice(IMG_PREFIX.length)] = localStorage.getItem(k);
        }
        return out;
      });
  }

  /* ---------------- file -> resized data URL ---------------- */
  function fileToDataUrl(file, maxW, quality) {
    maxW = maxW || 1400; quality = quality || 0.82;
    return new Promise(function (resolve, reject) {
      if (!file || !/^image\//.test(file.type)) return reject(new Error("Please choose an image file."));
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () {
        try {
          var scale = Math.min(1, maxW / img.width);
          var w = Math.max(1, Math.round(img.width * scale));
          var h = Math.max(1, Math.round(img.height * scale));
          var c = document.createElement("canvas");
          c.width = w; c.height = h;
          var ctx = c.getContext("2d");
          ctx.fillStyle = "#fff"; ctx.fillRect(0, 0, w, h);
          ctx.drawImage(img, 0, 0, w, h);
          URL.revokeObjectURL(url);
          resolve(c.toDataURL("image/jpeg", quality));
        } catch (e) { URL.revokeObjectURL(url); reject(e); }
      };
      img.onerror = function () { URL.revokeObjectURL(url); reject(new Error("Could not read that image.")); };
      img.src = url;
    });
  }

  function uploadImage(file, maxW, quality) {
    return fileToDataUrl(file, maxW, quality).then(function (dataUrl) {
      var id = uid("img");
      return putImage(id, dataUrl).then(function () { return { id: id, dataUrl: dataUrl }; });
    });
  }

  /* ---------------- export / import ---------------- */
  function exportAll() {
    return allImages().then(function (imgs) {
      return { version: 1, exportedAt: new Date().toISOString(), content: data(), images: imgs };
    });
  }

  function importAll(payload) {
    if (!payload || !payload.content) return Promise.reject(new Error("That file doesn't look like a VFI backup."));
    cache = payload.content;
    save();
    var imgs = payload.images || {};
    var ids = Object.keys(imgs);
    return ids.reduce(function (p, id) {
      return p.then(function () { return putImage(id, imgs[id]); });
    }, Promise.resolve()).then(function () { return ids.length; });
  }

  /* ---------------- misc helpers ---------------- */
  function fmtDate(iso) {
    if (!iso) return "";
    var d = new Date(iso + (iso.length === 10 ? "T00:00:00" : ""));
    if (isNaN(d)) return iso;
    return d.toLocaleDateString("en-US", { day: "2-digit", month: "short", year: "numeric" });
  }
  function fmtDay(iso) {
    if (!iso) return "";
    var d = new Date(iso + (iso.length === 10 ? "T00:00:00" : ""));
    if (isNaN(d)) return iso;
    return d.toLocaleDateString("en-US", { day: "2-digit", month: "short" });
  }
  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  function storageOK() {
    try { localStorage.setItem("__vfi_t", "1"); localStorage.removeItem("__vfi_t"); return true; }
    catch (e) { return false; }
  }

  return {
    SEED: SEED,
    data: data, save: save, reset: reset,
    list: list, get: get, put: put, remove: remove,
    settings: settings, saveSettings: saveSettings,
    media: media, setMedia: setMedia,
    country: country, saveCountry: saveCountry,
    region: region, saveRegion: saveRegion,
    servicesPage: servicesPage, saveServicesPage: saveServicesPage,
    partnerPage: partnerPage, savePartnerPage: savePartnerPage,
    partnerPortal: partnerPortal, savePartnerPortal: savePartnerPortal,
    pageEnabled: pageEnabled, setPage: setPage, baseName: baseName,
    getImage: getImage, putImage: putImage, delImage: delImage, uploadImage: uploadImage,
    exportAll: exportAll, importAll: importAll,
    uid: uid, fmtDate: fmtDate, fmtDay: fmtDay, esc: esc, storageOK: storageOK
  };
})();
