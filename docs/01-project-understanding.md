# 01 — Project Understanding

**Project:** Mini Loan Management System  
**Repos:** `mini-loan-ms-api` (engine) · `mini-loan-ms-web` (ops UI)  
**Assessment:** Demulla Investments Limited — Assistant Software Development Lead  
**Document type:** Product discovery (Approach B)  
**Status:** Milestone 0 — in progress

---

## 1. Problem Statement

Demulla needs a small, working loan management system that can take a customer from onboarding through to a reconciled repayment. The brief is not a UI exercise and not a thin CRUD demo. The hard requirement is **financial integrity under unreliable external payment signals**.

Safaricom’s Daraja sandbox will be used for real B2C disbursement and STK Push repayment flows. Those integrations are necessary, but they are not the exam. The exam is this constraint:

> Do not build matching logic around Safaricom’s own identifiers as the primary key back to a loan. Those identifiers are useful confirmations. They are Safaricom’s data, on Safaricom’s terms. The system must not be structurally dependent on them arriving reliably, unchanged, or on time.

Therefore this project is framed as:

**A financial transaction processing engine**, with a loan operations UI around it.

The engine owns:

- Customer and loan product definitions
- Loan lifecycle and installment schedules
- Auditable disbursement transitions
- Internally generated **Payment Intents** as the source of truth for repayment attempts
- Reconciliation, allocation, credit wallet handling, and audit trails
- Optional secondary evidence from an SMS forwarder webhook

The Vue application is an **operations console**. It must feel executive-grade, but it must never own money-moving rules.

Success means a reviewer can open the repository and conclude: this person thinks like an architect — especially around reconciliation, idempotency, and failure modes.

---

## 2. Goals and Non-Goals

### 2.1 Goals

| ID | Goal |
|----|------|
| G1 | Deliver a working end-to-end path: customer → product → loan → approval → schedule → B2C disbursement → STK repayment → reconciled allocation. |
| G2 | Treat **internal Payment Intent UUIDs** as the primary identity for repayment attempts; store Safaricom IDs as metadata only. |
| G3 | Make disbursement an auditable one-way transition (disbursement record + Daraja B2C), never a silent status edit. |
| G4 | Handle reconciliation edge cases explicitly: missing, duplicate, late, wrong-amount, colliding, partial, and overpayment flows. |
| G5 | Credit **customer wallet** on overpayment; later repayments may draw from wallet according to documented rules. |
| G6 | Scaffold an **SMS forwarder webhook** as a secondary evidence channel into the same reconciliation engine (not a primary matcher). |
| G7 | Keep money as `decimal` (never float); use database transactions and row locking where allocation races matter. |
| G8 | Separate backend engine and frontend UI into two repositories under `/Users/dolcepay/Code/`. |
| G9 | Use Laravel Sanctum for SPA authentication of the ops UI. |
| G10 | Use **flat interest** for loan products, with a written rationale versus reducing balance. |
| G11 | Ship clean migrations (`migrate:fresh` must succeed from a fresh clone), readable Git history, and documentation that can be defended in interview. |
| G12 | Provide an executive-level ops UI (clarity, motion, hierarchy) without sacrificing engine correctness for chrome. |

### 2.2 Non-Goals

| ID | Non-goal | Why it is out of scope |
|----|----------|------------------------|
| NG1 | Customer-facing mobile app or borrower self-service portal | Assessment targets ops + engine, not consumer UX. |
| NG2 | Production M-Pesa / live Daraja credentials | Sandbox only for the assessment. |
| NG3 | Multi-tenant SaaS, branches, or lender marketplaces | Single-tenant ops system is enough. |
| NG4 | Full banking double-entry ledger, event sourcing, or saga orchestration as the default architecture | Valuable discussion topics; implement only if explicitly approved later. A pragmatic intent + allocation + audit model is the target. |
| NG5 | CBK licensing, KYC vendor integrations, or credit bureau flows | Outside a take-home LMS scope. |
| NG6 | Depending on `CheckoutRequestID`, `MerchantRequestID`, or `ReceiptNumber` as structural join keys | Forbidden by the brief and by our architecture. |
| NG7 | Using phone + amount alone as the primary matching strategy | Too collision-prone; allowed only as supporting signals under an intent. |
| NG8 | Treating the SMS forwarder as the primary payment source of truth | It is disaster-recovery / secondary evidence only. |
| NG9 | Polished UI at the expense of reconciliation correctness | Engine first; UI excellence follows stable APIs. |
| NG10 | Skipping loan lifecycle states for convenience | Status transitions must be explicit and auditable. |

