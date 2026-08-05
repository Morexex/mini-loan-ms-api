# Reconciliation engine (Milestone 12)

## Pipeline

```text
PaymentEvidence
  → AllocatingReconciliationService
       match open Payment Intent (checkout hint OR exact phone+amount)
       post Payment (idempotency_key UNIQUE)
       AllocationService (oldest due first)
       overpay → CreditWalletAction
       intent completed; loan completed if schedule clear
```

## Rules

See [ADR 0006](./adr/0006-reconciliation-allocation-rules.md).

## Expiry

`ExpireStalePaymentIntentsJob` runs every minute via the scheduler and marks non-terminal intents past `expires_at` as `expired`.

## What this is not

- Manual match UI/API — Milestone 13
- Automatic wallet drawdown on next STK — deferred (Q5)
- Cross-loan allocation — intents are loan-scoped
