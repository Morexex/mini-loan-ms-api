# ADR 0003 — Credit wallet for overpayment

**Status:** Accepted  
**Date:** 2026-08-05  
**Deciders:** Moses (Lead)  
**Related:** D2, FR-W*, FM8

## Context

STK or manual evidence may exceed what allocation rules can apply to installments.

## Decision

Excess funds credit a **customer credit wallet** via auditable wallet events (never a silent balance field edit). Drawdown rules (automatic vs ops-triggered) are finalized in Milestone 12 design (see open question Q5).

## Consequences

- Wallet is a first-class domain module with decimal money and event history.
- Overpay path is interview-defensible and reconstructable from audit logs.
- Allocation service remains the only writer of wallet credits from payments.
