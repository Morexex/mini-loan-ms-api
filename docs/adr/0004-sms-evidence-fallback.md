# ADR 0004 — SMS forwarder as secondary evidence

**Status:** Accepted  
**Date:** 2026-08-05  
**Deciders:** Moses (Lead)  
**Related:** D4, D12, FR-S*, rank 4 in reconciliation strategies

## Context

HTTP callbacks can fail while the customer still receives an M-Pesa SMS confirmation. An Android SMS forwarder can POST that SMS to our API.

## Decision

Scaffold an authenticated **SMS forwarder webhook**. Parsed messages become `PaymentEvidence` with `source=sms_forwarder` and enter the **same** `ReconciliationService` as Daraja callbacks.

SMS is **not** a primary identity source and must not bypass intent matching.

## Consequences

- Operational fragility (device online, parse drift, spoofing) is accepted for DR/demo value.
- Shared secret + raw payload logging + reject-on-invalid-parse are mandatory.
- Sample forwarder payload (Q1) unlocks parser details at M11/M12; endpoint can scaffold earlier.
