# 01 — Project Understanding

**Project:** Mini Loan Management System  
**Repos:** `mini-loan-ms-api` (engine) · `mini-loan-ms-web` (ops UI)  
**Assessment:** Demulla Investments Limited — Assistant Software Development Lead  
**Document type:** Product discovery (Approach B)  
**Status:** Milestone 0 — complete  
**Next:** Milestone 1 — System architecture (`docs/02-system-design.md`)

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

---

## 5. Non-Functional Requirements

| ID | Category | Requirement |
|----|----------|-------------|
| NFR-I1 | Integrity | Money fields use fixed-precision decimals (never floating point). Allocations run inside database transactions. |
| NFR-I2 | Integrity | Concurrent payment application uses row-level locking (or equivalent) to prevent double allocation. |
| NFR-I3 | Idempotency | Replaying the same callback, SMS evidence, or operator action must not credit installments twice. |
| NFR-A1 | Auditability | Domain transitions and external webhook payloads are reconstructable from stored records without relying on Safaricom retention. |
| NFR-A2 | Auditability | Manual reconciliation actions always store actor, reason, before/after linkage. |
| NFR-S1 | Security | Input validation at the API boundary (Form Requests); mass-assignment protection; parameterized queries (Eloquent/Query Builder). |
| NFR-S2 | Security | Sanctum-authenticated ops routes; webhook endpoints authenticated (shared secret / signature) and rate-limited. |
| NFR-S3 | Security | Secrets (Daraja keys, webhook secrets) live in environment config — never committed. |
| NFR-S4 | Security | SPA XSS/CSRF posture appropriate for Sanctum cookie or token mode (locked at Milestone 4 design). |
| NFR-O1 | Observability | Structured logging for Daraja calls, reconciliation decisions, and failed matches. |
| NFR-O2 | Observability | Failed/pending Payment Intents are visible to ops (lists + manual queue). |
| NFR-P1 | Performance | Synchronous HTTP handlers stay thin; Daraja outbound calls and heavy reconciliation run via queues where practical. |
| NFR-P2 | Performance | Ops list endpoints support pagination; avoid unbounded table scans in default queries. |
| NFR-T1 | Testability | Reconciliation rules are unit/feature testable without live Daraja (HTTP client faked at the boundary; sandbox used for integration demos). |
| NFR-T2 | Testability | `php artisan migrate:fresh` succeeds from a clean clone with documented env. |
| NFR-M1 | Maintainability | Thin controllers; business rules in services/actions; stable FR/ADR docs updated per milestone. |
| NFR-M2 | Maintainability | Two-repo boundary: web never embeds allocation/reconciliation formulas. |
| NFR-U1 | Usability | Ops UI is responsive, keyboard-accessible for primary flows, and communicates async states (pending STK, failed disbursement) clearly. |
| NFR-R1 | Reliability | Scheduler handles intent expiry/escalation; missing callbacks do not leave money in an uninspectable state. |

---

## 6. Constraints

| ID | Constraint |
|----|------------|
| C1 | Stack is fixed: Laravel 12 / PHP 8.4 / MySQL / Redis queues (API); Vue 3 / TypeScript / Pinia / Vue Router / Vuetify / Tailwind (web). |
| C2 | Daraja **sandbox** B2C and STK must be real integrations — not mocked as the only path — for the assessment demo. |
| C3 | Safaricom identifiers (`CheckoutRequestID`, `MerchantRequestID`, `ReceiptNumber`, etc.) are **metadata only**, never primary join keys to loans/payments. |
| C4 | Repositories live at `/Users/dolcepay/Code/mini-loan-ms-api` and `/Users/dolcepay/Code/mini-loan-ms-web`. |
| C5 | Interest model for v1 is **flat**. |
| C6 | Overpayment disposition is **customer credit wallet** (not silent principal write-down without wallet events). |
| C7 | SMS forwarder is in scope as a **secondary** evidence webhook into the same reconciliation engine. |
| C8 | Auth for ops is **Laravel Sanctum** (SPA). |
| C9 | Loan status transitions must not skip states for convenience. |
| C10 | Constitution workflow: explain → approaches → design → small tasks → one task → teach → wait — unless Moses explicitly continues/approves. |
| C11 | Git history: **one commit per milestone** after all tasks in that milestone are complete (keeps history followable). Explicit mid-milestone commit/push requests are still honored. |
| C12 | Submission expectation: clean migrations from fresh clone; Git history readable; documentation defendable in interview. |

---

## 7. Assumptions