### 2.3 Framing statement (for README / interview)

> We did not build “a loan CRUD app with M-Pesa calls.” We built a payment-intent-driven transaction engine that can originate loans, disburse via B2C, collect via STK, and reconcile without trusting Safaricom identifiers as our primary keys — with wallet handling, manual recovery, and an SMS evidence fallback on the same reconciliation path.

---

## 3. Personas

| Persona | Channel | Primary jobs | Does not do |
|---------|---------|--------------|-------------|
| **Ops Officer** | `mini-loan-ms-web` | Onboard customers, define products, originate/approve loans, trigger disbursement & STK, investigate unmatched payments, manual reconcile, read audit/timelines | Edit money math in the browser; invent Safaricom join keys; skip loan states |
| **Customer (Borrower)** | M-Pesa only | Receive B2C funds; approve/decline STK prompts; receive M-Pesa SMS confirmations | Log into the ops UI (out of scope) |
| **System** | API workers, scheduler, webhooks | Call Daraja, ingest callbacks/SMS evidence, match Payment Intents, allocate installments, credit wallet, expire/escalate stale intents, write audit/webhook logs | Guess silently when match confidence is low — escalate to manual |

**Interview note:** The Ops Officer is the only human UI user. The Customer is a payment participant. The System is a first-class actor because most reconciliation risk lives in async paths.

---

## 4. Functional Requirements

Requirements use stable IDs so later milestones and tests can trace back here.

### 4.1 FR-A — Authentication & access

| ID | Requirement |
|----|-------------|
| FR-A1 | Ops UI authenticates against the API using **Laravel Sanctum** (SPA). |
| FR-A2 | Protected API routes require an authenticated ops user. |
| FR-A3 | Authorization uses policies (at minimum: authenticated ops may manage customers, products, loans, payments, and manual reconciliation). |
| FR-A4 | Auth and money-moving endpoints are rate-limited. |

### 4.2 FR-C — Customer management

| ID | Requirement |
|----|-------------|
| FR-C1 | Create and view a customer: name, phone, ID number, optional email. |
| FR-C2 | Phone doubles as the default M-Pesa payer/disbursement number. |
| FR-C3 | Phone numbers are normalized and **unique** (no duplicates). |
| FR-C4 | Validate sane formats for phone, ID number, and email (when present). |
| FR-C5 | Support list/search/pagination for ops workflows. |
| FR-C6 | Customer create/update writes an audit trail. |

### 4.3 FR-P — Loan products

| ID | Requirement |
|----|-------------|
| FR-P1 | Create reusable loan products (not tied to a single customer): name, interest model, rate, term, fees/charges. |
| FR-P2 | Interest model for v1 is **flat**; document why reducing balance was not chosen. |
| FR-P3 | Term is expressed in months or weeks (product field; schedule engine interprets consistently). |
| FR-P4 | Products can be listed/viewed and reused across many loan applications. |

### 4.4 FR-L — Loan origination & lifecycle

| ID | Requirement |
|----|-------------|
| FR-L1 | Assign a customer to a loan product to create a loan application/account (principal/amount as required by product rules). |
| FR-L2 | Loan status lifecycle is explicit and ordered; states are not skipped. Target chain: `pending` → `approved` → `disbursement_requested` → `disbursed` → `active` → `completed` / `closed` (exact enum names locked in Milestone 2). |
| FR-L3 | Approval is a deliberate transition that triggers installment schedule generation. |
| FR-L4 | Loan detail exposes status timeline suitable for ops UI. |

### 4.5 FR-I — Installment schedule

| ID | Requirement |
|----|-------------|
| FR-I1 | On approval, generate an installment schedule: principal, interest, due dates per product terms (flat interest). |
| FR-I2 | Schedule becomes live when the loan is disbursed/active (not merely while pending). |
| FR-I3 | Installments track amounts due, amounts paid, and remaining balances using decimal money. |

### 4.6 FR-D — Disbursement (Daraja B2C)

| ID | Requirement |
|----|-------------|
| FR-D1 | A disburse action creates an auditable **disbursement** record and calls Daraja sandbox **B2C**. |
| FR-D2 | Loan moves to `disbursement_requested` when the outbound request is initiated; reaches `disbursed`/`active` only after a successful, recorded outcome. |
| FR-D3 | Store request payload, response, errors, and timestamps for audit/retry. |
| FR-D4 | Never mark a loan disbursed by editing a status field alone. |

