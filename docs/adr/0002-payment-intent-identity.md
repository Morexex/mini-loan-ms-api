# ADR 0002 — Payment Intent as primary payment identity

**Status:** Accepted  
**Date:** 2026-08-05  
**Deciders:** Moses (Lead)  
**Related:** D3, D12, FR-R*, FR-X*, NG6

## Context

Daraja returns identifiers such as `CheckoutRequestID`, `MerchantRequestID`, and `ReceiptNumber`. Using them as primary join keys to loans couples our ledger to Safaricom’s availability, schema, and timing — which the assessment forbids as structural dependency.

## Decision

Before any STK call, create an internal **Payment Intent** (UUID) that records customer, loan, amount, phone, timestamps, status, expiry, and attempt number. Safaricom IDs are stored as **metadata only**.

Reconciliation’s primary question: *Does this evidence satisfy an outstanding Payment Intent?*

## Consequences

- Matching uses intent + supporting signals (phone, amount, time window, state).
- Duplicate/missing/late callbacks are handled via idempotency, scheduler, and manual queue.
- SMS and Daraja both produce `PaymentEvidence` aimed at intents — not at Safaricom PKs.
