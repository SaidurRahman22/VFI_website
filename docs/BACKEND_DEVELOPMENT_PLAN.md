# VFI Overseas Education — Backend Development Master Plan

> **Document Version:** 1.0  
> **Date:** 2026-08-09  
> **Author:** DevSecOps Engineering (15-year practice)  
> **Classification:** Internal — Engineering & Architecture  
> **Status:** DRAFT — Awaiting Stakeholder Approval

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Current State Audit](#2-current-state-audit)
3. [Technology Stack Recommendation](#3-technology-stack-recommendation)
4. [Database Architecture](#4-database-architecture)
5. [System Architecture Overview](#5-system-architecture-overview)
6. [Phased Implementation Roadmap](#6-phased-implementation-roadmap)
7. [Security Architecture & Compliance](#7-security-architecture--compliance)
8. [DevOps & CI/CD Pipeline](#8-devops--cicd-pipeline)
9. [Testing Strategy](#9-testing-strategy)
10. [Monitoring, Logging & Observability](#10-monitoring-logging--observability)
11. [Risk Register & Mitigation](#11-risk-register--mitigation)
12. [Appendices](#12-appendices)

---

## 1. Executive Summary

### 1.1 Project Context

VFI Overseas Education is a Dhaka-based study-abroad consultancy operating across Bangladesh, Nepal, and Sri Lanka with 60+ global offices, 1200+ university partnerships, and 730,000+ students served since 1998. The current website is a **100% static frontend** application (vanilla HTML5/CSS3/ES5 JavaScript) with zero backend infrastructure — all data persists in `localStorage` and `IndexedDB`.

### 1.2 The Problem

The website currently has:
- **No server-side data persistence** — all data is lost when browser storage is cleared
- **No real authentication** — auth flows are client-side simulations with `/* REAL REQUEST */` stubs
- **No multi-user capability** — localStorage is device-locked; no data sharing across devices/users
- **No document security** — student passports, transcripts, and visa papers have no secure storage
- **No transactional integrity** — partner wallet/commission tracking has no ACID guarantees
- **No audit trail** — no logging of who accessed/modified what, when
- **No search infrastructure** — university/course discovery is static HTML
- **No email/notification delivery** — no OTP delivery, no email alerts
- **No backup/disaster recovery** — JSON export to a local file is the only backup mechanism

### 1.3 The Goal

Build a **production-grade, security-first backend** that:
- Replaces all localStorage/IndexedDB persistence with a proper database
- Implements real authentication with OTP, session management, and RBAC
- Provides secure document storage and management
- Enables multi-user, multi-device, multi-office data synchronization
- Delivers enterprise-grade security, compliance, and audit logging
- Scales to support the organization's growth trajectory

### 1.4 Decision Summary

| Decision | Choice | Rationale |
|----------|--------|-----------|
| **Backend Framework** | Node.js + NestJS (TypeScript) | JS ecosystem alignment, enterprise-grade architecture, type safety |
| **Primary Database** | PostgreSQL 16+ | ACID compliance, JSONB flexibility, RLS, full-text search |
| **ORM** | Prisma | Type-safe queries, excellent migrations, schema-first design |
| **Cache / Session Store** | Redis 7+ | Session management, rate limiting, caching, pub/sub |
| **Object Storage** | AWS S3 / MinIO (self-hosted) | Secure document & image storage with pre-signed URLs |
| **Search Engine** | PostgreSQL FTS → Elasticsearch (Phase 6) | Start simple, scale when needed |
| **Email Service** | SendGrid / AWS SES | Transactional emails, OTP delivery |
| **SMS Service** | Twilio / local BD gateway | OTP delivery for Bangladesh/Nepal/Sri Lanka numbers |
| **Containerization** | Docker + Docker Compose | Reproducible environments, easy deployment |
| **CI/CD** | GitHub Actions | Automated testing, security scanning, deployment |
| **Deployment** | AWS (EC2/ECS) or DigitalOcean App Platform | Cost-effective, scalable, region-appropriate |

---

## 2. Current State Audit

### 2.1 Frontend Architecture Inventory

```
┌─────────────────────────────────────────────────────────────┐
│                    VFI_website (Static)                       │
├──────────────────┬──────────────────────────────────────────┤
│  Public Pages    │  40 HTML pages (marketing, country        │
│                  │  guides, services, blogs, events, etc.)   │
├──────────────────┼──────────────────────────────────────────┤
│  Student Portal  │  4 pages (login, verify, forgot, profile, │
│                  │  tracking) — CSS prefix: sa- / sp-        │
├──────────────────┼──────────────────────────────────────────┤
│  Partner Portal  │  15 pages (login, verify, forgot,         │
│                  │  dashboard, students, enquiries,          │
│                  │  applications, wallet, resources, etc.)   │
│                  │  — CSS prefix: pa- / pp-                  │
├──────────────────┼──────────────────────────────────────────┤
│  Admin Panel     │  1 page (admin.html) — all CMS functions  │
├──────────────────┼──────────────────────────────────────────┤
│  JavaScript      │  10 modules (~363 KB total):              │
│                  │  store.js, auth.js, partner-auth.js,      │
│                  │  portal.js, portal-render.js,             │
│                  │  student-portal.js, site.js, main.js,     │
│                  │  render.js, admin.js                      │
├──────────────────┼──────────────────────────────────────────┤
│  CSS             │  6 stylesheets (~347 KB total):           │
│                  │  style.css, admin.css, student-auth.css,  │
│                  │  partner-auth.css, student-portal.css,    │
│                  │  partner-portal.css                       │
├──────────────────┼──────────────────────────────────────────┤
│  Data Layer      │  localStorage (key: vfi_content_v1)       │
│                  │  IndexedDB (db: vfi_images, store: imgs)  │
│                  │  window.VFI IIFE API                      │
└──────────────────┴──────────────────────────────────────────┘
```

### 2.2 Identified Backend Integration Points

All backend wiring points are explicitly marked in source code with `/* REAL REQUEST */` comments:

| File | Stub Location | Required Backend Action |
|------|---------------|------------------------|
| `js/auth.js` | Sign In handler | POST `/api/auth/student/login` |
| `js/auth.js` | Create Account handler | POST `/api/auth/student/register` |
| `js/auth.js` | Send Reset Link | POST `/api/auth/student/forgot-password` |
| `js/auth.js` | Resend Reset Link | POST `/api/auth/student/resend-reset` |
| `js/auth.js` | Check OTP Code | POST `/api/auth/student/verify-otp` |
| `js/partner-auth.js` | Partner Sign In | POST `/api/auth/partner/login` |
| `js/partner-auth.js` | Agency Registration | POST `/api/auth/partner/register` |
| `js/partner-auth.js` | Password Reset | POST `/api/auth/partner/forgot-password` |
| `js/partner-auth.js` | OTP Check | POST `/api/auth/partner/verify-otp` |
| `js/portal.js` | Register New Student | POST `/api/partner/students` |
| `js/portal.js` | Request Program Options | POST `/api/partner/program-requests` |

### 2.3 Identified User Roles & Access Levels

| Role | Portal | Current Auth | Pages |
|------|--------|--------------|-------|
| **Anonymous Visitor** | Public Site | None | 40 marketing pages |
| **Student** | Student Portal | Simulated (localStorage) | login, verify, forgot, profile, tracking |
| **Partner (Sub-Agent)** | Partner Console | Simulated (localStorage) | 15 partner-*.html pages |
| **Franchisee** | N/A (future) | None | Currently public info page only |
| **Institution** | N/A (future) | None | Currently public info page only |
| **Admin** | Admin Panel | None (open access) | admin.html |
| **Super Admin** | Admin Panel (future) | None | Full system control |

### 2.4 Data Model Inventory (Extracted from Frontend)

**Total distinct data entities identified: 22**

| # | Entity | Source | Current Storage | Fields Count |
|---|--------|--------|----------------|--------------|
| 1 | Student Profile | student-profile.html | localStorage (`vfi_student_profile`) | 35+ fields |
| 2 | Student Application | student-tracking.html | Static HTML (demo) | 12+ fields |
| 3 | Application Journey Step | student-tracking.html | Static HTML (demo) | 5 fields |
| 4 | Application Timeline Entry | student-tracking.html | Static HTML (demo) | 5 fields |
| 5 | Pending Action Item | student-tracking.html | Static HTML (demo) | 5 fields |
| 6 | Student Document | student-profile.html | localStorage | 3 fields per doc type |
| 7 | Partner/Agency | vfi-partner-login.html | None (registration stub) | 15+ fields |
| 8 | Partner Student Record | partner-students.html | Static HTML (demo) | 8+ fields |
| 9 | Partner Enquiry | partner-enquiries.html | Static HTML (demo) | 6+ fields |
| 10 | Partner Wallet Transaction | partner-wallet.html | Static HTML (demo) | 5+ fields |
| 11 | Contact Enquiry | contact.html | None (form never submits) | 5 fields |
| 12 | Event | admin.html (store.js) | localStorage | 7 fields |
| 13 | Blog Post | admin.html (store.js) | localStorage | 8 fields |
| 14 | News Item | admin.html (store.js) | localStorage | 6 fields |
| 15 | Gallery Photo | admin.html (store.js) | IndexedDB | 3 fields |
| 16 | Country Page Content | admin.html (store.js) | localStorage | 14+ image slots, 20+ text fields |
| 17 | Region Page Content | admin.html (store.js) | localStorage | Similar to country |
| 18 | Service Block | admin.html (store.js) | localStorage | 6 fields per block × 9 blocks |
| 19 | Site Settings | admin.html (store.js) | localStorage | 14 fields |
| 20 | University | universities.html | Static HTML | 6+ fields |
| 21 | Regional Manager | admin.html (store.js) | localStorage | 5 fields |
| 22 | Partner Notification | admin.html (store.js) | localStorage | 4 fields |

---

## 3. Technology Stack Recommendation

### 3.1 Backend Framework: **NestJS (Node.js + TypeScript)**

#### Why NestJS over alternatives?

| Criterion | NestJS (Node.js) | Django (Python) | Laravel (PHP) | Spring Boot (Java) |
|-----------|:-----------------:|:---------------:|:-------------:|:-------------------:|
| **JS Ecosystem Alignment** | ✅ Same language as frontend | ❌ Different language | ❌ Different language | ❌ Different language |
| **TypeScript Type Safety** | ✅ Native | ⚠️ Type hints only | ❌ No native types | ✅ Strong typing |
| **Enterprise Architecture** | ✅ Modules, DI, Guards, Pipes | ✅ Django admin, ORM | ✅ Eloquent, Artisan | ✅ Spring ecosystem |
| **Learning Curve** | ⚠️ Moderate (Angular-inspired) | ✅ Low | ✅ Low | ❌ Steep |
| **Async I/O Performance** | ✅ Non-blocking event loop | ⚠️ WSGI/ASGI | ⚠️ Blocking by default | ✅ Reactive possible |
| **Microservices Ready** | ✅ Built-in transport layer | ⚠️ Manual setup | ⚠️ Manual setup | ✅ Spring Cloud |
| **Security Ecosystem** | ✅ Helmet, Passport, Guards | ✅ Built-in | ✅ Built-in | ✅ Spring Security |
| **Community / Hiring** | ✅ Large JS talent pool in BD | ⚠️ Smaller pool in BD | ✅ Large PHP pool | ⚠️ Limited pool |
| **Real-time (WebSocket)** | ✅ Built-in Gateway | ⚠️ Channels addon | ⚠️ Laravel Echo | ⚠️ STOMP setup |
| **Testing Framework** | ✅ Jest built-in | ✅ Pytest | ✅ PHPUnit | ✅ JUnit |

**Verdict:** NestJS wins because:
1. **Same ecosystem** — Your team already writes JavaScript. TypeScript is a natural upgrade.
2. **Enterprise-grade** — Modular architecture with dependency injection, interceptors, guards, and pipes maps perfectly to the multi-portal structure (Student, Partner, Admin).
3. **Security-first** — Built-in support for Passport.js strategies, CORS, rate limiting, validation pipes, and Helmet.js middleware.
4. **Scalable** — When you outgrow a monolith, NestJS has a built-in microservices transport layer.
5. **Talent availability** — Bangladesh has a strong Node.js developer community.

#### NestJS Module Architecture for VFI:

```
src/
├── app.module.ts                  # Root module
├── common/                        # Shared utilities
│   ├── decorators/                # Custom decorators (@CurrentUser, @Roles)
│   ├── filters/                   # Exception filters (HTTP, validation)
│   ├── guards/                    # Auth guards (JWT, Role, Throttle)
│   ├── interceptors/              # Logging, transform, cache
│   ├── pipes/                     # Validation, sanitization
│   └── middleware/                # Helmet, CORS, request ID
├── config/                        # Environment & app configuration
├── modules/
│   ├── auth/                      # Authentication & authorization
│   │   ├── strategies/            # JWT, Local, Refresh strategies
│   │   ├── guards/                # JwtAuthGuard, RolesGuard
│   │   └── dto/                   # Login, Register, OTP DTOs
│   ├── users/                     # User entity (students, partners, admins)
│   ├── students/                  # Student profiles, preferences, documents
│   ├── applications/              # Application tracking, journey, timeline
│   ├── partners/                  # Partner/agency management
│   ├── universities/              # University database, courses, programs
│   ├── enquiries/                 # Contact form submissions, lead management
│   ├── content/                   # CMS (blogs, news, events, gallery)
│   ├── countries/                 # Country/region page content management
│   ├── services/                  # Service page content management
│   ├── documents/                 # File upload, S3 integration
│   ├── notifications/             # Email, SMS, in-app notifications
│   ├── wallet/                    # Partner commission & wallet
│   ├── search/                    # University/course search engine
│   ├── settings/                  # Site-wide settings management
│   └── admin/                     # Admin-specific operations
├── database/
│   ├── prisma/                    # Prisma schema, migrations, seed
│   └── redis/                     # Redis module configuration
└── main.ts                        # Bootstrap
```

### 3.2 Database: **PostgreSQL 16+**

#### Why PostgreSQL?

| Requirement | PostgreSQL | MySQL | MongoDB | SQLite |
|-------------|:----------:|:-----:|:-------:|:------:|
| **ACID Transactions** (wallet, applications) | ✅ Full | ✅ Full | ⚠️ Limited | ✅ Full |
| **JSONB** (flexible CMS content) | ✅ Native indexed | ⚠️ JSON type, limited | ✅ Native | ❌ Text only |
| **Row-Level Security** (multi-tenant isolation) | ✅ Built-in RLS | ❌ No | ❌ No | ❌ No |
| **Full-Text Search** (university search) | ✅ tsvector/tsquery | ✅ FULLTEXT | ✅ Atlas Search | ❌ Basic |
| **Document Storage Metadata** | ✅ JSONB arrays | ⚠️ JSON | ✅ Native | ❌ No |
| **Complex Joins** (student→apps→universities) | ✅ Excellent | ✅ Good | ❌ Lookup pipelines | ✅ Good |
| **Enum Types** (status fields) | ✅ Native enum | ✅ ENUM | ❌ String validation | ❌ No |
| **UUID Primary Keys** | ✅ gen_random_uuid() | ⚠️ Plugin | ✅ ObjectId | ❌ No |
| **Partitioning** (archival, time-series logs) | ✅ Declarative | ✅ Range/Hash | ✅ Sharding | ❌ No |
| **Hosting Cost** | ✅ Free open source | ✅ Free open source | ⚠️ Atlas pricing | ✅ Free |

**Verdict:** PostgreSQL is the clear winner because:
1. **Relational core** — Student → Applications → Universities → Offers is inherently relational.
2. **JSONB flexibility** — CMS content (blogs, country pages) benefits from schema-flexible JSONB columns without sacrificing queryability.
3. **Financial integrity** — ACID compliance is non-negotiable for wallet transactions and commission settlements.
4. **Row-Level Security** — Partners must ONLY see their own students. RLS enforces this at the database layer, not just the application layer — defense in depth.
5. **Full-text search** — University/course search starts with PostgreSQL FTS and can graduate to Elasticsearch later.
6. **Battle-tested** — Powers Instagram, Spotify, Discord — proven at extreme scale.

### 3.3 ORM: **Prisma**

| Feature | Prisma | TypeORM | Sequelize | Knex.js |
|---------|:------:|:-------:|:---------:|:-------:|
| Type Safety | ✅ Auto-generated types | ⚠️ Manual decorators | ❌ Dynamic | ❌ Manual |
| Migration System | ✅ Declarative schema | ✅ CLI-driven | ✅ CLI-driven | ✅ Manual SQL |
| Query Builder | ✅ Fluent + raw SQL | ✅ QueryBuilder | ✅ Finder | ✅ SQL builder |
| Prisma Studio (GUI) | ✅ Built-in DB browser | ❌ | ❌ | ❌ |
| NestJS Integration | ✅ Official recipe | ✅ @nestjs/typeorm | ⚠️ Community | ⚠️ Manual |
| Learning Curve | ✅ Low | ⚠️ Moderate | ⚠️ Moderate | ⚠️ High |

**Verdict:** Prisma provides the safest, most productive developer experience with auto-generated TypeScript types from the database schema.

### 3.4 Supporting Infrastructure

| Component | Technology | Purpose |
|-----------|-----------|---------|
| **Cache & Sessions** | Redis 7+ | JWT session blacklist, rate limiter state, query caching, pub/sub for real-time notifications |
| **Object Storage** | AWS S3 or MinIO | Student documents (passport, transcripts, SOP, LOR, visa docs), gallery images, blog covers |
| **Email Delivery** | SendGrid (primary) / AWS SES (fallback) | OTP codes, password reset links, application status updates, partner notifications |
| **SMS Delivery** | Twilio / local BD SMS gateway (e.g., BulkSMSBD, SSL Wireless) | Phone OTP verification for Bangladesh/Nepal/Sri Lanka numbers |
| **Task Queue** | BullMQ (Redis-backed) | Email sending, image processing, report generation, document virus scanning |
| **API Documentation** | Swagger/OpenAPI 3.0 via @nestjs/swagger | Auto-generated interactive API docs |
| **Containerization** | Docker + Docker Compose | Development parity, reproducible builds, easy onboarding |
| **Reverse Proxy** | Nginx | SSL termination, static file serving, rate limiting, gzip compression |
| **Process Manager** | PM2 (production) | Cluster mode, zero-downtime restarts, log management |

---

## 4. Database Architecture

### 4.1 Entity-Relationship Overview

```
┌──────────────┐     ┌──────────────┐     ┌──────────────────┐
│    User      │────→│  StudentProfile│────→│    Document       │
│  (all roles) │     │              │     │  (S3 reference)   │
└──────┬───────┘     └──────┬───────┘     └──────────────────┘
       │                    │
       │                    ▼
       │             ┌──────────────┐     ┌──────────────────┐
       │             │  Application │────→│    University     │
       │             │              │     │                  │
       │             └──────┬───────┘     └──────────────────┘
       │                    │
       │                    ▼
       │             ┌──────────────┐
       │             │  JourneyStep │
       │             │  Timeline    │
       │             │  ActionItem  │
       │             └──────────────┘
       │
       ▼
┌──────────────┐     ┌──────────────┐
│  Partner     │────→│ PartnerStudent│
│  (Agency)    │     │  (junction)  │
└──────┬───────┘     └──────────────┘
       │
       ▼
┌──────────────┐     ┌──────────────┐
│  Wallet      │     │  Enquiry     │
│  Transaction │     │  (leads)     │
└──────────────┘     └──────────────┘

┌──────────────┐     ┌──────────────┐     ┌──────────────────┐
│  BlogPost    │     │  Event       │     │  NewsItem        │
└──────────────┘     └──────────────┘     └──────────────────┘

┌──────────────┐     ┌──────────────┐     ┌──────────────────┐
│  CountryPage │     │  ServicePage │     │  SiteSettings    │
└──────────────┘     └──────────────┘     └──────────────────┘

┌──────────────┐     ┌──────────────┐
│  Notification│     │  AuditLog    │
└──────────────┘     └──────────────┘
```

### 4.2 Core Database Tables (Prisma Schema Preview)

```prisma
// ============================================================
// USERS & AUTHENTICATION
// ============================================================

enum UserRole {
  STUDENT
  PARTNER
  ADMIN
  SUPER_ADMIN
}

enum UserStatus {
  PENDING_VERIFICATION
  ACTIVE
  SUSPENDED
  DEACTIVATED
}

model User {
  id              String      @id @default(uuid()) @db.Uuid
  email           String      @unique
  passwordHash    String
  role            UserRole
  status          UserStatus  @default(PENDING_VERIFICATION)
  emailVerified   Boolean     @default(false)
  phoneVerified   Boolean     @default(false)
  mfaEnabled      Boolean     @default(false)
  mfaSecret       String?
  lastLoginAt     DateTime?
  loginAttempts   Int         @default(0)
  lockedUntil     DateTime?
  createdAt       DateTime    @default(now())
  updatedAt       DateTime    @updatedAt

  student         StudentProfile?
  partner         Partner?
  sessions        Session[]
  notifications   Notification[]
  auditLogs       AuditLog[]
}

model Session {
  id            String    @id @default(uuid()) @db.Uuid
  userId        String    @db.Uuid
  refreshToken  String    @unique
  userAgent     String?
  ipAddress     String?
  expiresAt     DateTime
  createdAt     DateTime  @default(now())

  user          User      @relation(fields: [userId], references: [id])
}

// ============================================================
// STUDENT DOMAIN
// ============================================================

model StudentProfile {
  id            String    @id @default(uuid()) @db.Uuid
  userId        String    @unique @db.Uuid
  fileId        String    @unique  // e.g., "VFI-2026-04871"
  firstName     String
  lastName      String
  dateOfBirth   DateTime?
  nationality   String?
  countryCode   String?   // Dial code
  phone         String?
  addressLine1  String?
  addressLine2  String?
  city          String?
  district      String?
  postcode      String?
  country       String?
  completeness  Int       @default(0)  // 0-100
  createdAt     DateTime  @default(now())
  updatedAt     DateTime  @updatedAt

  user          User        @relation(fields: [userId], references: [id])
  academics     Academic[]
  testScores    TestScore[]
  preferences   StudentPreference?
  documents     Document[]
  applications  Application[]
  enquiries     Enquiry[]
}

model Academic {
  id            String    @id @default(uuid()) @db.Uuid
  studentId     String    @db.Uuid
  qualification String
  institution   String
  year          String
  grade         String
  sortOrder     Int       @default(0)
  createdAt     DateTime  @default(now())

  student       StudentProfile @relation(fields: [studentId], references: [id])
}

model TestScore {
  id          String    @id @default(uuid()) @db.Uuid
  studentId   String    @db.Uuid
  testType    String    // IELTS Academic, TOEFL iBT, PTE, GRE, GMAT, etc.
  score       String
  testDate    DateTime?
  sortOrder   Int       @default(0)
  createdAt   DateTime  @default(now())

  student     StudentProfile @relation(fields: [studentId], references: [id])
}

model StudentPreference {
  id              String    @id @default(uuid()) @db.Uuid
  studentId       String    @unique @db.Uuid
  destinations    String[]  // ["United Kingdom", "Canada", "Australia"]
  preferredIntake String?   // "September 2026"
  budgetRange     String?   // "USD 15,000 to 25,000"
  fieldOfStudy    String?   // "Computing & Data"
  createdAt       DateTime  @default(now())
  updatedAt       DateTime  @updatedAt

  student         StudentProfile @relation(fields: [studentId], references: [id])
}

enum DocumentStatus {
  MISSING
  UPLOADED
  UNDER_REVIEW
  VERIFIED
  REJECTED
}

enum DocumentCategory {
  APPLICATION
  VISA
}

model Document {
  id            String           @id @default(uuid()) @db.Uuid
  studentId     String           @db.Uuid
  category      DocumentCategory
  documentType  String           // "passport", "transcript", "sop", etc.
  status        DocumentStatus   @default(MISSING)
  fileName      String?
  fileKey       String?          // S3 object key
  fileSize      Int?
  mimeType      String?
  uploadedAt    DateTime?
  verifiedAt    DateTime?
  verifiedBy    String?          @db.Uuid
  rejectionNote String?
  createdAt     DateTime         @default(now())
  updatedAt     DateTime         @updatedAt

  student       StudentProfile @relation(fields: [studentId], references: [id])
}

// ============================================================
// APPLICATION TRACKING
// ============================================================

enum ApplicationStatus {
  DRAFT
  SUBMITTED
  UNDER_REVIEW
  CONDITIONAL_OFFER
  UNCONDITIONAL_OFFER
  OFFER_ACCEPTED
  OFFER_DECLINED
  VISA_PENDING
  VISA_APPROVED
  VISA_REJECTED
  ENROLLED
  WITHDRAWN
}

model Application {
  id                String            @id @default(uuid()) @db.Uuid
  studentId         String            @db.Uuid
  universityId      String            @db.Uuid
  programName       String
  intake            String
  status            ApplicationStatus @default(DRAFT)
  appliedDate       DateTime?
  deadlineDate      DateTime?
  notes             String?
  conditionDetails  String?
  partnerId         String?           @db.Uuid
  counsellorId      String?           @db.Uuid
  createdAt         DateTime          @default(now())
  updatedAt         DateTime          @updatedAt

  student           StudentProfile @relation(fields: [studentId], references: [id])
  university        University     @relation(fields: [universityId], references: [id])
  partner           Partner?       @relation(fields: [partnerId], references: [id])
  journeySteps      JourneyStep[]
  timelineEntries   TimelineEntry[]
  actionItems       ActionItem[]
}

model JourneyStep {
  id            String    @id @default(uuid()) @db.Uuid
  applicationId String    @db.Uuid
  stepNumber    Int
  title         String
  status        String    @default("pending") // "done", "active", "pending"
  completedAt   DateTime?
  note          String?

  application   Application @relation(fields: [applicationId], references: [id])
}

model TimelineEntry {
  id            String    @id @default(uuid()) @db.Uuid
  applicationId String    @db.Uuid
  title         String
  description   String?
  category      String?
  createdAt     DateTime  @default(now())

  application   Application @relation(fields: [applicationId], references: [id])
}

model ActionItem {
  id            String    @id @default(uuid()) @db.Uuid
  applicationId String    @db.Uuid
  title         String
  description   String?
  priority      String    @default("normal") // "urgent", "normal"
  actionUrl     String?
  completed     Boolean   @default(false)
  dueDate       DateTime?
  createdAt     DateTime  @default(now())

  application   Application @relation(fields: [applicationId], references: [id])
}

// ============================================================
// UNIVERSITY & COURSES
// ============================================================

model University {
  id            String    @id @default(uuid()) @db.Uuid
  name          String
  location      String
  country       String
  website       String?
  logoKey       String?   // S3 key
  ranking       Int?
  isFeatured    Boolean   @default(false)
  isActive      Boolean   @default(true)
  metadata      Json?     // Flexible JSONB for additional attributes
  createdAt     DateTime  @default(now())
  updatedAt     DateTime  @updatedAt

  programs      Program[]
  applications  Application[]
}

model Program {
  id            String    @id @default(uuid()) @db.Uuid
  universityId  String    @db.Uuid
  name          String
  level         String    // "foundation", "diploma", "bachelors", "masters", "mba", "phd"
  field         String
  duration      String?
  tuitionFee    String?
  currency      String?   @default("USD")
  intakes       String[]
  requirements  Json?     // JSONB for flexible entry requirements
  isActive      Boolean   @default(true)
  createdAt     DateTime  @default(now())
  updatedAt     DateTime  @updatedAt

  university    University @relation(fields: [universityId], references: [id])
}

// ============================================================
// PARTNER DOMAIN
// ============================================================

enum PartnerType {
  SUB_AGENT
  FRANCHISE
  INSTITUTION
}

enum PartnerStatus {
  PENDING_APPROVAL
  ACTIVE
  SUSPENDED
  TERMINATED
}

model Partner {
  id                String        @id @default(uuid()) @db.Uuid
  userId            String        @unique @db.Uuid
  partnerType       PartnerType
  status            PartnerStatus @default(PENDING_APPROVAL)
  agencyName        String
  registrationNo    String?
  contactPerson     String
  phone             String
  city              String
  country           String
  territory         String?
  tierName          String?       @default("Standard")
  commissionRate    Decimal?      @db.Decimal(5, 2)
  onboardedAt       DateTime?
  createdAt         DateTime      @default(now())
  updatedAt         DateTime      @updatedAt

  user              User              @relation(fields: [userId], references: [id])
  students          PartnerStudent[]
  applications      Application[]
  walletTransactions WalletTransaction[]
  enquiries         Enquiry[]
}

model PartnerStudent {
  id          String    @id @default(uuid()) @db.Uuid
  partnerId   String    @db.Uuid
  studentId   String    @db.Uuid
  assignedAt  DateTime  @default(now())

  partner     Partner @relation(fields: [partnerId], references: [id])
}

// ============================================================
// WALLET & FINANCIALS
// ============================================================

enum TransactionType {
  COMMISSION_CREDIT
  COMMISSION_DEBIT
  PAYOUT
  ADJUSTMENT
  REFUND
}

enum TransactionStatus {
  PENDING
  COMPLETED
  FAILED
  REVERSED
}

model WalletTransaction {
  id            String            @id @default(uuid()) @db.Uuid
  partnerId     String            @db.Uuid
  type          TransactionType
  status        TransactionStatus @default(PENDING)
  amount        Decimal           @db.Decimal(12, 2)
  currency      String            @default("BDT")
  description   String?
  referenceId   String?           // Links to application or invoice
  processedAt   DateTime?
  createdAt     DateTime          @default(now())

  partner       Partner @relation(fields: [partnerId], references: [id])
}

// ============================================================
// ENQUIRIES / LEADS
// ============================================================

enum EnquiryStatus {
  NEW
  CONTACTED
  QUALIFIED
  CONVERTED
  CLOSED
}

model Enquiry {
  id            String        @id @default(uuid()) @db.Uuid
  fullName      String
  phone         String
  email         String
  destination   String?
  message       String?
  source        String?       // "contact_form", "partner_referral", etc.
  status        EnquiryStatus @default(NEW)
  assignedTo    String?       @db.Uuid
  studentId     String?       @db.Uuid
  partnerId     String?       @db.Uuid
  createdAt     DateTime      @default(now())
  updatedAt     DateTime      @updatedAt

  student       StudentProfile? @relation(fields: [studentId], references: [id])
  partner       Partner?        @relation(fields: [partnerId], references: [id])
}

// ============================================================
// CMS CONTENT
// ============================================================

model BlogPost {
  id          String    @id @default(uuid()) @db.Uuid
  title       String
  slug        String    @unique
  author      String
  category    String
  summary     String?
  body        String    // Markdown content
  coverKey    String?   // S3 key for cover image
  readTime    Int?      // Minutes
  isPublished Boolean   @default(false)
  publishedAt DateTime?
  createdAt   DateTime  @default(now())
  updatedAt   DateTime  @updatedAt
}

model Event {
  id          String    @id @default(uuid()) @db.Uuid
  title       String
  date        DateTime
  endDate     DateTime?
  location    String
  category    String    // "Spot Admissions", "Webinar", "Education Fair", "Coaching"
  tag         String?
  description String?
  imageKey    String?
  isFeatured  Boolean   @default(false)
  isPublished Boolean   @default(true)
  createdAt   DateTime  @default(now())
  updatedAt   DateTime  @updatedAt
}

model NewsItem {
  id          String    @id @default(uuid()) @db.Uuid
  title       String
  date        DateTime
  category    String
  body        String?
  imageKey    String?
  isPublished Boolean   @default(true)
  createdAt   DateTime  @default(now())
  updatedAt   DateTime  @updatedAt
}

model GalleryPhoto {
  id          String    @id @default(uuid()) @db.Uuid
  caption     String?
  imageKey    String    // S3 key
  sortOrder   Int       @default(0)
  createdAt   DateTime  @default(now())
}

// ============================================================
// PAGE CONTENT MANAGEMENT
// ============================================================

model PageContent {
  id          String    @id @default(uuid()) @db.Uuid
  pageSlug    String    @unique // "country-uk", "country-usa", "services", etc.
  content     Json      // JSONB — flexible schema for any page type
  isPublished Boolean   @default(true)
  updatedBy   String?   @db.Uuid
  createdAt   DateTime  @default(now())
  updatedAt   DateTime  @updatedAt
}

model MediaSlot {
  id          String    @id @default(uuid()) @db.Uuid
  slotName    String    @unique // "hero", "students", "partners", etc.
  imageKey    String?   // S3 key
  altText     String?
  updatedBy   String?   @db.Uuid
  createdAt   DateTime  @default(now())
  updatedAt   DateTime  @updatedAt
}

model SiteSettings {
  id            String    @id @default(uuid()) @db.Uuid
  brand         String
  tagline       String?
  about         String?
  phone         String?
  phone2        String?
  email         String?
  addressShort  String?
  address       String?
  hours         String?
  facebook      String?
  instagram     String?
  linkedin      String?
  twitter       String?
  youtube       String?
  updatedBy     String?   @db.Uuid
  updatedAt     DateTime  @updatedAt
}

// ============================================================
// NOTIFICATIONS
// ============================================================

enum NotificationChannel {
  IN_APP
  EMAIL
  SMS
}

model Notification {
  id          String              @id @default(uuid()) @db.Uuid
  userId      String              @db.Uuid
  channel     NotificationChannel @default(IN_APP)
  title       String
  body        String?
  actionUrl   String?
  isRead      Boolean             @default(false)
  readAt      DateTime?
  createdAt   DateTime            @default(now())

  user        User @relation(fields: [userId], references: [id])
}

// ============================================================
// AUDIT & SECURITY
// ============================================================

model AuditLog {
  id          String    @id @default(uuid()) @db.Uuid
  userId      String?   @db.Uuid
  action      String    // "LOGIN", "PROFILE_UPDATE", "DOCUMENT_UPLOAD", etc.
  resource    String    // "User", "Application", "Document", etc.
  resourceId  String?
  details     Json?     // JSONB for change diff
  ipAddress   String?
  userAgent   String?
  createdAt   DateTime  @default(now())

  user        User? @relation(fields: [userId], references: [id])
}
```

### 4.3 Estimated Table Counts at Scale

| Entity | Year 1 | Year 3 | Year 5 |
|--------|--------|--------|--------|
| Users | ~5,000 | ~25,000 | ~100,000 |
| Student Profiles | ~3,000 | ~18,000 | ~75,000 |
| Applications | ~9,000 | ~54,000 | ~225,000 |
| Documents | ~18,000 | ~108,000 | ~450,000 |
| Universities | ~1,200 | ~2,000 | ~3,000 |
| Partners | ~200 | ~800 | ~2,000 |
| Enquiries | ~10,000 | ~50,000 | ~200,000 |
| Wallet Transactions | ~5,000 | ~30,000 | ~120,000 |
| Audit Logs | ~100,000 | ~1,000,000 | ~5,000,000+ |

---

## 5. System Architecture Overview

### 5.1 High-Level Architecture Diagram

```
                        ┌────────────────────────┐
                        │    CDN (CloudFront)     │
                        │  Static Assets + SSL    │
                        └───────────┬────────────┘
                                    │
                        ┌───────────▼────────────┐
                        │   Nginx Reverse Proxy   │
                        │  Rate Limiting + SSL    │
                        │  Static File Serving    │
                        └───────────┬────────────┘
                                    │
                    ┌───────────────┼───────────────┐
                    ▼               ▼               ▼
            ┌──────────────┐ ┌──────────┐  ┌──────────────┐
            │  Frontend    │ │  API      │  │  Admin API   │
            │  (Static     │ │  Server   │  │  (restricted │
            │   HTML/CSS/  │ │  NestJS   │  │   endpoints) │
            │   JS)        │ │  :3000    │  │              │
            └──────────────┘ └─────┬────┘  └──────┬───────┘
                                   │              │
                    ┌──────────────┼──────────────┼──────┐
                    │              │              │      │
              ┌─────▼─────┐ ┌─────▼─────┐ ┌─────▼────┐ │
              │ PostgreSQL │ │   Redis   │ │  AWS S3  │ │
              │   :5432    │ │   :6379   │ │ / MinIO  │ │
              │            │ │           │ │          │ │
              │ • Users    │ │ • Sessions│ │ • Docs   │ │
              │ • Students │ │ • Cache   │ │ • Images │ │
              │ • Apps     │ │ • Rate    │ │ • Covers │ │
              │ • Partners │ │   Limits  │ │          │ │
              │ • CMS      │ │ • Queues  │ │          │ │
              │ • Audit    │ │           │ │          │ │
              └────────────┘ └───────────┘ └──────────┘ │
                                                        │
                    ┌───────────────────────────────────┘
                    ▼
              ┌────────────┐     ┌────────────┐
              │  SendGrid  │     │  Twilio /  │
              │  (Email)   │     │  SMS GW    │
              └────────────┘     └────────────┘
```

### 5.2 API Design Principles

1. **RESTful** — Resource-oriented URLs, proper HTTP verbs, standard status codes
2. **Versioned** — All endpoints prefixed with `/api/v1/`
3. **Paginated** — Cursor-based pagination for list endpoints
4. **Rate-limited** — Per-user and per-IP rate limiting via Redis
5. **Validated** — Input validation via class-validator DTOs with Prisma-safe sanitization
6. **Authenticated** — JWT access tokens (15 min) + refresh tokens (7 days) in httpOnly cookies
7. **Authorized** — Role-based guards + resource-level ownership checks
8. **Audited** — Every write operation logged to AuditLog
9. **Documented** — Auto-generated Swagger/OpenAPI at `/api/docs`

---

## 6. Phased Implementation Roadmap

### Phase Overview

```
Phase 0 ─── Foundation & DevSecOps Setup ────────── Week 1–2    ██░░░░░░░░░░░░░░
Phase 1 ─── Database & Core API Scaffold ─────────── Week 3–5    ████░░░░░░░░░░░░
Phase 2 ─── Authentication & Authorization ──────── Week 6–8    ██████░░░░░░░░░░
Phase 3 ─── Student Portal Backend ──────────────── Week 9–11   ████████░░░░░░░░
Phase 4 ─── Partner Portal Backend ──────────────── Week 12–14  ██████████░░░░░░
Phase 5 ─── Admin Panel & CMS Backend ──────────── Week 15–17  ████████████░░░░
Phase 6 ─── Integration & Advanced Features ─────── Week 18–20  ██████████████░░
Phase 7 ─── Security Hardening & Compliance ─────── Week 21–22  ███████████████░
Phase 8 ─── Production Deployment & Launch ──────── Week 23–25  ████████████████
```

**Total Estimated Duration: 25 weeks (~6 months)**

---

### Phase 0: Foundation & DevSecOps Infrastructure

**Duration:** Week 1–2  
**Goal:** Establish the project skeleton, development environment, and CI/CD pipeline before writing any business logic.

#### 0.1 Project Initialization
- [ ] Initialize NestJS project with TypeScript strict mode
- [ ] Configure `tsconfig.json` with strict, no-implicit-any, and path aliases
- [ ] Set up directory structure (modules, common, config, database)
- [ ] Configure environment variables with `@nestjs/config` and `.env` files
- [ ] Create `.env.example`, `.env.development`, `.env.test`, `.env.production`

#### 0.2 Docker & Local Development
- [ ] Create `Dockerfile` (multi-stage build: build → production)
- [ ] Create `docker-compose.yml` with services:
  - `api` (NestJS app)
  - `postgres` (PostgreSQL 16)
  - `redis` (Redis 7)
  - `minio` (MinIO for local S3-compatible storage)
- [ ] Create `docker-compose.test.yml` for isolated test environment
- [ ] Write `Makefile` / npm scripts for common operations

#### 0.3 Code Quality & Standards
- [ ] Configure ESLint with `@typescript-eslint` and security rules
- [ ] Configure Prettier with project code style
- [ ] Set up Husky pre-commit hooks (lint, format, type-check)
- [ ] Configure commitlint for conventional commits
- [ ] Create `.editorconfig` for cross-IDE consistency

#### 0.4 CI/CD Pipeline (GitHub Actions)
- [ ] **PR Pipeline**: Lint → Type-check → Unit tests → Build → Security scan
- [ ] **Main Pipeline**: Above + Integration tests → Docker build → Push to registry
- [ ] **Deploy Pipeline**: Pull image → Database migration → Rolling restart
- [ ] Configure branch protection rules (require PR, reviews, passing CI)

#### 0.5 Security Scanning Setup
- [ ] `npm audit` in CI for dependency vulnerabilities
- [ ] Snyk or GitHub Dependabot for automated dependency updates
- [ ] Trivy for Docker image CVE scanning
- [ ] SonarQube or CodeQL for SAST (Static Application Security Testing)
- [ ] Secret scanning (detect leaked API keys, passwords in code)

#### 0.6 Git Workflow
- [ ] Define branching strategy: `main` → `staging` → `feature/*` / `fix/*`
- [ ] Set up semantic versioning with `standard-version` or `semantic-release`
- [ ] Create PR template and issue templates

**Deliverables:**
- ✅ Running NestJS app in Docker with PostgreSQL, Redis, MinIO
- ✅ CI/CD pipeline running on every push
- ✅ Security scanning integrated
- ✅ Development environment documented and reproducible

---

### Phase 1: Database Design & Core API Scaffold

**Duration:** Week 3–5  
**Goal:** Implement the complete database schema, seed data migration from localStorage, and core API infrastructure.

#### 1.1 Prisma Setup & Schema
- [ ] Install and configure Prisma with PostgreSQL
- [ ] Implement full database schema (all tables from Section 4.2)
- [ ] Create initial migration: `prisma migrate dev --name init`
- [ ] Configure Prisma Studio for database browsing
- [ ] Set up connection pooling (PgBouncer or Prisma connection pool)

#### 1.2 Seed Data & Migration Scripts
- [ ] Create seed script to populate:
  - Default admin user
  - University database (1200+ entries from existing static data)
  - Country/region page content (migrated from localStorage SEED object)
  - Service blocks (9 blocks from store.js)
  - Default site settings
- [ ] Write data migration utility: `store.js SEED data → PostgreSQL`
- [ ] Implement demo data toggle for development/staging

#### 1.3 Core API Infrastructure
- [ ] Global exception filter (standardized error responses)
- [ ] Global validation pipe (`class-validator` + `class-transformer`)
- [ ] Request ID middleware (X-Request-ID header for tracing)
- [ ] Logging interceptor (structured JSON logging with Winston/Pino)
- [ ] Response transform interceptor (consistent API envelope)
- [ ] CORS configuration
- [ ] Helmet.js security headers

#### 1.4 API Documentation
- [ ] Configure `@nestjs/swagger` with OpenAPI 3.0
- [ ] Define global API envelope schema
- [ ] Set up Swagger UI at `/api/docs` (dev/staging only)
- [ ] Document authentication flows, error codes, pagination

#### 1.5 Health & Readiness Checks
- [ ] `GET /api/health` — Application liveness
- [ ] `GET /api/health/ready` — Database + Redis connectivity
- [ ] `GET /api/health/version` — App version and build info

**Deliverables:**
- ✅ Complete database schema deployed and tested
- ✅ Seed data populated from existing frontend data
- ✅ Core middleware stack operational
- ✅ Swagger API documentation live
- ✅ Health check endpoints returning green

---

### Phase 2: Authentication & Authorization

**Duration:** Week 6–8  
**Goal:** Implement production-grade authentication for all three portals (Student, Partner, Admin) with OTP verification, session management, and role-based access control.

#### 2.1 JWT Authentication Core
- [ ] Implement JWT strategy with Passport.js (`@nestjs/passport`)
- [ ] Access token: RS256 signed, 15-minute expiry, contains: `{ sub, role, email }`
- [ ] Refresh token: UUID stored in DB + httpOnly secure cookie, 7-day expiry
- [ ] Token refresh endpoint: `POST /api/v1/auth/refresh`
- [ ] Token revocation via Redis blacklist (for logout and password change)
- [ ] Implement sliding session window (refresh extends expiry)

#### 2.2 Student Authentication (`js/auth.js` integration)
- [ ] `POST /api/v1/auth/student/register` — Create account with email + password
  - Password strength validation (min 8 chars, mixed case, number, special)
  - Hash with bcrypt (12 rounds)
  - Send OTP email
- [ ] `POST /api/v1/auth/student/login` — Email + password sign-in
  - Rate limit: 5 attempts per 15 minutes per email
  - Account lockout after 10 failed attempts (30-minute cooldown)
- [ ] `POST /api/v1/auth/student/verify-otp` — 6-digit email OTP verification
  - OTP valid for 10 minutes (matches frontend countdown)
  - Max 3 verification attempts per OTP
- [ ] `POST /api/v1/auth/student/forgot-password` — Send password reset link
  - Rate limit: 3 requests per hour per email
  - Reset link valid for 30 minutes (matches frontend notice)
- [ ] `POST /api/v1/auth/student/reset-password` — Set new password with token
- [ ] `POST /api/v1/auth/student/resend-otp` — Resend verification code

#### 2.3 Partner Authentication (`js/partner-auth.js` integration)
- [ ] `POST /api/v1/auth/partner/register` — 3-step agency registration
  - Step 1: Agency details (name, registration, territory)
  - Step 2: Contact person details
  - Step 3: Account credentials + terms acceptance
  - Status: `PENDING_APPROVAL` (requires admin approval)
- [ ] `POST /api/v1/auth/partner/login` — Partner sign-in
- [ ] `POST /api/v1/auth/partner/verify-otp` — OTP verification
- [ ] `POST /api/v1/auth/partner/forgot-password` — Password reset flow

#### 2.4 Admin Authentication
- [ ] `POST /api/v1/auth/admin/login` — Admin sign-in with MFA
- [ ] Implement TOTP-based MFA (Google Authenticator compatible)
- [ ] Admin session: shorter expiry (1 hour), IP binding optional
- [ ] Admin actions require re-authentication for sensitive operations

#### 2.5 Role-Based Access Control (RBAC)
- [ ] Implement `@Roles()` decorator and `RolesGuard`
- [ ] Define permission matrix:

| Resource | Student | Partner | Admin | Super Admin |
|----------|---------|---------|-------|-------------|
| Own profile | CRUD | CRUD | CRUD | CRUD |
| Other students | ❌ | Read (own) | CRUD | CRUD |
| Applications | CRUD (own) | Read (own students) | CRUD | CRUD |
| Documents | CRUD (own) | Read (own students) | CRUD | CRUD |
| Universities | Read | Read | CRUD | CRUD |
| Blog/News/Events | Read | Read | CRUD | CRUD |
| Wallet | ❌ | Read (own) | Read | CRUD |
| Settings | ❌ | ❌ | CRUD | CRUD |
| Audit Logs | ❌ | ❌ | Read | CRUD |
| User Management | ❌ | ❌ | ❌ | CRUD |

#### 2.6 Session Security
- [ ] Redis-backed session store with automatic expiry
- [ ] Concurrent session limit (max 5 active sessions per user)
- [ ] Session invalidation on password change
- [ ] `DELETE /api/v1/auth/logout` — Invalidate current session
- [ ] `DELETE /api/v1/auth/logout-all` — Invalidate all sessions

#### 2.7 Rate Limiting & Brute Force Protection
- [ ] Global rate limit: 100 requests/minute per IP
- [ ] Auth endpoints: 10 requests/minute per IP
- [ ] OTP endpoints: 5 requests/minute per email
- [ ] Implement progressive delay on failed login attempts
- [ ] CAPTCHA integration trigger after 3 failed attempts (reCAPTCHA v3)

**Deliverables:**
- ✅ Complete auth flow for Student, Partner, and Admin portals
- ✅ JWT + refresh token rotation working
- ✅ OTP email delivery functional
- ✅ RBAC guards protecting all endpoints
- ✅ Rate limiting and brute force protection active
- ✅ Frontend `/* REAL REQUEST */` stubs replaced with actual API calls

---

### Phase 3: Student Portal Backend

**Duration:** Week 9–11  
**Goal:** Build all APIs required by `student-profile.html` and `student-tracking.html`.

#### 3.1 Student Profile CRUD
- [ ] `GET /api/v1/students/me` — Get current student profile (with completeness score)
- [ ] `PATCH /api/v1/students/me/personal` — Update personal details
- [ ] `PATCH /api/v1/students/me/address` — Update address
- [ ] `POST /api/v1/students/me/academics` — Add academic record
- [ ] `PUT /api/v1/students/me/academics/:id` — Update academic record
- [ ] `DELETE /api/v1/students/me/academics/:id` — Remove academic record
- [ ] `POST /api/v1/students/me/test-scores` — Add test score
- [ ] `PUT /api/v1/students/me/test-scores/:id` — Update test score
- [ ] `DELETE /api/v1/students/me/test-scores/:id` — Remove test score
- [ ] `PUT /api/v1/students/me/preferences` — Update study preferences
- [ ] Implement server-side completeness calculation (matching frontend 0-100% logic)

#### 3.2 Document Management
- [ ] `POST /api/v1/students/me/documents` — Upload document to S3
  - Virus scan via ClamAV before accepting
  - File type validation (PDF, JPG, PNG only)
  - Max file size: 10MB per document
  - Generate pre-signed upload URL for direct-to-S3 upload
- [ ] `GET /api/v1/students/me/documents` — List all documents with status
- [ ] `GET /api/v1/students/me/documents/:id/download` — Pre-signed download URL
- [ ] `DELETE /api/v1/students/me/documents/:id` — Remove document
- [ ] Admin endpoint: `PATCH /api/v1/admin/documents/:id/verify` — Approve/reject document

#### 3.3 Application Tracking
- [ ] `GET /api/v1/students/me/applications` — List all applications with filters
- [ ] `GET /api/v1/students/me/applications/:id` — Application detail with journey, timeline, actions
- [ ] `POST /api/v1/students/me/applications` — Submit new application
- [ ] `GET /api/v1/students/me/applications/:id/journey` — Journey step progress
- [ ] `GET /api/v1/students/me/applications/:id/timeline` — Activity timeline
- [ ] `GET /api/v1/students/me/actions` — All pending action items across applications

#### 3.4 University & Program Search
- [ ] `GET /api/v1/universities` — Search with filters (country, level, field)
- [ ] `GET /api/v1/universities/:id` — University detail with programs
- [ ] `GET /api/v1/universities/:id/programs` — Programs list with filters
- [ ] `GET /api/v1/programs/search` — Cross-university program search
- [ ] Implement PostgreSQL full-text search with `ts_vector` indexes

#### 3.5 Frontend Integration
- [ ] Replace `localStorage` reads in `js/student-portal.js` with API calls
- [ ] Implement API client wrapper with JWT token management
- [ ] Handle offline/error states gracefully
- [ ] Maintain caret-position input filtering on frontend (no backend change needed)

**Deliverables:**
- ✅ Complete student profile CRUD with server-side persistence
- ✅ Secure document upload/download via S3 pre-signed URLs
- ✅ Application tracking with journey steps, timeline, and action items
- ✅ University search with full-text search
- ✅ Frontend migrated from localStorage to API

---

### Phase 4: Partner Portal Backend

**Duration:** Week 12–14  
**Goal:** Build all APIs required by the 11 partner console pages (`partner-*.html`).

#### 4.1 Partner Dashboard
- [ ] `GET /api/v1/partners/me/dashboard` — Dashboard summary (student count, app count, wallet balance, recent activity)
- [ ] `GET /api/v1/partners/me` — Partner profile details
- [ ] `PATCH /api/v1/partners/me` — Update partner profile

#### 4.2 Student Management (Partner's Students)
- [ ] `GET /api/v1/partners/me/students` — List partner's referred students (paginated, filterable)
- [ ] `POST /api/v1/partners/me/students` — Register new student (from portal.js "Register New Student" modal)
- [ ] `GET /api/v1/partners/me/students/:id` — Student detail (limited view per RBAC)

#### 4.3 Enquiry Management
- [ ] `GET /api/v1/partners/me/enquiries` — List partner's enquiries
- [ ] `POST /api/v1/partners/me/enquiries` — Submit new enquiry
- [ ] `PATCH /api/v1/partners/me/enquiries/:id` — Update enquiry status

#### 4.4 Application Pipeline
- [ ] `GET /api/v1/partners/me/applications` — All applications from partner's students
- [ ] `POST /api/v1/partners/me/program-requests` — Request program options (from portal.js modal)

#### 4.5 Wallet & Commission
- [ ] `GET /api/v1/partners/me/wallet` — Wallet summary (balance, pending, total earned)
- [ ] `GET /api/v1/partners/me/wallet/transactions` — Transaction history (paginated, filterable)
- [ ] Admin: `POST /api/v1/admin/wallet/credit` — Credit commission to partner
- [ ] Admin: `POST /api/v1/admin/wallet/payout` — Process payout to partner

#### 4.6 Resources & Documents
- [ ] `GET /api/v1/partners/me/resources` — Learning documents and materials
- [ ] `GET /api/v1/partners/me/resources/:id/download` — Download resource file

#### 4.7 Notifications & Updates
- [ ] `GET /api/v1/partners/me/notifications` — In-app notifications (paginated)
- [ ] `PATCH /api/v1/partners/me/notifications/:id/read` — Mark as read
- [ ] `GET /api/v1/partners/me/email-updates` — Email update history
- [ ] `GET /api/v1/partners/me/updates` — Important updates / announcements

#### 4.8 Interview Scheduling (partner-interview.html)
- [ ] `GET /api/v1/partners/me/interviews` — Upcoming interview slots
- [ ] `POST /api/v1/partners/me/interviews` — Schedule/request an interview

#### 4.9 Program Search (partner-search.html)
- [ ] `GET /api/v1/partners/programs/search` — Advanced program search with partner-specific filters

**Deliverables:**
- ✅ Complete partner dashboard with real data
- ✅ Student management under partner umbrella
- ✅ Wallet with transaction history
- ✅ Resource library with secure downloads
- ✅ Notification system functional

---

### Phase 5: Admin Panel & CMS Backend

**Duration:** Week 15–17  
**Goal:** Build all APIs required by `admin.html` to replace the localStorage-based CMS.

#### 5.1 Content Management APIs
- [ ] **Events CRUD**: `GET/POST/PUT/DELETE /api/v1/admin/events`
- [ ] **Blog Posts CRUD**: `GET/POST/PUT/DELETE /api/v1/admin/blogs`
- [ ] **News Items CRUD**: `GET/POST/PUT/DELETE /api/v1/admin/news`
- [ ] **Gallery Photos**: `GET/POST/DELETE /api/v1/admin/gallery`
- [ ] All CRUD endpoints support bulk operations and soft-delete

#### 5.2 Page Content Management
- [ ] **Country Pages**: `GET/PUT /api/v1/admin/pages/countries/:code`
- [ ] **Region Pages**: `GET/PUT /api/v1/admin/pages/regions/:code`
- [ ] **Services Page**: `GET/PUT /api/v1/admin/pages/services`
- [ ] **Partner Page**: `GET/PUT /api/v1/admin/pages/partner`
- [ ] **Page Visibility**: `GET/PUT /api/v1/admin/pages/visibility`

#### 5.3 Partner Console Management
- [ ] **Regional Managers**: `GET/POST/PUT/DELETE /api/v1/admin/partner-console/managers`
- [ ] **Important Updates**: `GET/POST/PUT/DELETE /api/v1/admin/partner-console/updates`
- [ ] **Quick Links**: `GET/POST/PUT/DELETE /api/v1/admin/partner-console/quicklinks`
- [ ] **Learning Documents**: `GET/POST/PUT/DELETE /api/v1/admin/partner-console/docs`
- [ ] **Email Updates**: `GET/POST/PUT/DELETE /api/v1/admin/partner-console/emails`
- [ ] **Notifications**: `GET/POST/PUT/DELETE /api/v1/admin/partner-console/notifications`
- [ ] **Console Text**: `GET/PUT /api/v1/admin/partner-console/text`

#### 5.4 Media Management
- [ ] `POST /api/v1/admin/media/upload` — Upload image with auto-resize (1400px JPEG)
- [ ] `GET /api/v1/admin/media/slots` — List all media slots
- [ ] `PUT /api/v1/admin/media/slots/:name` — Assign image to slot
- [ ] `DELETE /api/v1/admin/media/:id` — Remove image from storage

#### 5.5 Site Settings
- [ ] `GET /api/v1/admin/settings` — Get current site settings
- [ ] `PUT /api/v1/admin/settings` — Update site settings

#### 5.6 University Management
- [ ] `GET/POST/PUT/DELETE /api/v1/admin/universities`
- [ ] `GET/POST/PUT/DELETE /api/v1/admin/universities/:id/programs`
- [ ] Bulk import: `POST /api/v1/admin/universities/import` (CSV/Excel upload)

#### 5.7 Backup & Restore
- [ ] `GET /api/v1/admin/backup/export` — Full JSON export (matches current `VFI.exportAll()`)
- [ ] `POST /api/v1/admin/backup/import` — Full JSON import (matches current `VFI.importAll()`)
- [ ] Automated daily backups to S3 (PostgreSQL `pg_dump`)

#### 5.8 User & Partner Management
- [ ] `GET /api/v1/admin/users` — List all users (paginated, filterable by role/status)
- [ ] `PATCH /api/v1/admin/users/:id/status` — Activate/suspend/deactivate user
- [ ] `GET /api/v1/admin/partners` — List all partners (with approval queue)
- [ ] `PATCH /api/v1/admin/partners/:id/approve` — Approve/reject partner application

#### 5.9 Frontend Migration
- [ ] Replace `window.VFI` store calls in `js/admin.js` with API calls
- [ ] Maintain existing admin UI (no visual changes)
- [ ] Add authentication gate to `admin.html`

**Deliverables:**
- ✅ Complete CMS backend replacing localStorage
- ✅ Media management with S3 storage
- ✅ User and partner management
- ✅ Automated backups
- ✅ Admin panel protected with authentication + MFA

---

### Phase 6: Integration & Advanced Features

**Duration:** Week 18–20  
**Goal:** Integrate external services, implement advanced features, and optimize performance.

#### 6.1 Email Service Integration
- [ ] Configure SendGrid / AWS SES transporter
- [ ] Create email templates:
  - Welcome email (post-registration)
  - OTP verification code
  - Password reset link
  - Application status update
  - Document verification notification
  - Partner approval notification
  - Partner commission credit notification
- [ ] Implement email queue via BullMQ (retry on failure, dead letter queue)
- [ ] Email delivery tracking and bounce handling

#### 6.2 SMS OTP Service
- [ ] Integrate Twilio / local BD SMS gateway
- [ ] Phone number verification flow
- [ ] SMS rate limiting (max 3 OTPs per phone per hour)
- [ ] Fallback: WhatsApp Business API for OTP delivery

#### 6.3 Notification Engine
- [ ] Real-time in-app notifications via WebSocket (NestJS Gateway)
- [ ] Notification preferences (per-user opt-in/opt-out)
- [ ] Push notification support (future: Firebase Cloud Messaging)
- [ ] Notification aggregation (batch similar notifications)

#### 6.4 Public API for Frontend Rendering
- [ ] `GET /api/v1/public/events` — Published events (for events.html)
- [ ] `GET /api/v1/public/blogs` — Published blogs (for blogs.html, blog-post.html)
- [ ] `GET /api/v1/public/news` — Published news (for news.html)
- [ ] `GET /api/v1/public/gallery` — Published photos (for gallery.html)
- [ ] `GET /api/v1/public/settings` — Public site settings
- [ ] `GET /api/v1/public/pages/:slug` — Page content by slug
- [ ] `GET /api/v1/public/media/:slot` — Media slot image URL
- [ ] Replace `js/render.js` localStorage reads with these API calls

#### 6.5 Contact Form & Enquiry Processing
- [ ] `POST /api/v1/public/enquiries` — Submit contact form (`#cform`)
  - Honeypot field for bot detection
  - reCAPTCHA v3 verification
  - Auto-assign to available counsellor (round-robin)
  - Send confirmation email to enquirer
  - Send notification to assigned counsellor

#### 6.6 Search Enhancement
- [ ] Implement Elasticsearch if PostgreSQL FTS performance is insufficient
- [ ] Autocomplete/suggestion API for university and program search
- [ ] Search analytics (track popular queries, zero-result queries)

#### 6.7 Reporting & Analytics
- [ ] `GET /api/v1/admin/reports/applications` — Application pipeline report
- [ ] `GET /api/v1/admin/reports/enquiries` — Enquiry conversion report
- [ ] `GET /api/v1/admin/reports/partners` — Partner performance report
- [ ] `GET /api/v1/admin/reports/revenue` — Commission & revenue report
- [ ] Export reports as CSV/PDF

**Deliverables:**
- ✅ Email and SMS services operational
- ✅ Real-time notifications via WebSocket
- ✅ Public CMS API replacing localStorage rendering
- ✅ Contact form submitting to backend with lead routing
- ✅ Admin reporting dashboard

---

### Phase 7: Security Hardening & Compliance

**Duration:** Week 21–22  
**Goal:** Comprehensive security audit, penetration testing, and compliance implementation.

#### 7.1 OWASP Top 10 Audit & Remediation

| # | Vulnerability | Mitigation |
|---|---------------|------------|
| A01 | Broken Access Control | RBAC guards, resource ownership checks, RLS in PostgreSQL |
| A02 | Cryptographic Failures | bcrypt for passwords, AES-256 for PII at rest, TLS 1.3 in transit |
| A03 | Injection | Prisma parameterized queries, input validation pipes, SQL injection testing |
| A04 | Insecure Design | Threat modeling review, security architecture review |
| A05 | Security Misconfiguration | Helmet.js headers, disable debug in prod, secure cookie flags |
| A06 | Vulnerable Components | Automated dependency scanning, Snyk/Dependabot |
| A07 | Auth Failures | Rate limiting, MFA for admin, account lockout, session management |
| A08 | Software Integrity | Signed Docker images, CI/CD pipeline integrity, SRI for static assets |
| A09 | Logging Failures | Structured audit logging, log tamper protection, SIEM integration |
| A10 | SSRF | URL validation, allowlist for external requests, network segmentation |

#### 7.2 Data Protection & Encryption
- [ ] Encrypt PII at rest (student names, emails, phone numbers, addresses)
- [ ] Database-level encryption (PostgreSQL TDE or application-level AES-256)
- [ ] S3 server-side encryption (SSE-S3 or SSE-KMS) for all documents
- [ ] TLS 1.3 enforced for all connections
- [ ] Key management via AWS KMS or HashiCorp Vault

#### 7.3 GDPR / Data Privacy Compliance
- [ ] Data Processing Agreement (DPA) documentation
- [ ] Right to Access: `GET /api/v1/students/me/data-export` (full profile export)
- [ ] Right to Erasure: `DELETE /api/v1/students/me/account` (account deletion with data purge)
- [ ] Right to Rectification: Profile update endpoints (already implemented)
- [ ] Consent management: Track consent for marketing emails, data sharing with universities
- [ ] Data retention policy: Auto-archive inactive profiles after 3 years
- [ ] Cookie consent banner integration

#### 7.4 Penetration Testing
- [ ] Automated scanning with OWASP ZAP
- [ ] Manual penetration testing (auth bypass, privilege escalation, IDOR)
- [ ] API fuzzing with Burp Suite
- [ ] Document findings and remediation plan

#### 7.5 Audit Logging Enhancement
- [ ] Log all authentication events (login, logout, failed attempts, password changes)
- [ ] Log all data access events (profile views, document downloads)
- [ ] Log all data modification events (profile updates, application submissions)
- [ ] Log all admin actions (user management, content changes, settings updates)
- [ ] Implement log integrity (append-only, tamper-evident)
- [ ] Configure log retention (90 days hot, 1 year cold, 7 years archive for financial)

**Deliverables:**
- ✅ OWASP Top 10 audit complete with remediations
- ✅ PII encryption at rest and in transit
- ✅ GDPR compliance features implemented
- ✅ Penetration test report with zero critical/high findings
- ✅ Comprehensive audit logging active

---

### Phase 8: Production Deployment & Launch

**Duration:** Week 23–25  
**Goal:** Deploy to production, configure monitoring, and execute launch checklist.

#### 8.1 Infrastructure Provisioning
- [ ] Provision cloud infrastructure (AWS or DigitalOcean):
  - Application server: 2× t3.medium (or DO Droplet 4GB)
  - Database: RDS PostgreSQL or DO Managed Database (2GB RAM, 50GB SSD)
  - Redis: ElastiCache or DO Managed Redis
  - S3 bucket with lifecycle policies
  - Load balancer with SSL termination
- [ ] Configure auto-scaling policies
- [ ] Set up staging environment (mirror of production, smaller instances)

#### 8.2 Database Operations
- [ ] Run production migrations
- [ ] Seed production data (universities, countries, site settings)
- [ ] Configure automated daily backups (pg_dump → S3, 30-day retention)
- [ ] Test backup restoration procedure
- [ ] Set up read replicas (if needed for reporting queries)

#### 8.3 Performance Optimization
- [ ] Load testing with k6 or Artillery:
  - Target: 500 concurrent users, <200ms p95 response time
  - Test critical paths: login, profile load, university search, application submit
- [ ] Implement Redis caching layer:
  - University list: 1-hour TTL
  - Public CMS content: 5-minute TTL
  - Site settings: 10-minute TTL
- [ ] Database query optimization:
  - Add indexes on frequently queried columns
  - Analyze slow queries with `EXPLAIN ANALYZE`
  - Implement query result caching for expensive joins
- [ ] Enable gzip/Brotli compression in Nginx
- [ ] Configure CDN for static assets (CloudFront or Cloudflare)

#### 8.4 Monitoring & Observability
- [ ] Application Performance Monitoring (APM):
  - Prometheus metrics exporter
  - Grafana dashboards (request rate, error rate, response time, DB connections)
- [ ] Error tracking: Sentry integration
- [ ] Uptime monitoring: UptimeRobot or Better Uptime
- [ ] Database monitoring: pgMonitor or CloudWatch RDS metrics
- [ ] Alerting rules:
  - Error rate > 1% → Slack alert
  - Response time p95 > 500ms → Slack alert
  - Database connections > 80% pool → Slack alert
  - Disk space > 80% → Slack alert
  - Failed login spike (>50 in 5 min) → Security alert

#### 8.5 SSL & Domain Configuration
- [ ] Obtain SSL certificate (Let's Encrypt or AWS ACM)
- [ ] Configure Nginx for SSL termination
- [ ] Redirect HTTP → HTTPS
- [ ] Set up HSTS header
- [ ] Configure DNS records (A, CNAME, MX for email)

#### 8.6 Launch Checklist

| # | Item | Status |
|---|------|--------|
| 1 | All CI/CD pipelines green | ☐ |
| 2 | All unit tests passing (>80% coverage) | ☐ |
| 3 | Integration tests passing | ☐ |
| 4 | Security scan: zero critical/high findings | ☐ |
| 5 | Load test: passes 500 concurrent users | ☐ |
| 6 | Database backup tested (restore verified) | ☐ |
| 7 | SSL/TLS configured and verified | ☐ |
| 8 | Monitoring & alerting active | ☐ |
| 9 | Error tracking (Sentry) configured | ☐ |
| 10 | Environment variables secured (no secrets in code) | ☐ |
| 11 | CORS configured for production domain only | ☐ |
| 12 | Rate limiting active on all endpoints | ☐ |
| 13 | Admin MFA enforced | ☐ |
| 14 | Audit logging verified | ☐ |
| 15 | Rollback procedure documented and tested | ☐ |
| 16 | On-call rotation established | ☐ |
| 17 | Incident response playbook created | ☐ |
| 18 | Data migration from localStorage verified | ☐ |

**Deliverables:**
- ✅ Production environment live and serving traffic
- ✅ Monitoring and alerting operational
- ✅ Automated backups running
- ✅ Launch checklist 100% complete
- ✅ Rollback procedure tested

---

## 7. Security Architecture & Compliance

### 7.1 Defense-in-Depth Layers

```
Layer 1: Network          → Firewall rules, VPC, security groups
Layer 2: Transport         → TLS 1.3, HSTS, certificate pinning
Layer 3: Application Edge  → Nginx rate limiting, WAF, DDoS protection
Layer 4: API Gateway       → Helmet.js, CORS, request validation
Layer 5: Authentication    → JWT + refresh tokens, MFA, account lockout
Layer 6: Authorization     → RBAC guards, resource ownership checks
Layer 7: Data Access       → Prisma parameterized queries, PostgreSQL RLS
Layer 8: Data Storage      → Encryption at rest (AES-256), S3 SSE
Layer 9: Audit             → Comprehensive logging, tamper-evident logs
Layer 10: Monitoring       → Anomaly detection, security alerts
```

### 7.2 Sensitive Data Classification

| Classification | Data Examples | Storage | Access |
|---------------|---------------|---------|--------|
| **CRITICAL** | Passwords, MFA secrets, API keys | Hashed/encrypted, never logged | System only |
| **HIGH** | Passport scans, visa documents, financial records | Encrypted S3, audit-logged access | Owner + Admin |
| **MEDIUM** | Email, phone, DOB, address | Encrypted database columns | Owner + assigned staff |
| **LOW** | Study preferences, university shortlist | Standard database | Owner + partner + admin |
| **PUBLIC** | Blog posts, events, news, university listings | Standard database | Anyone |

### 7.3 Security Headers (Helmet.js Configuration)

```
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
X-XSS-Protection: 0  (rely on CSP instead)
Strict-Transport-Security: max-age=63072000; includeSubDomains; preload
Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' fonts.googleapis.com; font-src fonts.gstatic.com; img-src 'self' data: blob: *.amazonaws.com; connect-src 'self' api.yourdomain.com
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: camera=(), microphone=(), geolocation=()
```

---

## 8. DevOps & CI/CD Pipeline

### 8.1 Pipeline Architecture

```
┌──────────┐    ┌──────────────┐    ┌──────────────┐    ┌─────────────┐
│  Dev Push │───→│  PR Pipeline  │───→│ Main Pipeline │───→│ Deploy      │
│           │    │              │    │              │    │ Pipeline    │
│ feature/* │    │ • Lint       │    │ • All PR     │    │             │
│ fix/*     │    │ • Type-check │    │   checks     │    │ • Staging   │
│           │    │ • Unit tests │    │ • Integration│    │ • Prod      │
│           │    │ • Build      │    │   tests      │    │   (manual)  │
│           │    │ • SAST scan  │    │ • Docker     │    │             │
│           │    │ • Dep audit  │    │   build      │    │             │
│           │    │              │    │ • Push image │    │             │
└──────────┘    └──────────────┘    └──────────────┘    └─────────────┘
```

### 8.2 Environment Strategy

| Environment | Purpose | Database | Deployment |
|-------------|---------|----------|------------|
| **Local** | Development | Docker PostgreSQL | `docker-compose up` |
| **Test** | CI/CD automated tests | Docker PostgreSQL (ephemeral) | GitHub Actions |
| **Staging** | QA, demo, UAT | Managed PostgreSQL (shared) | Auto-deploy on `staging` merge |
| **Production** | Live users | Managed PostgreSQL (dedicated) | Manual approval gate |

---

## 9. Testing Strategy

### 9.1 Testing Pyramid

```
                    ╱╲
                   ╱  ╲         E2E Tests (Playwright)
                  ╱    ╲        — 5-10 critical user flows
                 ╱──────╲
                ╱        ╲      Integration Tests (Supertest)
               ╱          ╲     — API endpoint testing with test DB
              ╱────────────╲
             ╱              ╲   Unit Tests (Jest)
            ╱                ╲  — Services, guards, pipes, validators
           ╱──────────────────╲ — Target: 80%+ code coverage
```

### 9.2 Test Categories

| Category | Tool | Scope | Run Frequency |
|----------|------|-------|---------------|
| Unit Tests | Jest | Services, guards, pipes, DTOs | Every commit |
| Integration Tests | Jest + Supertest | API endpoints with test DB | Every PR |
| E2E Tests | Playwright | Critical user flows (register, login, apply) | Pre-deploy |
| Security Tests | OWASP ZAP | API vulnerability scanning | Weekly + pre-deploy |
| Load Tests | k6 / Artillery | Performance under load | Pre-release |
| Contract Tests | Pact (optional) | API contract validation | Pre-release |

---

## 10. Monitoring, Logging & Observability

### 10.1 Three Pillars of Observability

| Pillar | Tool | Purpose |
|--------|------|---------|
| **Metrics** | Prometheus + Grafana | Request rates, error rates, latency percentiles, resource utilization |
| **Logs** | Winston/Pino → CloudWatch or ELK Stack | Structured JSON logs, request tracing, audit trail |
| **Traces** | OpenTelemetry (optional Phase 6+) | Distributed tracing for debugging slow requests |

### 10.2 Key Metrics to Monitor

| Metric | Warning Threshold | Critical Threshold |
|--------|-------------------|-------------------|
| API Error Rate (5xx) | > 0.5% | > 2% |
| API Response Time (p95) | > 300ms | > 1000ms |
| Database Query Time (p95) | > 100ms | > 500ms |
| Database Connection Pool Usage | > 70% | > 90% |
| Redis Memory Usage | > 70% | > 90% |
| Disk Usage | > 70% | > 85% |
| Failed Login Rate | > 20/min | > 50/min |
| CPU Usage | > 70% | > 90% |
| Memory Usage | > 75% | > 90% |

---

## 11. Risk Register & Mitigation

| # | Risk | Probability | Impact | Mitigation |
|---|------|-------------|--------|------------|
| R1 | Data loss during localStorage→DB migration | Medium | Critical | Parallel running period; keep localStorage as fallback for 30 days |
| R2 | Authentication system compromise | Low | Critical | MFA, rate limiting, account lockout, security audit, pen testing |
| R3 | Document storage breach (student PII) | Low | Critical | S3 encryption, pre-signed URLs (time-limited), audit logging |
| R4 | Database scaling bottleneck | Medium | High | Connection pooling, read replicas, query optimization, caching |
| R5 | Third-party service outage (SendGrid, Twilio) | Medium | Medium | Fallback providers (SES, local SMS gateway), queue retry logic |
| R6 | Team skill gap (TypeScript/NestJS) | Medium | Medium | Training sprint in Phase 0, pair programming, code reviews |
| R7 | Scope creep delaying launch | High | High | Strict phase gates, MVP mindset, defer non-critical features |
| R8 | GDPR non-compliance fine | Low | Critical | Legal review, DPO appointment, privacy impact assessment |
| R9 | Production deployment failure | Medium | High | Blue-green deployment, automated rollback, staging validation |
| R10 | Cost overrun on cloud infrastructure | Medium | Medium | Start small, monitor usage, reserved instances, auto-scaling limits |

---

## 12. Appendices

### Appendix A: Frontend Integration Mapping

This table maps every frontend JavaScript file to the backend modules/APIs it will integrate with:

| Frontend File | Current Data Source | Backend Module | Migration Phase |
|---------------|-------------------|----------------|-----------------|
| `js/auth.js` | `/* REAL REQUEST */` stubs | `auth` module | Phase 2 |
| `js/partner-auth.js` | `/* REAL REQUEST */` stubs | `auth` module | Phase 2 |
| `js/student-portal.js` | `localStorage (vfi_student_profile)` | `students`, `applications`, `documents` | Phase 3 |
| `js/portal.js` | `/* REAL REQUEST */` stubs | `partners`, `students` | Phase 4 |
| `js/portal-render.js` | `window.VFI` (localStorage) | `partners` (GET endpoints) | Phase 4 |
| `js/admin.js` | `window.VFI` (localStorage + IndexedDB) | `admin` (all CRUD endpoints) | Phase 5 |
| `js/render.js` | `window.VFI` (localStorage) | `public` (GET endpoints) | Phase 6 |
| `js/store.js` | localStorage + IndexedDB | **Replaced by API client** | Phase 6 |
| `js/site.js` | `VFI.pageEnabled()` | `public/settings` | Phase 6 |
| `js/main.js` | No data layer | **No change needed** | N/A |

### Appendix B: API Route Summary

```
/api/v1/
├── auth/
│   ├── student/   (register, login, verify-otp, forgot-password, reset-password)
│   ├── partner/   (register, login, verify-otp, forgot-password)
│   ├── admin/     (login, mfa-setup, mfa-verify)
│   ├── refresh    (token refresh)
│   ├── logout     (current session)
│   └── logout-all (all sessions)
├── students/
│   └── me/        (profile, academics, test-scores, preferences, documents, applications, actions)
├── partners/
│   └── me/        (profile, dashboard, students, enquiries, applications, wallet, resources, notifications, interviews)
├── universities/  (list, detail, programs, search)
├── programs/      (search)
├── admin/
│   ├── users/     (list, status management)
│   ├── partners/  (list, approval)
│   ├── events/    (CRUD)
│   ├── blogs/     (CRUD)
│   ├── news/      (CRUD)
│   ├── gallery/   (CRUD)
│   ├── media/     (upload, slots)
│   ├── pages/     (countries, regions, services, partner, visibility)
│   ├── partner-console/ (managers, updates, quicklinks, docs, emails, notifs, text)
│   ├── universities/ (CRUD, programs, bulk import)
│   ├── wallet/    (credit, payout)
│   ├── documents/ (verify, reject)
│   ├── settings/  (get, update)
│   ├── backup/    (export, import)
│   └── reports/   (applications, enquiries, partners, revenue)
├── public/
│   ├── events/    (published events)
│   ├── blogs/     (published blogs)
│   ├── news/      (published news)
│   ├── gallery/   (published photos)
│   ├── settings/  (public site settings)
│   ├── pages/     (page content by slug)
│   ├── media/     (media slot image URL)
│   └── enquiries/ (contact form submission)
└── health/        (liveness, readiness, version)
```

### Appendix C: Estimated Resource Requirements

| Resource | Staging | Production (Year 1) | Production (Year 3) |
|----------|---------|---------------------|---------------------|
| **API Server** | 1× 2GB RAM | 2× 4GB RAM (load balanced) | 4× 4GB RAM (auto-scaled) |
| **PostgreSQL** | 2GB RAM, 20GB SSD | 4GB RAM, 100GB SSD | 8GB RAM, 500GB SSD + read replica |
| **Redis** | 1GB RAM | 2GB RAM | 4GB RAM (cluster) |
| **S3 Storage** | ~5GB | ~100GB | ~1TB |
| **Monthly Cost (est.)** | ~$50 | ~$200-350 | ~$500-800 |

### Appendix D: Team Composition Recommendation

| Role | Count | Phase Involvement |
|------|-------|-------------------|
| **Backend Lead / Architect** | 1 | All phases |
| **Backend Developer** | 2 | Phase 1–6 |
| **Frontend Developer** (API integration) | 1 | Phase 2–6 |
| **DevOps Engineer** | 1 | Phase 0, 7, 8 |
| **QA Engineer** | 1 | Phase 2–8 |
| **Security Specialist** (part-time/consultant) | 1 | Phase 0, 7 |

---

> **Next Step:** Upon approval of this plan, proceed to **Phase 0: Foundation & DevSecOps Infrastructure** — project initialization, Docker setup, and CI/CD pipeline configuration.

---

*Document prepared by DevSecOps Engineering. All recommendations are based on thorough analysis of the existing VFI_website codebase (60+ HTML pages, 10 JS modules, 6 CSS stylesheets, ~710 KB combined frontend code), industry best practices, OWASP security guidelines, and 15 years of production systems experience.*

---

## 13. Addendum — VFI Partner Portal: Focused Capability Assessment & Gap Analysis

Scope: **the VFI Partner Portal only** — partner auth (`vfi-partner-login/-forgot/-verify.html` + `js/partner-auth.js`), the 11 `partner-*.html` console pages (`js/portal.js`, `js/portal-render.js`), and the two global modals. This addendum assesses the partner portal against the plan above and records where **this plan file has gaps** for that surface. The stack decisions in §3 stand.

### 13.1 Capability assessment (partner portal)

| # | Capability | Portal needs | Covered by plan §4/§5? | Risk |
|---|---|---|---|---|
| C1 | Agency identity + **multi-user tenancy** | Agency account with several counsellors, each a login; every read/write scoped to that agency | Partial — auth yes; multi-user + isolation **not** operationalised | Critical |
| C2 | Students (per agency) | CRUD, plus QR self-registration into the agency | Partial — CRUD yes; **QR referral missing** | Med |
| C3 | Applications + 8 dashboard KPIs | Pipeline whose statuses feed All/Offers/Payments/Visa-Received/Visa-Rejected/Non-Enrolment/Deferrals/Pending-from-Partner | Partial — list endpoint yes; **KPI taxonomy misaligned** | Med |
| C4 | Program search (100k+, faceted) | Multi-facet filter (level/country/study-area/requirements/quick-filters) over a large catalogue | Weak — one endpoint line, **no data source, no facet/index design** | High |
| C5 | Wallet (funded + spendable) | Partner **tops up** and **spends** on application fees; ledger; balance | Wrong direction — plan models **commission earnings**, not a funded wallet | Critical |
| C6 | Payments / funding | How money enters the wallet + "Pay via Flywire" | **Absent** — no PSP, idempotency, reconciliation, PCI stance | Critical |
| C7 | Documents (enquiry uploads) | "Request Program Options" academic-doc upload; secure storage | Covered — S3 + pre-signed URLs | High (sensitive) |
| C8 | Learning resources | Admin-published docs by country/category, partner downloads | Partial — admin CRUD listed; **no resource entity in the model** | Low |
| C9 | Notifications / email updates | Per-agency feed + broadcast list | Covered | Low |
| C10 | Benefit tiers + quotas | Tier (STARTER→Growth), quotas (assistant msgs, autofills, AI interviews), "recruit 6 to unlock" | **Absent** — `tierName` string only, no quota metering | Med |
| C11 | AI assistant + AI mock interview | Chat + AI interview practice with quota metering + external LLM | **Mismodelled** — plan treats it as interview *scheduling* | Med (+privacy) |

### 13.2 Gaps in THIS plan (partner portal) — with fixes

| ID | Gap | Plan today | Fix | Severity |
|---|---|---|---|---|
| **PG-1** | **Tenancy is a principle, not a design.** RLS/RBAC are named (§3.2, §7) but there is no `agency_id` scoping guard, no RLS policy example, and no negative isolation test. This is THE partner-portal control. | "Partners must only see their own students" (prose) | Add `agency_id` FK to every partner-owned table; a `TenantGuard` that injects the caller's `agency_id` into every query; Postgres RLS policies as defence-in-depth; and a **CI test proving agency A gets 403 on agency B's rows**. | Critical |
| **PG-2** | **Agency modelled as a single user.** `Partner.userId @unique` = one login per agency; real agencies have multiple counsellors sharing one tenant's data. | 1:1 Partner↔User | Introduce `AgencyUser(agency_id, role: owner\|counsellor)`; the **tenant is the agency**, not the user; dashboard/students belong to the agency and are visible to all its counsellors. | High |
| **PG-3** | **Wallet direction is backwards.** `TransactionType` = COMMISSION_CREDIT/DEBIT/PAYOUT — the agency *earns*. The portal wallet is *funded by the partner and spent on application fees* ("Add money … for instant Application Fee payments"). | Commission ledger only | Add `TOPUP` (funding) and `APPLICATION_FEE_DEBIT`; model a spendable balance; keep commission as a separate ledger if needed. | Critical |
| **PG-4** | **No payment/funding path.** Nothing on how money enters the wallet or how "Pay via Flywire" tuition works. | — | PSP **hosted checkout** (bKash/Nagad/SSLCommerz for local top-up; Flywire for tuition); **idempotency key** on every credit; reconciliation job; **stay out of PCI scope** (never touch card data). | Critical |
| **PG-5** | **Program search under-designed + no data source.** One endpoint; "PostgreSQL FTS → Elasticsearch" — but faceted filtering ≠ full-text, and **nowhere says where the 100k+ programs come from**. | 1 line + FTS hand-wave | Define the **catalogue source/ingestion** (blocker — client input needed); design facet indexes; use Meilisearch/OpenSearch for faceted search if Postgres GIN/trigram is insufficient. | High + Blocker |
| **PG-6** | **QR referral registration missing.** The dashboard's "Student Registration QR / Copy Link" lets a student self-register *into that partner's account*; unmodelled. | — | `referral_code` on agency; public `GET /r/:code` + `POST /r/:code/register` that attributes the new student to the agency. | Med |
| **PG-7** | **Benefit tiers & quotas missing.** Tier progression and quotas (100 assistant msgs/mo, 20 autofills/yr, 10 AI interviews/yr, "recruit 6 to unlock Growth") are UI copy with no backing. | `tierName` string | `benefit_tiers(quotas JSONB)` + `quota_usage(agency_id, key, used, period)` + metering middleware that enforces limits. | Med |
| **PG-8** | **AI assistant + AI mock interview mismodelled.** §4.8 treats `partner-interview.html` as interview *scheduling*; it is an **AI mock interview**, and the dashboard has an **AI assistant chat** with a monthly quota. | "Interview slots" | Decide LLM provider + cost model + **PII-minimisation** before student data reaches a third-party model; enforce the message/interview quotas; or explicitly descope AI to a later phase. | Med (privacy) |
| **PG-9** | **KPI taxonomy misaligned.** `ApplicationStatus` lacks Non-Enrolment, Deferral and "Pending from Partner"; the 8 dashboard counters have no computed definition. | Enum without these buckets | Extend the status enum; define each of the 8 KPIs as an explicit query over statuses; document it beside the dashboard summary endpoint. | Med |
| **PG-10** | **Application creation pipeline undefined.** Endpoints are listed but not *who* creates an Application or *how* it flows (shortlist → apply → offer → visa → enrol), nor how "Request Program Options" becomes an application. | Endpoint list only | Product-define the application lifecycle and the enquiry→application transition before finalising the `applications` model. | Med |
| **PG-11** | **Learning-resource entity absent.** The `Document` model is student-scoped; partner "Learning Resources" are a different thing (admin-published files by country/category). | Admin CRUD listed, no model | Add `resource_docs(country, category, title, storage_key, …)`; the partner download endpoint reads it. | Low |

### 13.3 What the plan already gets right for the partner portal

PostgreSQL + private S3 + pre-signed URLs (C7), the RBAC matrix skeleton (§2.5), notifications/email-updates (C9), and the Phase-5 admin endpoints that manage partner-console content (managers/updates/quicklinks/docs/emails/notifications/text) — these align with `portal-render.js` and are sound. The partner-portal work is well-sequenced as **Phase 4**, but Phase 4 must be **re-scoped** to fix PG-1..PG-11 (especially move tenancy PG-1/PG-2 into the auth phase, and split the wallet PG-3..PG-4 into its own money phase with heightened review).

### 13.4 Blockers needing client input before build

- **PG-5** — the source/licence of the 100k+ program catalogue (search cannot be built without it).
- **PG-4** — which payment provider(s) fund the wallet, and the currency model (BDT top-up vs USD/GBP fees).
- **PG-8** — whether AI assistant/mock-interview is in scope for v1, and the data-privacy stance for sending student data to an LLM.

> Verdict: the plan is a strong full-site skeleton but is **thin and partly mis-modelled on the three partner-portal hard parts — tenancy, money, and search**. Nothing blocks starting Phase 0–2; PG-1/PG-2 must be resolved inside the identity phase, and PG-3..PG-5/PG-8 need the client decisions above before their phases begin.

---

## 14. Scope Revision (CURRENT) — Core Partner → Student Application Flow

**This section supersedes the money/benefit parts of §4, §5 and §13 for the current build.** Decision (2026-08-09): everything is **free for now** — build only the core loop below.

### 14.1 The core loop (the only thing we are building now)

1. A partner **counsellor logs in** to the console.
2. The counsellor **registers a student** and **opens an application "file"** for them (university/programme, intake, status).
3. The counsellor **works the file** — updates its status and timeline as the case progresses, attaches documents.
4. The **student logs in** to the student portal and **sees their own application status** (the same file the partner opened) — and nothing belonging to any other agency or student.

That partner-writes → student-reads linkage is the heart of this phase.

### 14.2 In scope vs deferred

| In scope (core) | Deferred (build later) |
|---|---|
| Partner auth + **agency tenancy** (multiple counsellors per agency) | **Wallet, payments, Flywire, top-ups, fee debits** (PG-3, PG-4) — all free now |
| **Student registration by the partner** + provisioning a student login (invite / set-password) | **Benefit tiers, quotas, "recruit N to unlock"** (PG-7) |
| **Application "file" CRUD** by the counsellor: create, update status, add timeline events | **AI assistant + AI mock interview** (PG-8) |
| **Student portal read**: own profile + own applications + status/timeline | **100k+ faceted program search** (PG-5) — counsellor types the university/programme as free text on the file for now |
| Case **documents** (upload + secure retrieval — reuse the student portal's existing doc packs) | Commission/settlement accounting |
| In-app **notification** on status change (light, optional) | Broader public-site CMS (unchanged; not part of this loop) |

**Still mandatory even in the reduced scope:** PG-1 (tenancy isolation), PG-2 (agency multi-user), PG-9 (application status taxonomy), and the new partner↔student linkage. PG-5/PG-3/PG-4/PG-7/PG-8 become moot for now.

### 14.3 Minimal data model (core only)

```
agencies(id, name, status)
agency_users(id, agency_id, name, email UNIQUE, password_hash, role[owner|counsellor])   -- counsellor logins
student_users(id, email UNIQUE, password_hash, status[invited|active])                    -- student login
students(id, agency_id, student_user_id NULL, first, middle, last, email, phone, ...)      -- the "file" owner
applications(id, agency_id, student_id, university, programme, intake, status, created_by, created_at, ...)
application_events(id, application_id, type, note, created_by, created_at)                 -- the student-visible timeline
documents(id, agency_id, student_id, application_id NULL, kind, storage_key, status, ...)
sessions / otp_codes / audit_log(...)
```

- `students.agency_id` = **tenancy** (an agency sees only its rows).
- `students.student_user_id` = the **login linkage** (set when the student accepts the invite).
- The **same `applications` row** feeds both `partner-applications.html` (write) and `student-tracking.html` (read).

### 14.4 Core API (the loop)

| Actor | Endpoint | Purpose |
|---|---|---|
| Partner | `POST /partner/students` | create a student **and** provision a login (sends invite) |
| Partner | `POST /partner/students/:id/applications` | **open a file** |
| Partner | `PATCH /partner/applications/:id` | update status |
| Partner | `POST /partner/applications/:id/events` | add a timeline entry |
| Partner | `POST /partner/documents` (+ signed download) | attach case documents |
| Student | `POST /auth/student/accept-invite` · `POST /auth/student/login` | claim the account + sign in |
| Student | `GET /student/me` · `GET /student/applications` · `GET /student/applications/:id` | **see own status + timeline** (read-only) |

Status taxonomy the student sees (fixes PG-9): `Submitted → Under Review → Conditional Offer → Offer → Visa Pending → Visa Received → Visa Rejected → Deferral → Non-Enrolment → Enrolled`. The 8 dashboard KPIs are computed as counts over these.

### 14.5 Revised phase focus (partner portal, this build)

| Phase | Goal | Wires | Exit gate |
|---|---|---|---|
| **A — Identity & tenancy** | Agency + counsellor auth; **student-login provisioning**; tenant guard | `partner-auth.js` + `auth.js` REAL REQUESTs | Login works; **agency A gets 403 on agency B's data** (CI test) |
| **B — Application file** | Counsellor opens/updates a file; status taxonomy + timeline | Register New Student modal, `partner-students.html`, `partner-applications.html` | A counsellor can create a student, open a file, and change its status |
| **C — Student read** | Student logs in and sees own application status/timeline | `student-tracking.html`, `student-profile.html` | Student sees exactly the file the partner opened; sees no one else's |
| **D — Documents** | Case documents on the file (upload + signed retrieval) | Request Program Options upload, student doc packs | A passport-class file uploads to private storage; only the owning agency + that student can retrieve it |

**Overall core exit gate:** a partner logs in, opens a file for a student and sets its status; the student logs in and sees that exact status and timeline — with strict tenancy isolation and no wallet/benefit/AI code in the path.
