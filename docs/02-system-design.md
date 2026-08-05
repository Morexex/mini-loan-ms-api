# 02 — System Design

**Project:** Mini Loan Management System  
**Repos:** `mini-loan-ms-api` (engine) · `mini-loan-ms-web` (ops UI)  
**Milestone:** 1 — Architecture  
**Status:** Complete  
**Architecture style:** Approach B — Modular domain + ports (pragmatic modular monolith)

**Upstream:** [`01-project-understanding.md`](./01-project-understanding.md)  
**Downstream (planned):** `03-erd.md` (Milestone 2), OpenAPI (later)  
**ADRs:** [`adr/`](./adr/)

---

## 1. Purpose of this document

This document defines **how** the system is structured so that:

- The Vue app remains an ops console (no money math).
- Laravel owns a **financial transaction processing engine**.
- Daraja and SMS are **evidence adapters**, not sources of payment identity.
- Reconciliation asks: *Does this evidence satisfy an outstanding Payment Intent?*

It does **not** install Laravel/Vue or define final SQL (Milestones 2–3).

---

## 2. Architecture decision (Approach B)

| Option | Summary | Outcome |
|--------|---------|---------|
| A — Classic layered Laravel | Controllers → Services → Eloquent | Not the primary organizing shape |
| **B — Modular domain + ports** | Domain modules + Actions + `DarajaGateway` + Evidence → Reconciliation | **Selected** |
| C — Hexagonal + event-sourced ledger | Aggregates, outbox, double-entry | Rejected (YAGNI / NG4) |

**Why B:** Clear module ownership, testable boundaries, interchangeable evidence sources (Daraja callback, SMS forwarder, manual), without enterprise ceremony that burns the assessment window.

---

## 3. Locked decisions this design must honor

| ID | Decision |
|----|----------|
| D1 | Flat interest |
| D2 | Overpayment → customer credit wallet |
| D3 | Internal **Payment Intent UUID** is primary payment-attempt identity; Safaricom IDs are metadata only |
| D5 | Laravel Sanctum (SPA) |
| D6 | Two repositories: API engine + Web UI |
| D8 | Disbursement record + B2C before status success |
| D11 | One git commit per milestone after all tasks complete |
| D12 | Reconciliation stack: Intent + Daraja callback + scheduler + manual (+ SMS fallback) |

`FR-*`, `NFR-*`, and `FM*` in `01-project-understanding.md` remain binding.

---

## 4. Framing (interview one-liner)

> Modular monolith: domain modules own loan and money transitions; Infrastructure adapters turn Daraja/SMS into PaymentEvidence; ReconciliationService matches evidence to Payment Intents we own — never the other way around.

---

## 5. Context & containers

### 5.1 System context

```text
[Ops Officer] ──HTTPS──► [mini-loan-ms-web]
                              │
                              │ REST /api/v1 + Sanctum
                              ▼
                         [mini-loan-ms-api]
                        /        |        \
                       ▼         ▼         ▼
                   [MySQL]   [Redis]   [Daraja Sandbox]
                                       ▲
[SMS Forwarder] ──POST webhook─────────┤
[Daraja]        ──STK/B2C callbacks────┘
```

### 5.2 Engine vs UI boundary

| Layer | May do | Must not do |
|-------|--------|-------------|
| `mini-loan-ms-web` | Auth UX, forms, timelines, trigger approve/disburse/STK, manual recon UI | Allocation formulas, wallet math, intent matching, status skips |
| `mini-loan-ms-api` | All domain transitions, Daraja calls, reconciliation, audit | Trust Safaricom IDs as primary join keys |

### 5.3 Container / module view (API)

```text
┌──────────────────────────────────────────────────────────┐
│ HTTP: Controllers · Form Requests · Resources · Policies   │
│ Auth: Sanctum · Rate limits · Webhook secrets              │
└────────────────────────────┬─────────────────────────────┘
                             ▼
┌─────────────┐ ┌─────────────┐ ┌──────────────────────────┐
│ Customers   │ │ LoanProducts│ │ Loans (lifecycle)        │
└─────────────┘ └─────────────┘ └────────────┬─────────────┘
                                             ▼
                               ┌──────────────────────────┐
                               │ Installments             │
                               └────────────┬─────────────┘
        ┌──────────────────────────────────┼──────────────────────────┐
        ▼                                  ▼                          ▼
┌───────────────┐                ┌─────────────────┐        ┌─────────────┐
│ Disbursements │                │ Payments        │        │ Wallet      │
│ (+ B2C job)   │                │ Intents/Alloc   │        │             │
└───────┬───────┘                └────────┬────────┘        └─────────────┘
        │                                 │
        ▼                                 ▼
┌───────────────┐                ┌─────────────────┐
│ DarajaGateway │◄───────────────│ Reconciliation  │
│ (port + impl) │                │ ← PaymentEvidence│
└───────────────┘                └────────┬────────┘
                                          ▲
              adapters: DarajaCallback · SmsForwarder · Manual
                                          │
                               Jobs · Scheduler · Audit · WebhookLog
```