| ID | Assumption | If wrong |
|----|------------|----------|
| A1 | Daraja sandbox credentials and shortcode/passkey material will be available in `.env` for local/demo runs. | Disbursement/STK demos blocked until credentials exist; architecture still proceeds with clear env contracts. |
| A2 | Callback URLs can be reached by Safaricom (tunnel, hosted URL, or equivalent) during demo. | Use logged outbound + manual/SMS evidence paths; document tunnel setup in README. |
| A3 | Single ops tenant / single organization — no multi-lender partitioning in v1. | Schema stays simple; tenancy would be a later ADR. |
| A4 | Currency is **KES**; amounts are two-decimal money unless an ADR changes storage to minor units. | Affects column precision and UI formatting only. |
| A5 | Customer phone is a reliable enough M-Pesa MSISDN after normalization for sandbox testing. | Strengthen validation; support override MSISDN field later if needed. |
| A6 | The SMS forwarder app can POST a documented JSON payload (raw SMS text + metadata) to our webhook with a shared secret. | Endpoint still scaffolds; parser adapts once a sample payload is provided. |
| A7 | One primary ops user (or small set of equivalent admins) is enough for authz in v1. | Roles/permissions can deepen without redesigning the engine. |
| A8 | “Oldest due first” is an acceptable default multi-loan allocation rule until Milestone 12 design confirms or replaces it. | Swap rule in one service method + tests. |
| A9 | Flat interest schedule generation (equal installments over term) is acceptable for the assessment’s auditability goals. | Reducing-balance remains a documented non-choice, not a silent pivot. |
| A10 | Redis is available locally (Docker or native) for queues; sync queue driver is acceptable only for early bootstrap, not the final demo posture. | Document Docker Compose when foundation lands. |

---

## 8. Risks

| ID | Risk | Impact | Likelihood | Mitigation |
|----|------|--------|------------|------------|
| R1 | Daraja STK/B2C callback never arrives or arrives very late | Payment Intent stuck; ops unsure if customer paid | High (async networks) | Intent TTL + scheduler expire/escalate; manual recon queue; SMS secondary evidence |
| R2 | Duplicate callbacks or retried webhooks | Double allocation / inflated loan receipts | Medium | Idempotency keys; terminal intent states; unique constraints where safe |
| R3 | Same phone, similar amount, overlapping time windows (collision) | Wrong loan/intent credited | Medium | Intent-first matching; tight time window; low-confidence → manual hold |
| R4 | Amount paid ≠ amount requested | Partial/overpay ambiguity | Medium | Documented allocation + wallet credit rules; flag remainder |
| R5 | Sandbox flakiness or credential misconfig | Demo path fails under time pressure | Medium | Auditable outbound logs; clear `.env.example`; README runbook; retry policies |
| R6 | Callback URL not publicly reachable during local dev | No primary confirmation channel | High without tunnel | Document tunnel/hosting; rely on scheduler/manual/SMS for recovery demos |
| R7 | SMS forwarder spoofing, offline device, or parse drift | False credits or missed fallback | Medium | Shared secret; raw payload store; never allocate on invalid parse; SMS not primary |
| R8 | Two-repo API/UI contract drift | UI broken or client-side “fixes” that bypass engine | Medium | Versioned `/api/v1`; OpenAPI in API repo; no money math in web |
| R9 | Overbuilding UI / enterprise patterns (full ledger/sagas) before engine works | Miss assessment core; noisy Git history | Medium | Engine-first milestones; YAGNI; one commit per milestone |
| R10 | Float/money precision mistakes | Silent financial corruption | Low if disciplined | Decimal columns only; tests on allocation edge cases |
| R11 | Skipping loan states under deadline pressure | Undefendable audit story | Medium | Enforce transitions in domain services; reject illegal jumps |
| R12 | Unclear SMS payload from forwarder app until late | Webhook scaffold delayed or wrong | Medium | Lock sample payload early; keep raw-text field flexible |

---

## 9. Why Reconciliation Is the Hardest Part

Customer CRUD, products, and even calling Daraja are scaffolding. Reconciliation is hard because it sits at the intersection of **our ledger intent**, **an unreliable external network**, and **real money semantics**.

### 9.1 Two identities that must not be confused

| Identity | Owner | Role in our system |
|----------|-------|--------------------|
| Payment Intent UUID | **Us** | Primary key of “we asked this customer to pay this amount for this loan attempt” |
| Safaricom IDs (Checkout/Merchant/Receipt) | **Safaricom** | Metadata / confirmation hints — useful, not authoritative structure |

If we join loans primarily on Safaricom IDs, we inherit Safaricom’s availability, schema stability, timing, and retry behavior as structural dependencies. The brief forbids that. Architecturally, we ask:

> Does this piece of evidence satisfy an **outstanding Payment Intent**?

not:

> Which loan does this CheckoutRequestID belong to?

### 9.2 Evidence is asynchronous and imperfect

Callbacks can be missing, duplicated, late, under/over-amount, or interleaved with another customer’s payment on the same MSISDN pattern. SMS can arrive when HTTP does not — and can also be forged or misparsed. A serious design therefore needs:

1. **Primary path:** Intent + Daraja callback + idempotent allocate  
2. **Essential companions:** scheduler (timeout/escalate) + manual recon workspace  
3. **Optional fallback:** SMS forwarder → same engine, tagged secondary  

### 9.3 Money has side effects that must be atomic

Matching is not enough. A correct match must, in one transactional unit of work:

Payment Intent → Payment → Installment allocation(s) → Loan balances/status → Wallet credit (if overpay)

under concurrency controls so two workers cannot apply the same evidence twice.

### 9.4 Why this is what Demulla is testing

Anyone can flip `status = paid`. Few candidates design an owned identity for payment attempts, defend failure modes, and keep external network identifiers in their proper place. That is the architectural bar for this assessment — and the reason Milestone 12 outweighs UI polish.

---

## 10. Failure-Mode Matrix

How the system should behave when the happy path breaks. Implementation lands mainly in Milestones 11–13; this matrix is the contract those milestones must satisfy.

| ID | Failure | How we detect it | System behavior | Ops visibility |
|----|---------|------------------|-----------------|----------------|
| FM1 | STK/B2C **callback never arrives** | Payment Intent past TTL still non-terminal | Scheduler marks expired / escalates to manual queue; no silent “paid” | Pending/expired intent list |
| FM2 | Callback arrives **twice** (or worker retries) | Idempotency key / intent already terminal / payment already applied | ACK webhook; **no second allocation** | Webhook log shows duplicate; business state unchanged |
| FM3 | Callback **amount ≠** intent amount | Compare evidence amount to intent.amount | Apply documented partial/over rules; remainder outstanding or **wallet credit**; low-confidence → manual | Intent + payment show variance reason |
| FM4 | Callback **late** after intent expired/superseded | Evidence references expired intent or only weak signals match | Do **not** auto-allocate; create unmatched payment/evidence; manual recon | Unmatched queue |
| FM5 | **Phone collision** (similar amount, overlapping window, multiple open intents) | More than one candidate intent scores equally / below confidence threshold | Hold as unmatched; require manual match | Manual workspace with candidates listed |
| FM6 | Customer has **multiple outstanding loans** | Intent is loan-scoped (preferred); if evidence lacks strong intent link | Prefer intent’s loan; if allocating without unique intent, use **oldest due first** (A8) until M12 confirms | Allocation audit shows loan + installment targets |
| FM7 | **Partial** payment vs full installment(s) | Amount &lt; remaining due on target installment(s) | Allocate what was paid; leave installment/loan partially paid | Installment remaining balance updates |
| FM8 | **Overpayment** | Amount &gt; what allocation rules can apply to installments (per M12 ADR) | Allocate max allowed; excess → **customer credit wallet** (FR-W) | Wallet ledger event + payment record |
| FM9 | B2C **fails** after disbursement requested | Daraja error / failed result on disbursement record | Loan stays pre-disbursed; disbursement record failed; retry/manual — never imply funds sent | Disbursement detail + loan timeline |
| FM10 | B2C **success** but local transition fails mid-write | Transaction/job error after external success | Retry-safe completion from stored disbursement result; alert ops; do not create a second B2C blindly | Failed job + disbursement audit |
| FM11 | SMS arrives, **HTTP callback does not** | SMS webhook validated; open intent matches signals | Same `ReconciliationService`; evidence source=`sms_forwarder`; idempotent if callback later arrives | Evidence source visible on payment |
| FM12 | SMS **spoof / invalid / unparseable** | Auth failure or schema/parse failure | Reject; store raw payload if authenticated-but-unparseable policy allows; **never allocate** | Webhook/SMS log + reject reason |
| FM13 | Operator **manual match** error risk | Human selects intent/payment pair | Require reason; full audit; still run through allocation service (no bypass) | Audit trail with actor |
| FM14 | Concurrent STK intents for same customer | Multiple non-terminal intents | Matching prefers exact amount + window + loan context; ambiguity → manual | List of open intents on customer/loan |

---

## 11. Reconciliation Strategy Ranking

Ordered from strongest production posture to weakest. Do not reorder without an ADR and Lead approval.

