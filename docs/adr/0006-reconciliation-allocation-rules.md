# ADR 0006 — Reconciliation allocation rules (Milestone 12)

**Status:** Accepted  
**Date:** 2026-08-05  
**Deciders:** Moses (Lead) — defaults locked for assessment delivery  
**Related:** D2, D9, Q4–Q6, FR-X*, FR-W*, FM2–FM8

## Context

Milestone 12 needs explicit defaults for open product questions so allocation is deterministic and testable.

## Decision

| Question | Default |
|----------|---------|
| **Q4** Amount tolerance | **Exact** match required when linking by phone + amount. CheckoutRequestID may confirm an open intent even if evidence amount differs; **posted amount is always evidence amount** (money that arrived). |
| **Q5** Wallet drawdown | **Ops-triggered only** (not automatic on next STK). Overpay still **credits** wallet (ADR 0003). Drawdown API/UI deferred. |
| **Q6** Multi-loan rule | Payment Intents are **loan-scoped**. Allocation runs on the intent’s loan only, oldest due installment first (`due_date`, then `sequence`). No cross-loan auto-allocate. |

## Allocation algorithm

1. Lock Payment Intent + loan + open installments.
2. Create `payments` row with UNIQUE `idempotency_key` (duplicate → no-op).
3. Walk open installments oldest-due-first; apply `min(remaining_payment, installment_outstanding)`.
4. If payment remainder > 0 after all installments → wallet credit (`overpayment`).
5. If all installments `paid` → loan `completed`.
6. Intent → `completed`.

## Consequences

- Sandbox demos stay predictable (exact phone/amount matching).
- Checkout-linked variance still books real money correctly.
- Manual recon (M13) can still invoke the same `AllocationService`.