---

## 6. Domain module responsibilities

| Module | Owns | May not |
|--------|------|---------|
| **Customers** | Profile, phone normalize/unique, customer audit | Loan math, payments |
| **LoanProducts** | Templates: flat rate, term, fees | Per-customer state |
| **Loans** | Application, status machine, guards on transitions | Call Daraja directly; allocate payments |
| **Installments** | Schedule generation (flat), due/paid/remaining | External I/O |
| **Disbursements** | Disbursement records, orchestrate B2C via gateway, drive disbursed/active | Mark disbursed without a record |
| **Payments** | PaymentIntent, Payment, PaymentAllocation entities/actions | Parse Safaricom payloads into loan joins |
| **Wallet** | Credit/debit events, balance | Invent overpay rules outside Reconciliation/Payments policy |
| **Reconciliation** | Match `PaymentEvidence` → Intent; invoke allocate; unmatched queue | Depend on Safaricom IDs as PK |
| **Audit** | Append-only domain audit entries | Business decisions |
| **Infrastructure/Daraja** | OAuth, B2C, STK, map callbacks → Evidence DTO | Touch installment rows |
| **Infrastructure/SmsForwarder** | Auth webhook, parse → Evidence DTO | Primary matching authority |

**Shared DTO:** `PaymentEvidence` — `source`, `phone`, `amount`, `occurred_at`, `raw_reference` (optional metadata), `idempotency_key`, `raw_payload_id`.

---

## 7. Folder structure (target at Milestone 3+)

```text
app/
  Domain/
    Customers/
    LoanProducts/
    Loans/
    Installments/
    Disbursements/
    Payments/
    Wallet/
    Reconciliation/
    Audit/
  Infrastructure/
    Daraja/              # DarajaGateway + SandboxDarajaGateway
    SmsForwarder/
    Persistence/         # Eloquent models (or colocated per domain — ADR at M3)
  Http/
    Controllers/Api/V1/
    Requests/
    Resources/
    Middleware/
  Enums/
  Jobs/
  Policies/
docs/
  01-project-understanding.md
  02-system-design.md
  03-erd.md              # M2
  adr/
    0001-flat-interest.md
    0002-payment-intent-identity.md
    0003-credit-wallet-overpayment.md
    0004-sms-evidence-fallback.md
```

Web (later): `src/features/{auth,customers,products,loans,payments,reconciliation}/`.

---

## 8. Sequence diagrams

### 8.1 Origination & schedule

```text
Ops → Web → POST /customers → CreateCustomerAction
  → normalize phone → unique check → Customer + Audit

Ops → POST /loans → CreateLoanApplicationAction
  → Customer + Product → loan status=pending

Ops → POST /loans/{id}/approve → ApproveLoanAction
  → guard pending→approved
  → InstallmentScheduleService.generate(flat)
  → persist installments + Audit
```

### 8.2 Disbursement (B2C)

```text
Ops → POST /loans/{id}/disburse → DisburseLoanAction
  → guard: approved
  → create Disbursement(pending)
  → loan → disbursement_requested
  → queue SendB2cDisbursementJob
       → DarajaGateway.b2c(...)
       → store request/response on Disbursement
       → success: loan disbursed → active (schedule live)
       → failure: disbursement failed; loan remains pre-disbursed
```

### 8.3 STK Push + Payment Intent

```text
Ops → POST /loans/{id}/payment-intents
  → CreatePaymentIntentAction (UUID, amount, phone, expires_at, attempt)
  → queue InitiateStkPushJob
       → DarajaGateway.stkPush(...)
       → store Checkout/Merchant IDs as metadata on Intent only
```

### 8.4 Callback → Reconciliation

