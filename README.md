# EduPortal — Web-Based Integrated School Management System

**Datamex College of Saint Adeline**

A role-based school management system covering the full student lifecycle —
from application and enrollment, through documents, payment, class
registration, grading, permits and clearance — built on a custom PHP/MySQL MVC
architecture.

| | |
|---|---|
| **Repository** | https://github.com/haroldaguilar4438-byte/Eduportal |
| **Live deployment** | https://skyblue-sardine-644746.hostingersite.com |
| **Framework** | Custom PHP MVC (no third-party framework) |
| **Language / Runtime** | PHP 8.0+ (developed on 8.1) |
| **Database** | MySQL / MariaDB via PDO |
| **Web server** | Apache 2.4 (`mod_rewrite`, `mod_headers`) on shared hosting |
| **Scale** | 260 routes · 36 controllers · 120 views · 20 core classes · 60 tables |

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Features](#2-features)
3. [Technology Stack](#3-technology-stack)
4. [Architecture](#4-architecture)
5. [System Architecture](#5-system-architecture)
6. [Security Statement](#6-security-statement)
7. [Objectives](#7-objectives)
8. [Target Metrics](#8-target-metrics)
9. [Vulnerability Assessment (OWASP Top 10 — 2021)](#9-vulnerability-assessment-owasp-top-10--2021)
10. [Student Guidebook](#10-student-guidebook)
11. [Installation](#11-installation)
12. [Deployment](#12-deployment)
13. [Project Structure](#13-project-structure)

---

## 1. Project Overview

EduPortal replaces the paper-based processes of a school registrar's office
with a single authenticated web application. A student or parent submits an
enrollment application once; the same records then drive documents, payment,
subject registration, grading, permits and clearance — so nobody has to re-enter
the same information at each stage.

The system is organized around **six roles**, each with its own dashboard and
its own set of pages, enforced server-side on every request:

| Role | Responsibility |
|---|---|
| **Administrator** | Full control — accounts, subjects, tuition plans, calendar, backups |
| **Registrar** | Academic records, grade verification, document issuance, permits |
| **Accounting** | Enrollment finance, payment approval, receipts, financial clearance |
| **Teacher** | Grade sheets, classroom tasks, permit verification, clearance signing |
| **Student** | Enrollment, documents, classes, grades, permits, ledger |
| **Parent / Guardian** | Everything a student has, scoped to linked children only |

### Design goals

- **One source of truth.** A document uploaded once is reused automatically in
  every future application instead of being requested again.
- **Nothing is silently lost.** Resubmissions create a new version; rejected
  requests are retained; every workflow transition is audit-logged.
- **Least privilege by default.** A parent can only ever reach children linked
  to their own account, enforced in the data layer rather than the interface.

---

## 2. Features

### 2.1 Authentication & Account Security
- Email + password login with **bcrypt** hashing (`password_hash`/`password_verify`)
- **Multi-factor authentication** — TOTP authenticator app and SMS, plus 10
  single-use recovery codes
- Brute-force protection — lockout for 15 minutes after 5 failed attempts,
  tracked per IP **and** per email
- **30-minute idle timeout** with automatic sign-out
- Trusted-device management and remote session/device revocation
- New-device email verification for unfamiliar logins
- Password reset via single-use link (1-hour TTL); email verification via link
  (24-hour TTL) or 6-digit OTP (10-minute TTL)

### 2.2 Enrollment & Applications
- Guided application with level, program and academic-period selection
- Multi-stage approval pipeline: `pending → accounting_approved →
  awaiting_payment → approved`, plus `rejected` and `action_required`
- Parent/guardian filing for children who do not yet have accounts
- Automatic parent↔child linking once an application is approved
- Re-enrollment flow and college subject-eligibility checking

### 2.3 Document Management
- **Permanent reusable documents** — PSA Birth Certificate, Valid ID, Certificate
  of Good Moral, Form 137, Form 138, 2×2 photo, and ECCD Checklist
- **Level-aware requirements** — ECCD is requested for Kinder and Grade 1 only,
  never for college students
- Versioned uploads: a new submission creates a new version; history is never
  overwritten
- Accounting review with resubmission reasons
- **Official generated documents** — Transcript of Records, Certificate of
  Enrollment, Certificate of Grades, Grade Slip, Grade Equivalency, Registration
  Form — printed **only** from finalized official records

### 2.4 Payments & Finance
- PayRex gateway (GCash, Maya, Card, QR Ph) with signed webhook verification
- Active tuition plans drive amounts — no hard-coded figures
- Payment approval and receipt dispatch by Accounting
- Per-student ledger and outstanding balances
- Financial clearance workflow

### 2.5 Academics
- Admin-managed subject offerings with per-section invite codes
- Enrollment-gated class registration (fully paid + fully enrolled)
- Weekly schedule
- Grade sheets with a **Draft → Submitted → Under Review → Returned → Approved →
  Finalized** workflow
- Weighted grade computation, configurable grade scale, INC handling
- Only **finalized** grades are treated as official; only **released** ones print

### 2.6 Classroom
- Tasks with due dates and attachments
- Student submissions with versioning and file-type validation
- Teacher grading with feedback, teacher-owned announcement and assignment posting

### 2.7 Permits & Clearance
- Exam permits requested in order (Prelim → Midterm → Prefinal → Finals),
  each verified by Accounting → Registrar → Teacher
- QR verification links for permits and clearances
- Digital clearance cycle with per-subject teacher signing, Registrar review,
  then the financial leg

### 2.8 Communication & Insights
- Announcements and school news with role-targeted publishing
- Notification centre with unread tracking and live push (EduLive)
- Blog / content management
- Global search with role-scoped suggestions
- School analytics dashboard
- **AI assistant** — role-aware, grounded in verified portal data, answers in
  plain language and never claims to have performed an action

---

## 3. Technology Stack

### Backend
| Component | Choice | Rationale |
|---|---|---|
| Language | **PHP 8.0+** | Native `match`, union types, `str_starts_with`, typed properties |
| Architecture | **Custom MVC** | Full control over routing, RBAC and audit flow; no framework overhead |
| Routing | Custom front-controller router with `{param}` placeholders | Small, auditable, zero dependency |
| Database access | **PDO**, native prepared statements, `EMULATE_PREPARES=false` | Real parameter binding — injection-safe by construction |
| Templating | Plain PHP views with a shared layout | No template-engine dependency |
| Mail | **PHPMailer** (bundled in `app/lib/`) | SMTP delivery for verification/reset/receipts |
| Payments | **PayRex API** + HMAC webhook | Card/e-wallet/QR with signed callbacks |

### Frontend
| Component | Choice |
|---|---|
| CSS | Bootstrap 5.3.3 + Font Awesome 6.5.1 + Bootstrap Icons 1.11.3 |
| Utility CSS | Tailwind CSS (Play CDN) |
| Typography | Inter + Plus Jakarta Sans (Google Fonts) |
| JavaScript | Vanilla JS, no framework |
| Design system | `design-system.css` — tokens, components, dark mode |
| Responsiveness | `responsive.css` — breakpoints 360 / 480 / 640 / 768 / 1024 / 1280 / 1400 |

### Infrastructure
- Apache 2.4 with front-controller rewriting
- MySQL / MariaDB (InnoDB, `utf8mb4`)
- Shared hosting (Hostinger) with `.htaccess` and environment variables
- Directory-listing disabled, dotfiles and `.sql`/`.log`/`.bak` blocked

---

## 4. Architecture

EduPortal follows a strict **Model–View–Controller** pattern with a
front-controller entry point.

```
Browser request
      │
      ▼
public_html/.htaccess  ── rewrites every non-file request to index.php
      │
      ▼
public_html/index.php   ── front controller: boot, route, dispatch
      │
      ▼
Core/bootstrap.php     ── config + secrets
                        ── PSR-4 autoloader (App\ → app/, Lib\ → app/lib/)
                        ── procedural helpers (functions, ui, mailer)
                        ── error/exception handlers
                        ── hardened session cookie
      │
      ▼
Core/Router            ── matches GET/POST against routes/web.php
      │
      ▼
Controller             ── enforces Auth::requireRole(...)
      │                 ── validates input, calls models/services
      ├──▶ Core/Database   (PDO, prepared statements)
      ├──▶ Core/*          domain services (GuardianLinks, StudentDocs, Tuition,
      │                    AcademicPeriod, GradeEngine, Mfa, Uploads, …)
      │
      ▼
View  ──▶ shared/layout.php ──▶ HTML response
```

### Layer responsibilities

| Layer | Location | Responsibility |
|---|---|---|
| **Entry** | `public_html/index.php` | Single HTTP entry point; routing and dispatch only |
| **Core** | `app/Core/` | Framework services — DB, auth, router, CSRF, uploads, grading, periods, guardian links |
| **Controllers** | `app/Controllers/` | HTTP concerns: authorisation, validation, orchestration, view data |
| **Models** | `app/Models/` | Domain entities and data access for blog, announcements, users |
| **Views** | `app/views/` | Presentation; no business logic, no direct SQL |
| **Helpers** | `app/helpers/` | Global functions: escaping, URLs, flash messages, UI fragments |

### Key architectural decisions

**Application code lives outside the web root.** Only `public_html/` is served.
`app/` contains all logic, models and views and is additionally protected by a
second `Require all denied` rule — defence in depth against misconfiguration.

**Native prepared statements everywhere.** `Database` exposes only
`run/one/all/value/insert/update`; all values are bound parameters. Table and
column identifiers passed to `insert()`/`update()` are validated against a
strict pattern (`assertIdentifier`) so no caller can inject an identifier.

**Views are sandboxed from data.** `Controller::view()` renders the view with
`ob_start`, then the shared layout. Every value that reaches HTML passes through
`e()`, the global escaping helper.

**Fail-open on optional context, fail-closed on security.** Non-critical
lookups (notification badge, academic period) are wrapped so a missing table
never breaks a page. Authentication, authorisation, CSRF and webhook signature
verification are never bypassed.

---

## 5. System Architecture

### 5.1 Logical layers

```
┌──────────────────────────────────────────────────────────────┐
│  PRESENTATION LAYER                                          │
│  Bootstrap 5 · Tailwind · design-system.css · responsive.css  │
│  Vanilla JS modules (edulive, responsive, chatbot, content)  │
└──────────────────────────────────────────────────────────────┘
                              ▲ ▼  HTTPS
┌──────────────────────────────────────────────────────────────┐
│  APPLICATION LAYER                                           │
│  Router · Controllers · Services · View engine               │
│  ┌────────────┬─────────────┬──────────────┬───────────────┐  │
│  │ Auth / RBAC│ Validation  │ Workflows    │ AI Assistant  │  │
│  │ Csrf       │ Formatting  │ (enrol/doc/  │ (grounded,    │  │
│  │ Mfa        │ Uploads     │  pay/grade)  │  read-only)   │  │
│  └────────────┴─────────────┴──────────────┴───────────────┘  │
└──────────────────────────────────────────────────────────────┘
                              ▲ ▼  PDO (prepared statements)
┌──────────────────────────────────────────────────────────────┐
│  DATA LAYER                                                  │
│  60 InnoDB tables · utf8mb4 · FK constraints                 │
│  Users/Roles · Students · Enrollment · Documents · Payments  │
│  Academics · Classrooms · Permits · Clearances · Audit/Logs  │
└──────────────────────────────────────────────────────────────┘
```

### 5.2 Role-based access matrix

| Module | Admin | Registrar | Accounting | Teacher | Student | Parent |
|---|:--:|:--:|:--:|:--:|:--:|:--:|
| Enrollment queue | ✔ | ✔ | ✔ | — | — | — |
| Student records | ✔ | ✔ | ✔ (finance) | — | own | linked children |
| Documents (issue/release) | ✔ | ✔ | ✔ (verify) | — | request | request for children |
| Payments / ledger | ✔ | — | ✔ | — | own | linked children |
| Grade sheets | ✔ | ✔ (review) | — | ✔ (own) | view released | view released |
| Classrooms | ✔ | — | — | ✔ (own) | ✔ (join) | — |
| Exam permits | ✔ | ✔ | ✔ | ✔ (verify) | request | request for children |
| Clearance | ✔ | ✔ (review) | ✔ (financial) | ✔ (sign) | request | request for children |
| Accounts & backups | ✔ | — | — | — | — | — |
| Content / announcements | ✔ | — | — | — | view | view |

*Enforced server-side by `Auth::requireRole()` on 173 call sites and by
ownership checks inside `GuardianLinks`.*

### 5.3 Key business workflows

```
ENROLLMENT
  applicant ─► pending ─► accounting_approved ─► awaiting_payment ─► approved ─► ENROLLED
                    │              │                     │
                    └── rejected ──┴── action_required ◄──┘   (PayRex payment)

DOCUMENTS (stamped)
  pending ─► approved ─► ready ─► released   (student may print)
                │                      │
                └── rejected (reason) ─┘──► request again (new record)

EXAM PERMIT   (per period, sequential)
  pending_accounting ─► approved_accounting ─► approved_registrar ─► fully_approved
                                                     │ teacher verify       │
                                                     └── rejected ◄──────────┘

CLEARANCE
  pending ─► signed (all teachers) ─► issued ─► Accounting ─► cleared
                │                                 └─────────► not_cleared / on_hold
                └── returned (with reason)

GRADE SHEET
  draft ─► submitted ─► under_review ─► approved ─► finalized  (official)
                       └► returned (teacher corrects)
```

---

## 6. Security Statement

EduPortal stores personally identifiable information (names, addresses, birth
dates, payment records, scanned government IDs and academic records). The
following controls are implemented and enforced **server-side**:

1. **Access control** — every route declares the roles allowed to reach it;
   parent access is additionally restricted to children linked through
   `guardian_students`, validated per request.
2. **Password storage** — bcrypt via PHP's `password_hash()`, with automatic
   rehashing when PHP's default algorithm improves.
3. **Session hardening** — `HttpOnly`, `SameSite=Lax`, `Secure` under HTTPS,
   regenerated on privilege change, 30-minute idle expiry, server-side
   revocation.
4. **CSRF protection** — token issued per session and required on all 140
   state-changing endpoints (85 top-level controllers, 50 in `Admin/`, 2 in
   `Student/`, 2 inline route closures and 1 API endpoint); validated on every
   authenticated POST.
5. **Injection resistance** — PDO native prepared statements throughout; no
   string concatenation of user input into SQL.
6. **Transport security** — HTTPS forced via `mod_rewrite`; secure cookie flags.
7. **Output encoding** — the global `e()` helper escapes all dynamic output.
8. **Upload safety** — MIME sniffing via `finfo`, allow-listed types, a 10 MB
   cap, server-generated filenames, and an `.htaccess` that blocks script
   execution inside the uploads directory.
9. **Auditability** — 71 audit-log write points record actor, entity, IP and
   timestamp for sensitive actions.
10. **Secret management** — database, mail, payment and AI credentials are
    excluded from version control via `.gitignore` and supplied through
    `app/config/credentials.php` or hosting environment variables.

### Incident disclosure

Report suspected vulnerabilities privately to the project owner. Please do not
attempt denial-of-service, bulk data extraction, or access to other users'
records.

---

## 7. Objectives

| # | Objective | Success measure |
|---|---|---|
| O1 | Digitise the registrar's core workflow end-to-end | 100% of enrollment, document and payment requests processed in-system |
| O2 | Eliminate duplicate data entry | A document uploaded once is reused automatically in later applications |
| O3 | Enforce role separation | Zero cross-role data access, verified by automated authorisation tests |
| O4 | Protect student financial and identity records | No unauthorised disclosure of PII, credentials or payment data |
| O5 | Provide reliable academic records | Only finalized grades treated as official; full history retained |
| O6 | Support mobile access | Usable navigation and workflows from 320 px upward |
| O7 | Reduce support burden | Role-aware AI assistant answering from verified portal data |
| O8 | Operate on shared hosting | Runs on Hostinger with no root access or framework dependencies |

---

## 8. Target Metrics

### Performance
| Metric | Target |
|---|---|
| Page load (cached assets, normal network) | < 2.0 s |
| Server response time per request | < 500 ms |
| Chat assistant first response | < 3.0 s |
| Concurrent users (shared hosting) | 50 |
| Uptime | ≥ 99.5% |

### Security
| Metric | Target |
|---|---|
| Unauthorised access incidents | 0 |
| CSRF-protected state-changing endpoints | 100% |
| SQL queries using parameter binding | 100% |
| Secrets committed to version control | 0 |
| OWASP Top 10 findings open at submission | 0 critical, 0 high |

### Usability
| Metric | Target |
|---|---|
| Time to complete an enrollment application | < 10 minutes |
| Tasks a first-time user can complete unaided | ≥ 90% |
| Mobile layouts without horizontal scroll at 320–1366 px | 100% |

### Data integrity
| Metric | Target |
|---|---|
| Overwritten or deleted historical records | 0 |
| Payment records created without approval | 0 |
| Status transitions without an audit-log entry | 0 |

---

## 9. Vulnerability Assessment (OWASP Top 10 — 2021)

Assessment method: source-code review of the application layer, configuration
review of `.htaccess` and `config.php`, and data-layer review of the schema and
`Core/Database`. Each control below is referenced to its implementing file.

### Summary

| # | OWASP 2021 category | Risk | Status |
|---|---|---|---|
| A01 | Broken Access Control | High | **Mitigated** |
| A02 | Cryptographic Failures | High | **Mitigated** |
| A03 | Injection | Critical | **Mitigated** |
| A04 | Insecure Design | Medium | **Mitigated** |
| A05 | Security Misconfiguration | Medium | **Mitigated** |
| A06 | Vulnerable & Outdated Components | Medium | **Partially mitigated** |
| A07 | Identification & Authentication Failures | High | **Mitigated** |
| A08 | Software & Data Integrity Failures | Medium | **Mitigated** |
| A09 | Security Logging & Monitoring Failures | Low | **Mitigated** |
| A10 | Server-Side Request Forgery | Medium | **Mitigated** |

### A01 — Broken Access Control *(Risk: High — Mitigated)*

*Threat:* a user reads or changes records belonging to someone else.

| Control | Evidence |
|---|---|
| Role enforcement on every action | `Auth::requireRole()` — **173 call sites** across controllers |
| Parent scope limited to linked children | `GuardianLinks::ownedChild()` re-validates `guardian_students` on every request; a forged `child_id` is rejected, never silently swapped |
| Default-deny routing | `Router` has no unauthenticated handler; unauthenticated access redirects to `/login` |
| Privilege changes invalidate sessions | `Auth::user()` re-reads role and status per request, so a suspended account loses access immediately |
| Forced sign-out on tampering | `Auth::boot()` enforces idle timeout and revoked-session checks on every request |

*Residual risk:* authorisation is enforced per controller rather than by a
central policy layer; a newly added controller could omit `requireRole()`.
Mitigation: `Auth::requireRole()` is the documented convention and every
existing role-guarded module follows it.

### A02 — Cryptographic Failures *(Risk: High — Mitigated)*

| Control | Evidence |
|---|---|
| Passwords hashed, never stored or logged in clear | `password_hash($p, PASSWORD_DEFAULT)` / `password_verify()` in `Core/Auth.php` |
| Secrets excluded from source control | `.gitignore` blocks `app/config/credentials.php`, logs, and user uploads |
| No secrets in the web root | `app/.htaccess` = `Require all denied`; only `public_html/` is served |
| Secrets removed from tracked config | `public_html/.htaccess` now carries **no** credentials; secrets are supplied via hosting environment variables |
| Transport encrypted | HTTPS forced by `mod_rewrite`; `Secure` cookie flag set when `HTTPS=on` |
| Verification tokens are hashed & time-limited | Email link 24 h, reset link 1 h, OTP 10 min — each single-use |
| AI provider key server-side only | `GEMINI_API_KEY` read in `Core/GeminiClient.php`; the browser never receives it |

### A03 — Injection *(Risk: Critical — Mitigated)*

| Control | Evidence |
|---|---|
| All queries parameterised | `Core/Database::run/one/all/value` use `prepare()` + bound parameters |
| No emulation | `PDO::ATTR_EMULATE_PREPARES => false` → genuine server-side binding |
| Identifier injection blocked | `Database::assertIdentifier()` enforces `^[A-Za-z_][A-Za-z0-9_]*$` on every table/column passed to `insert()`/`update()` |
| Dynamic SQL uses fixed fragments only | The ~10 concatenated fragments are internal `WHERE`/`ORDER` clauses assembled from constants and literals; **no user value is interpolated** |
| Output escaped | `e()` applied to all dynamic output in views |

### A04 — Insecure Design *(Risk: Medium — Mitigated)*

Business rules are enforced in the service layer, not left to the interface:

- Document release requires passing through `approved → ready`; transitions are validated against an allowed-state map in `RegistrarController::releaseDocument()`.
- Exam permits are sequential per period and require Accounting → Registrar → Teacher.
- Only **finalized** grade sheets count as official; only **released** documents are printable.
- Grade-bearing document requests are refused when no finalized grades exist.
- Duplicate active requests for the same document + term are blocked.
- Payable amounts are derived from the active tuition plan, never hard-coded.
- PayRex webhooks are accepted only after HMAC signature verification.

### A05 — Security Misconfiguration *(Risk: Medium — Mitigated)*

| Control | Evidence |
|---|---|
| Directory listing off | `Options -Indexes` |
| Sensitive extensions blocked | `FilesMatch "\.(sql|log|ini|bak|old|md|dist|example)$" → Require all denied` |
| Dotfiles unreachable | `FilesMatch "^\." → Require all denied` (also hides `.htaccess` itself) |
| Response headers set | `X-Content-Type-Options`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`, `Permissions-Policy` |
| Production-safe errors | `APP_DEBUG` is `production` on live; errors log with a correlation ID and never expose stack traces |
| Uploads cannot execute | `public_html/uploads/.htaccess` blocks PHP execution |
| Default credentials not shipped | `credentials.example.php` is a template; the real file is git-ignored |

### A06 — Vulnerable & Outdated Components *(Risk: Medium — Partially mitigated)*

| Dependency | Version | Note |
|---|---|---|
| Bootstrap | 5.3.3 | Current at time of writing |
| Bootstrap Icons | 1.11.3 | Current |
| Font Awesome | 6.5.1 | Current |
| Tailwind CSS | **Play CDN (runtime JIT)** | ⚠ See below |
| PHPMailer | Bundled in `app/lib/` | Source-in-tree; no runtime fetches |

**Finding A06-1 (accepted, documented):** Tailwind is loaded from
`cdn.tailwindcss.com`, which compiles utilities in the browser at runtime.
Besides the availability risk, it means third-party JavaScript executes on every
authenticated page with access to the DOM and the CSRF token.

*Mitigation:* EduPortal's own `design-system.css` carries all structural and
component styling, so the portal degrades gracefully without Tailwind.
*Recommended:* replace the Play CDN with a pre-built Tailwind stylesheet before
production rollout.

### A07 — Identification & Authentication Failures *(Risk: High — Mitigated)*

| Control | Evidence |
|---|---|
| MFA | TOTP + SMS via `Core/Mfa.php`, 10 single-use recovery codes, encrypted secrets |
| Brute-force lockout | 5 failures → 15-minute lockout, enforced per IP **and** per email |
| Idle session expiry | 30 minutes (`SESSION_IDLE_TIMEOUT`) |
| Session fixation prevention | `session_regenerate_id(true)` on login and on privilege change |
| Device trust | New-device verification email; trusted devices revocable individually |
| Step-up authentication | Sensitive security actions require MFA re-entry |
| AI assistant never solicits secrets | `ChatbotKnowledge::redact()` strips passwords, OTPs, recovery codes, card numbers and API keys before anything is stored or sent to the model |

### A08 — Software & Data Integrity Failures *(Risk: Medium — Mitigated)*

| Control | Evidence |
|---|---|
| Payment callbacks authenticated | `public_html/api/payrex/webhook.php` reads the `PayRex-Signature` header and validates it via `PayRexClient::verifyWebhookSignature()`, which recomputes `hash_hmac('sha256', "$timestamp.$rawBody", $secret)` and rejects the event **before any row is written** |
| No code fetched at runtime server-side | PHPMailer is bundled; the AI client calls one fixed, hard-coded endpoint |
| Model cannot execute actions | The assistant is read-only by construction — it has no write path and the prompt forbids claiming any action was performed |
| Prompt injection contained | The system instruction is delivered via Gemini's `systemInstruction` field, so page data and user text cannot overwrite it |
| TLS verified | `Core/GeminiClient` resolves a platform CA bundle and **fails closed** rather than disabling certificate validation |

### A09 — Security Logging & Monitoring Failures *(Risk: Low — Mitigated)*

| Control | Evidence |
|---|---|
| Audit trail | `audit_logs` captures actor, entity type, entity id, IP, user agent and timestamp across **71** write points |
| Application logging | `storage_log()` writes timestamped daily logs outside the web root |
| PHP error log | Separate `php-error.log`; `.htaccess` blocks `.log` downloads |
| Correlation IDs | Every 500 response carries a reference ID written to the log with the request path |
| Alerts | Notification service raises staff alerts on queue backlogs and account-state changes |

*Residual risk:* no external SIEM. Acceptable for a single-school deployment;
an aggregated central log store is the natural next step.

### A10 — Server-Side Request Forgery *(Risk: Medium — Mitigated)*

| Control | Evidence |
|---|---|
| No user-controlled outbound URLs | The only server-side HTTP call is to a fixed Gemini endpoint; the model and host are configuration constants, never request input |
| No proxy/fetch feature | The application exposes no URL-fetch, import-by-URL, or webhook-callback-by-user-input functionality |
| No redirects from user input | `redirect()` only accepts application-relative paths |

### Accepted risks

1. **A06-1** Tailwind Play CDN executes third-party JavaScript on authenticated pages.
2. **A09** Logs are local to the host, not centralised.
3. **Shared-hosting trust** — the application depends on the provider patching
   the OS, PHP and MySQL versions; a stale server-side PHP build would be
   outside the project's control.

---

## 10. Student Guidebook

A task-oriented guide for students and parents is maintained separately so it can
be handed out without the technical chapters:

**[`docs/STUDENT_GUIDEBOOK.md`](docs/STUDENT_GUIDEBOOK.md)** — signing in,
enrolling, uploading documents, paying, joining classes, checking grades,
requesting documents, permits and clearance, and what each status means.

---

## 11. Installation

### Requirements
- PHP **8.0+** with `pdo_mysql`, `mbstring`, `curl`, `fileinfo`, `openssl`, `gd`
- MySQL 5.7+ / MariaDB 10.3+
- Apache with `mod_rewrite` and `mod_headers`
- A writable `logs/` directory and `public_html/uploads/`

### Steps

```bash
# 1. Place the project so that app/ sits OUTSIDE public_html
   project/
   ├── app/
   ├── database/
   ├── logs/
   └── public_html/        <- the only directory a web server should expose

# 2. Create the database and import the schema
mysql -u root -p -e "CREATE DATABASE eduportal_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p eduportal_db < database/schema.sql

# 3. Apply incremental patches in any later schema/ folder order, then:
#    patch_registrar_accounting.sql, patch_academic_records.sql,
#    patch_academic_periods.sql, patch_student_documents_compat.sql

# 4. Configure credentials
cp app/config/credentials.example.php app/config/credentials.php
# then edit credentials.php with your database, SMTP and PayRex values

# 5. Point the web server at public_html/
```

### Local development
```bash
php -S localhost:8000 -t public_html
```

---

## 12. Deployment

Target: shared hosting where `public_html/` is the document root and `app/`
sits one level above it.

1. **Upload** `app/`, `database/` and `public_html/` — `app/` **must** remain
   outside the document root.
2. **Create the database** and import `database/schema.sql`, then the patches.
3. **Create `app/config/credentials.php`** on the server from the example file.
   This file is git-ignored and must never be uploaded to a public repository.
4. **Set secrets in hPanel**, not in code:
   `DB_USER`, `DB_PASS`, `PAYREX_WEBHOOK_SECRET`, `SMTP_*`, `GEMINI_API_KEY`
   — *hPanel → Advanced → PHP Configuration / Environment Variables*.
5. **Permissions**: `logs/` and `public_html/uploads/` must be writable by PHP
   (`755`, or `775` if the host uses a different group).
6. **Verify**: open `/health` — it returns JSON confirming the app booted.
7. **Clear the edge cache** after deployment and hard-refresh (CSS/JS are
   versioned by file mtime).

### Post-deployment checklist

- [ ] `/health` responds `{"ok":true}`
- [ ] Sign in as each of the six roles; confirm each dashboard loads
- [ ] Confirm a student cannot open `/admin/*` (should redirect)
- [ ] Confirm `logs/` is not downloadable
- [ ] Confirm `app/config/credentials.php` returns 404 over HTTP
- [ ] Submit a test payment and confirm the signed webhook updates the ledger
- [ ] Confirm `APP_ENV=production` (users see a safe error page, not a trace)

### If a secret was ever committed
Credentials that entered a public repository must be **rotated**, not just
deleted from the latest commit — Git history preserves them. Rotate the
database password and the PayRex webhook secret in hPanel, then use
`git filter-repo` (or BFG) to purge them from history if required.

---

## 13. Project Structure

```
Capstone1/
├── app/
│   ├── Core/                  20 framework services
│   │   ├── Auth.php           authentication, RBAC, lockout, MFA gates
│   │   ├── bootstrap.php      autoloader, error handlers, session
│   │   ├── Controller.php     base controller + view engine
│   │   ├── Csrf.php           token issue/validation
│   │   ├── Database.php       PDO toolkit (prepared statements only)
│   │   ├── Router.php         front-controller router
│   │   ├── AcademicPeriod.php band-aware periods (quarter/semester)
│   │   ├── ChatbotKnowledge.php AI knowledge, role context, safety
│   │   ├── CollegeEligibility.php, EnrollmentCycle.php, GradeEngine.php,
│   │   ├── GuardianLinks.php  parent ↔ child ownership
│   │   ├── Mfa.php, Tuition.php, Uploads.php, GeminiClient.php, …
│   ├── Controllers/           36 controllers (Admin/, Student/ sub-namespaces)
│   ├── Models/                Announcement, Blog, User
│   ├── views/                 120 view templates (shared/ partials + layout)
│   ├── helpers/               functions.php, ui.php, mailer.php
│   ├── lib/                   PHPMailer, PayRexClient
│   ├── routes/web.php         260 route definitions
│   └── config/                config.php, credentials.example.php
├── database/
│   ├── schema.sql             base schema (60 tables)
│   ├── patch_*.sql            idempotent incremental migrations
│   └── schema-hostinger.sql   shared-hosting variant
├── logs/                      application + error logs (never web-served)
├── public_html/               ← document root
│   ├── index.php              front controller
│   ├── .htaccess              rewrites, security headers, env
│   ├── api/                   chatbot.php, payrex/webhook.php
│   ├── assets/                css/ (4 stylesheets), js/, img/
│   ├── uploads/               user files, execution blocked
│   └── install/               first-run admin setup
├── docs/
│   └── STUDENT_GUIDEBOOK.md
└── README.md
```

---

## Acknowledgements

Developed for **Datamex College of Saint Adeline** as a Capstone Project
(Project 1). Special thanks to the Registrar and Accounting offices whose real
workflows shaped the system's approval pipelines.

## License

For academic submission and institutional use. Contains no third-party
credentials; bundled PHPMailer retains its LGPL licence.