### 4.7 FR-R — Repayment via STK Push (Payment Intents)

| ID | Requirement |
|----|-------------|
| FR-R1 | From loan/customer ops views, trigger STK Push for a specified amount against a loan context. |
| FR-R2 | **Before** calling Daraja, create an internal **Payment Intent** (UUID) with customer, loan, amount, phone, timestamps, status, expiry, attempt number. |
| FR-R3 | Safaricom identifiers returned by STK (`CheckoutRequestID`, `MerchantRequestID`, etc.) are stored as **metadata** on the intent — never as the primary join key. |
| FR-R4 | Intent status progresses through an explicit machine (e.g. pending → sent → prompted → paid → allocated → completed / expired / failed — exact names in Milestone 2). |

### 4.8 FR-X — Reconciliation engine

| ID | Requirement |
|----|-------------|
| FR-X1 | Reconciliation answers: “Does this evidence satisfy an outstanding Payment Intent?” — not “Which loan does this Safaricom ID belong to?” as the primary question. |
| FR-X2 | Matching uses internal intent first, then supporting signals (phone, expected amount, time window, intent/loan state). |
| FR-X3 | On successful match: update intent → record payment → allocate to installment(s) → update loan balances/status as rules require. |
| FR-X4 | Duplicate evidence is idempotent (no double allocation). |
| FR-X5 | Missing/late callbacks are handled via scheduler (expire/escalate) and/or manual workspace — not silent loss. |
| FR-X6 | Wrong/partial amounts follow documented allocation rules; remainder stays outstanding or is flagged. |
| FR-X7 | Concurrent allocation uses database transactions and appropriate row locking. |
| FR-X8 | Multiple outstanding loans for one customer follow a documented rule (default proposal: **oldest due first** — confirm at design time for Milestone 12). |

### 4.9 FR-W — Customer credit wallet

| ID | Requirement |
|----|-------------|
| FR-W1 | Overpayment relative to what can be allocated to due/advance installments (per documented rule) credits the **customer wallet**. |
| FR-W2 | Wallet balance is decimal, auditable (credit/debit events), and never a silent field tweak. |
| FR-W3 | Future repayments may apply wallet balance toward outstanding installments under explicit ops or automatic rules (locked in Milestone 12 design). |

### 4.10 FR-S — SMS forwarder webhook (secondary evidence)

| ID | Requirement |
|----|-------------|
| FR-S1 | Expose an authenticated webhook endpoint that accepts M-Pesa confirmation payloads from the SMS forwarder application. |
| FR-S2 | Persist raw SMS/webhook payloads for audit before parsing. |
| FR-S3 | Parsed SMS evidence enters the **same** `ReconciliationService` as Daraja callbacks, tagged with evidence source `sms_forwarder`. |
| FR-S4 | SMS is never the primary identity of a payment attempt; it may satisfy an open Payment Intent or land in unmatched/manual queues. |
| FR-S5 | Reject unauthenticated or schema-invalid payloads; do not allocate on garbage input. |

### 4.11 FR-M — Manual reconciliation & ops recovery

| ID | Requirement |
|----|-------------|
| FR-M1 | Provide a manual reconciliation workspace for unmatched or low-confidence payments/intents. |
| FR-M2 | Operator match/reject actions require a reason and write an audit record. |
| FR-M3 | Manual actions still go through allocation services (no bypass that skips wallet/installment rules). |

### 4.12 FR-K — Audit, webhooks, and observability

| ID | Requirement |
|----|-------------|
| FR-K1 | Persist webhook/callback logs (Daraja and SMS) independently of business outcome. |
| FR-K2 | Domain-significant transitions (loan status, disbursement, intent, payment, allocation, wallet) are auditable. |
| FR-K3 | Ops can view activity timelines per loan (status, disbursement, payments, allocations). |

### 4.13 FR-U — Ops UI (presentation only)

| ID | Requirement |
|----|-------------|
| FR-U1 | Vue 3 + TypeScript ops console consumes the API; no client-side authority over reconciliation math. |
| FR-U2 | Surfaces include customers, products, loans, installment progress, payment intent/payment history, manual recon, and (time permitting) light analytics. |
| FR-U3 | UX bar: executive, modern, responsive, accessible; motion and polish after APIs for each module are stable. |