| Rank | Strategy | Reliability | Role | Recommendation |
|------|----------|-------------|------|----------------|
| 1 | **Internal Payment Intent** + Daraja callback + idempotent allocation | Excellent | **Primary architecture** | Required |
| 2 | **Scheduler** on pending/expired intents (timeout, escalate) | Very high | Essential companion to (1) | Required |
| 3 | **Manual reconciliation workspace** for unmatched / suspicious items | Very high | Essential ops safety net | Required |
| 4 | **SMS forwarder webhook** → same reconciliation engine (secondary evidence) | Good as DR; operationally fragile | Optional fallback / interview differentiator | In scope as scaffolded fallback — **not** primary |
| 5 | Match solely by **phone + amount** | Weak under collisions | Supporting signal only | Avoid as main strategy |
| 6 | Depend on Safaricom IDs as **structural join keys** | Fragile; contradicts brief | Metadata only | **Forbidden** as primary design |

### 11.1 Matching question (canonical)

```text
Evidence received (Daraja callback | SMS | manual)
        ↓
Log raw evidence
        ↓
Does this satisfy an outstanding Payment Intent?
        ↓ yes (confident)          ↓ no / ambiguous
Transactional allocate             Unmatched → manual queue
Intent → Payment → Allocations
→ Installments → Loan → Wallet?
```

### 11.2 Supporting signals (never primary keys)

Used only to **select or confirm** an open Payment Intent:

- Normalized phone / MSISDN  
- Expected amount (exact or documented tolerance)  
- Time window relative to intent creation/expiry  
- Intent status (must be allocatable)  
- Loan/customer scope carried on the intent  

Safaricom IDs may corroborate after a candidate intent is found; they must not be the only way to find the loan.

---

## 12. Decision Log

| ID | Decision | Choice | Rationale | Revisit when |
|----|----------|--------|-----------|--------------|
| D1 | Interest model | **Flat** | Easier to explain, audit, and generate schedules within assessment scope | If product owners require reducing balance |
| D2 | Overpayment | **Customer credit wallet** | Explicit liability to customer; auditable credits/debits | Milestone 12 ADR for drawdown rules |
| D3 | Payment identity | **Internal Payment Intent UUID** | Owns matching without depending on Safaricom IDs | Never demote to Safaricom-primary |
| D4 | SMS forwarder | **Scaffold webhook** as secondary evidence | Real DR path + interview differentiator; not primary | After sample payload from Moses’s forwarder app |
| D5 | Auth | **Laravel Sanctum (SPA)** | Standard Vue ↔ Laravel API auth; YAGNI vs Passport | If mobile app clients appear |
| D6 | Repo layout | **`mini-loan-ms-api` + `mini-loan-ms-web`** under `/Users/dolcepay/Code/` | Engine vs UI boundary; independent deploy | If monorepo tooling becomes necessary |
| D7 | Money storage | **`decimal`** (KES, 2 dp assumed) | Avoid float corruption | ADR if switching to integer minor units |
| D8 | Disbursement | **Disbursement record + B2C**, then status | Auditable one-way transition | — |
| D9 | Multi-loan allocation default | **Oldest due first** (proposal) | Deterministic, common collections heuristic | Confirm/replace in Milestone 12 design |
| D10 | Discovery style | **Approach B** (structured PRD) | Architect signal without inception theater | — |
| D11 | Git cadence | **One commit per milestone** (after all tasks done) | Readable history for reviewers | Explicit mid-milestone commit still allowed |
| D12 | Reconciliation stack | Intent + callback + scheduler + manual (+ SMS fallback) | Matches ranked strategies §11 | — |

---

## 13. Requirements → Milestone Traceability

| Milestone | Name | Primary FR / NFR / docs coverage |
|-----------|------|----------------------------------|
| M0 | Requirements analysis | This document (`01-project-understanding.md`) |
| M1 | Architecture | G1–G2, FR-X framing → `02-system-design.md` |
| M2 | Database design | FR-C/P/L/I/D/R/W/S/K, NFR-I*, D7 → ERD |
| M3 | Laravel foundation | NFR-M1, NFR-T2, A10 → app skeleton, queues |
| M4 | Authentication | FR-A*, NFR-S2/S4, D5 |
| M5 | Customer module | FR-C* |
| M6 | Loan products | FR-P*, D1 |
| M7 | Loan origination | FR-L* |
| M8 | Installment engine | FR-I*, D1 |
| M9 | Disbursement (B2C) | FR-D*, D8 |
| M10 | STK Push + intents | FR-R*, D3 |
| M11 | Callback handling | FR-K1, FM1–FM4 ingest path |
| M12 | Reconciliation engine | FR-X*, FR-W*, §10–§11, D2/D9/D12 |
| M13 | Manual reconciliation UI | FR-M*, FM5/FM13 |
| M14 | Reporting | FR-U2 analytics / aging (time permitting) |
| M15 | Testing | NFR-T1, reconciliation edge cases |
| M16 | Deployment | A1–A2, callback reachability, hosting bonus |
| M17 | Documentation | G11, README/ADR/API polish |
| M18 | Final review | Defend §9–§11 orally; checklist vs brief |
| (cross) | SMS webhook | FR-S* — scaffold with M11/M12 ingest; sample payload unlocks parser |

