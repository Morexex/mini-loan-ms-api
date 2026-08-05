# ADR 0001 — Flat interest for v1

**Status:** Accepted  
**Date:** 2026-08-05  
**Deciders:** Moses (Lead)  
**Related:** D1, FR-P2, FR-I1

## Context

Loan products require an interest model. The brief allows flat or reducing balance.

## Decision

Use **flat interest** for v1 schedule generation.

## Consequences

- Simpler installment generation and audit explanations in interview.
- Reducing balance remains a documented non-choice (not a silent future pivot without a new ADR).
- Schedule service implements equal/consistent flat amortization over term (months or weeks per product field).
