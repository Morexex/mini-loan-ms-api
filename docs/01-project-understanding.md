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