---

## 14. Success Criteria (Milestone 0 → Sunday-ready product)

### 14.1 Milestone 0 exit criteria

- [x] Problem, goals/non-goals, personas, FRs documented  
- [x] NFRs, constraints, assumptions documented  
- [x] Risks + reconciliation difficulty articulated  
- [x] Failure-mode matrix + strategy ranking documented  
- [x] Decision log, traceability, success criteria, open questions (Task 0.7)  
- [x] README stubs cross-linked (Task 0.8)  
- [x] Consistency review pass (Task 0.9)  
- [x] Single Milestone 0 git commit on `mini-loan-ms-api` (and web if README changed) after Task 0.9  

### 14.2 Assessment submission criteria (end state)

| Criterion | Measure |
|-----------|---------|
| Fresh install | `migrate:fresh` succeeds from clean clone with documented `.env` |
| Real Daraja | Sandbox B2C disbursement and STK Push paths work end-to-end |
| Intent-first recon | Payments allocate via Payment Intent; Safaricom IDs are metadata only |
| Failure handling | Duplicate/missing/late/wrong-amount paths covered in code + tests + docs |
| Wallet | Overpay credits customer wallet with audit events |
| SMS fallback | Authenticated webhook accepts forwarder posts into same recon engine |
| Manual safety net | Unmatched items can be resolved with reason + audit |
| Two-repo clarity | Web has no allocation authority |
| Docs | README + architecture/ERD/API sufficient for interviewer walkthrough |
| History | Milestone-sized commits; readable narrative |

### 14.3 Task 0.9 review findings

| Check | Result |
|-------|--------|
| Maps to Demulla brief §§2–3 (customers, products, origination, B2C, STK, recon) | Pass |
| Safaricom IDs never primary keys (G2, NG6, C3, D3, §9, §11 rank 6) | Pass — consistent |
| Flat interest + wallet + SMS secondary + Sanctum + two repos | Pass — aligned with Lead decisions |
| Failure modes cover missing/dupe/late/wrong amount/collision/partial/overpay | Pass (FM1–FM14) |
| Open questions do not block Milestone 1 | Pass — Q1–Q8 needed at M4/M10–M12/M16 |
| Git cadence D11 vs early task commits | Note: Tasks 0.2–0.4 were committed before D11 locked; from Milestone 1 onward prefer **one commit per milestone**. No history rewrite. |
| Brief lifecycle vs FR-L2 | Pass — we add `disbursement_requested` for auditability (stricter than brief, still non-skipping) |
| README ↔ docs cross-links | Pass |

**Milestone 0 verdict:** Ready for Milestone 1 (Architecture). No blocking doc defects.

---

## 15. Open Questions

Blocking only if unanswered when that milestone’s design starts.

| ID | Question | Needed by | Owner | Status |
|----|----------|-----------|-------|--------|
| Q1 | Exact JSON payload from the SMS forwarder app (sample message + fields) | M11/M12 SMS parser design | Moses | **Open** |
| Q2 | Sanctum mode: cookie-based SPA vs token header — final choice | M4 design | Lead + assistant | **Resolved — cookie SPA (ADR 0005)** |
| Q3 | Payment Intent TTL duration (e.g. 15 vs 30 minutes) | M10/M12 | Lead | **Resolved — default 15 minutes** (`PAYMENT_INTENT_TTL_MINUTES`) |
| Q4 | Amount tolerance: exact match only vs allow minor variance | M12 | Lead | **Resolved — exact for phone+amount match; checkout-linked posts evidence amount (ADR 0006)** |
| Q5 | Wallet drawdown: automatic on next STK vs ops-triggered apply | M12 | Lead | **Resolved — ops-triggered only for now (ADR 0006); overpay still credits wallet** |
| Q6 | Confirm **oldest due first** for multi-loan ambiguity | M12 | Lead | **Resolved — loan-scoped intent; oldest due installment first (ADR 0006)** |
| Q7 | Public demo host vs local + tunnel only | M16 | Moses | **Open** |
| Q8 | GitHub repo visibility (private vs public for submission) | Submission | Moses | **Open** (currently private remotes exist) |
