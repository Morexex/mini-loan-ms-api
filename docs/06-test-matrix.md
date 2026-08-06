# Test matrix (Milestone 15)

Automated coverage for Mini Loan MS API. Run:

```bash
php artisan test
```

Fake Daraja is used in `APP_ENV=testing` — no live Safaricom calls.

## Suite map

| Area | Location | What it proves |
|------|----------|----------------|
| Auth | `tests/Feature/Auth` | Sanctum cookie SPA login/logout/me |
| Customers | `tests/Feature/Customers` | Phone normalize, wallet create, uniqueness |
| Products | `tests/Feature/LoanProducts` | Flat interest only |
| Origination | `tests/Feature/Loans` | Approve → schedule; disburse B2C fake |
| Installments | `tests/Unit/.../InstallmentScheduleServiceTest` + API | Flat formula + remainder cents |
| Payment Intents | `tests/Feature/Payments` | Intent-first STK submit |
| Webhooks | `tests/Feature/Webhooks` | STK/SMS ingest, ACK, idempotent duplicate |
| Reconciliation | `tests/Feature/Reconciliation` | Allocate, partial, overpay→wallet, expire, manual match |
| Edge cases | `ReconciliationEdgeCasesTest` | Phone collision, ambiguity, full payoff, terminal ignore |
| Reports | `tests/Feature/Reports` + unit | Overview + aging buckets |
| Health | `tests/Feature/HealthEndpointTest` | Public health |

## Failure modes ↔ tests

| FM | Covered by |
|----|------------|
| FM1 Missing callback / TTL | `test_scheduler_expires_stale_intents`, `test_expired_intent_is_not_allocated` |
| FM2 Duplicate callback | Webhook duplicate tests + `test_duplicate_evidence_does_not_double_allocate` |
| FM3 Amount variance | `test_checkout_linked_amount_variance_posts_evidence_amount` |
| FM4 Late after expire | Expired not auto-allocated; manual match recovers |
| FM5 Phone collision | `test_phone_collision_matches_exact_amount_only` |
| FM6 Multi-loan | Loan-scoped intents (allocation never crosses loans) |
| FM8 Overpayment | `test_overpayment_credits_customer_wallet` |
| FM11 SMS secondary | `SmsForwarderWebhookTest` |
| FM13 Manual match | `ManualReconciliationTest` |

## Demo / sandbox

Live Daraja sandbox is **out of band** for PHPUnit. Use `DARAJA_FAKE=false` + credentials for manual demos only.
