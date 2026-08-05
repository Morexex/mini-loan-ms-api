# 04 — Installment Engine

**Milestone:** 8  
**Service:** `App\Domain\Installments\InstallmentScheduleService`  
**Related:** ADR 0001 (flat interest), FR-I*

## When schedules are created

Installments are generated **once** when a loan transitions `pending → approved` (`ApproveLoanAction`).

Rules:

- If installments already exist, approval/regeneration is rejected.
- Illegal status transitions are rejected by `LoanStatusGuard`.
- The schedule is read-only via API after creation (no update/delete endpoints in v1).

## Flat interest formula (v1)

Given:

- `P` = loan `principal_amount`
- `R` = product `interest_rate` (percent)
- `F` = product `fee_amount`
- `N` = product `term_length` (periods)
- `unit` = `months` | `weeks`

Then:

```text
total_interest = round(P × R / 100, 2)
total_fee      = round(F, 2)

Each of principal, interest, and fee is divided evenly across N periods
at 2 decimal places. Any remainder cents are applied to the **last** installment.

amount_due[i] = principal_due[i] + interest_due[i] + fee_due[i]
```

Interpretation: **R is a flat charge for the whole term**, not a reducing-balance APR amortization. This matches ADR 0001 (auditability over actuarial precision for the assessment).

## Due dates

```text
due_date[i] = approved_at + i periods
```

- `months` → `addMonthsNoOverflow(i)`
- `weeks` → `addWeeks(i)`

## API

| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/v1/loans/{loan}` | Includes `installments` when loaded |
| GET | `/api/v1/loans/{loan}/installments` | Dedicated installment collection |

## Status values

Installments start as `scheduled`. Later milestones (repayment/reconciliation) move them through `due` / `partially_paid` / `paid` / `overdue`.