```text
Daraja → POST /webhooks/daraja/stk
  → WebhookLog(raw)
  → map to PaymentEvidence
  → queue ReconcilePaymentEvidenceJob
       → ReconciliationService
            find outstanding Intent (phone, amount, window, state, loan scope)
            confident match?
              yes → DB txn + row locks:
                    Intent terminal paid/allocated
                    Payment + Allocations → Installments → Loan
                    overpay → Wallet credit
              no  → unmatched queue (manual)
```

### 8.5 SMS fallback (same engine)

```text
SMS App → POST /webhooks/sms-forwarder (shared secret)
  → WebhookLog
  → parse → PaymentEvidence(source=sms_forwarder)
  → same ReconcilePaymentEvidenceJob / ReconciliationService
  → idempotent if Daraja callback arrives later
```

### 8.6 Scheduler (missing callback)

```text
Schedule → ExpireStalePaymentIntentsJob
  → non-terminal intents past TTL → expired / escalate to manual
```

---

## 9. API surface sketch (`/api/v1`)

### 9.1 Ops (Sanctum)

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/login` | Auth |
| POST | `/logout` | Auth |
| GET | `/me` | Current user |
| GET/POST | `/customers` | List/create |
| GET/PATCH | `/customers/{id}` | View/update |
| GET | `/customers/{id}/wallet` | Wallet balance + recent events |
| GET/POST | `/loan-products` | Products |
| GET/PATCH | `/loan-products/{id}` | Product detail |
| GET/POST | `/loans` | List/create application |
| GET | `/loans/{id}` | Detail + timeline payload |
| POST | `/loans/{id}/approve` | Approve + generate schedule |
| POST | `/loans/{id}/disburse` | Start B2C disbursement |
| POST | `/loans/{id}/payment-intents` | Create intent + STK |
| GET | `/payment-intents/{uuid}` | Intent status |
| GET | `/reconciliation/unmatched` | Manual queue |
| POST | `/reconciliation/matches` | Manual match (reason required) |

### 9.2 Webhooks (no Sanctum; secret/signature + rate limit)

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/webhooks/daraja/stk` | STK callback |
| POST | `/webhooks/daraja/b2c` | B2C result callback (if used) |
| POST | `/webhooks/sms-forwarder` | SMS evidence |

Exact path names may be refined at implementation; semantics stay.

---

## 10. Cross-cutting rules

| Concern | Rule |
|---------|------|
| Validation | Form Requests at HTTP; domain guards on illegal status jumps |
| Money | `decimal` only; allocations in DB transactions |
| Concurrency | Row locks on intent/installment/loan rows during allocate |
| Idempotency | Evidence `idempotency_key` + terminal intent states |
| Authz | Sanctum + policies on ops routes |
| Webhooks | Authenticate secret first; always persist raw log; enqueue work |
| Queues | Outbound Daraja + reconciliation off the request thread |
| Audit | Every significant transition writes audit (who/what/when/why) |
| Testing | Fake `DarajaGateway` in automated tests; sandbox for live demo |
| Errors | Domain exceptions → consistent API error resources |

---

## 11. Failure modes (architecture binding)

FM1–FM14 in `01-project-understanding.md` §10 are binding. Unmatched evidence is a **first-class** queue, not a log-only afterthought.

---

## 12. ADR index

| ADR | Title | Status |
|-----|-------|--------|
| [0001](./adr/0001-flat-interest.md) | Flat interest for v1 | Accepted |
| [0002](./adr/0002-payment-intent-identity.md) | Payment Intent as primary identity | Accepted |
| [0003](./adr/0003-credit-wallet-overpayment.md) | Credit wallet for overpayment | Accepted |
| [0005](./adr/0005-sanctum-cookie-spa.md) | Sanctum cookie SPA auth | Accepted |
| [0006](./adr/0006-reconciliation-allocation-rules.md) | Reconciliation allocation rules (Q4–Q6) | Accepted |

---

## 13. Milestone 1 exit checklist

- [x] Approach B documented  
- [x] Context/container + engine vs UI boundary  
- [x] Domain module responsibilities  
- [x] Target folder structure  
- [x] Sequences: origination, B2C, STK, callback, SMS, scheduler  
- [x] API + webhook surface sketch  
- [x] Cross-cutting rules  
- [x] Four ADRs  
- [x] README links updated  

**Next:** Milestone 2 — Database / ERD (`03-erd.md`).
