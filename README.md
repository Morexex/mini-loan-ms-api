# Mini Loan MS — API

Laravel backend for the **Mini Loan Management System** (Demulla technical assessment).

This repository is the **financial transaction processing engine**: customers, products, loans, installments, Daraja B2C/STK, Payment Intents, reconciliation, wallets, webhooks, and primary documentation.

Companion UI: [`../mini-loan-ms-web`](../mini-loan-ms-web) · Remote: https://github.com/Morexex/mini-loan-ms-api

## Requirements

- PHP 8.4+
- Composer 2
- MySQL 8 (assessment/demo)
- Redis optional (queues/cache); database queue driver works for early bootstrap

## Quick start

```bash
cp .env.example .env
php artisan key:generate

# Create MySQL database: mini_loan_ms
# Then set DB_* in .env

composer install
php artisan migrate:fresh --seed
php artisan serve
```

Default ops user (from seeder):

- Email: `ops@miniloan.test`
- Password: `password`

### Auth (Sanctum cookie SPA)

1. `GET /sanctum/csrf-cookie` (credentials included)
2. `POST /api/v1/login` `{ "email", "password" }`
3. `GET /api/v1/me` (authenticated)
4. `POST /api/v1/logout`

Configure `FRONTEND_URL` and `SANCTUM_STATEFUL_DOMAINS` for the Vite origin. See [ADR 0005](./docs/adr/0005-sanctum-cookie-spa.md).

Health check: [GET /api/v1/health](http://127.0.0.1:8000/api/v1/health)

Laravel also exposes [GET /up](http://127.0.0.1:8000/up).

### Webhooks (no Sanctum)

| Method | Path | Notes |
|--------|------|-------|
| POST | `/webhooks/daraja/stk` | STK callback → `WebhookLog` → `PaymentEvidence` → queue |
| POST | `/webhooks/daraja/b2c` | B2C result sink (raw log) |
| POST | `/webhooks/sms-forwarder` | Header `X-Sms-Forwarder-Secret` = `SMS_FORWARDER_WEBHOOK_SECRET` |

Milestone 11 links evidence to an open Payment Intent and **Milestone 12 allocates**: posts `payments`, applies installments oldest-due-first, credits wallet on overpay, completes the intent.

### SQLite smoke test (optional)

```bash
# In .env: DB_CONNECTION=sqlite and ensure database/database.sqlite exists
touch database/database.sqlite
php artisan migrate:fresh
```

## Documentation

| Doc | Description |
|-----|-------------|
| [`docs/01-project-understanding.md`](./docs/01-project-understanding.md) | Milestone 0 discovery |
| [`docs/02-system-design.md`](./docs/02-system-design.md) | Milestone 1 architecture |
| [`docs/03-erd.md`](./docs/03-erd.md) | Milestone 2 ERD (migrations implement this) |
| [`docs/04-installment-engine.md`](./docs/04-installment-engine.md) | Milestone 8 flat schedule formula + immutability |
| [`docs/05-reconciliation-engine.md`](./docs/05-reconciliation-engine.md) | Milestone 12 allocation + wallet overpay |
| [`docs/adr/`](./docs/adr/) | ADRs (incl. Sanctum cookie SPA, allocation rules) |

## Application layout (Approach B)

```text
app/Domain/           # Customers, Loans, Payments, Reconciliation, ...
app/Infrastructure/   # Daraja, SmsForwarder, Persistence
app/Enums/            # Loan/payment/wallet status enums
app/Http/Controllers/Api/V1/
config/daraja.php     # Sandbox + SMS webhook secret
```

## Status

- Milestones 0–2 — docs complete
- Milestone 3 — Laravel foundation + ERD migrations
- Milestone 4 — Sanctum cookie SPA auth + ops seeder
- Milestone 5 — Customer module (normalize phone, wallet, audit)
- Milestone 6 — Loan products (flat interest only)
- Milestone 7 — Loan origination + approval schedule generation
- Milestone 8 — Installment engine hardening + read API
- Milestone 9 — Disbursement via Daraja B2C (auditable + fakeable gateway)
- Milestone 10 — Payment Intents + STK Push (intent-first)
- Milestone 11 — Callback handling (STK/SMS webhooks → PaymentEvidence → ingest reconciliation)
- Milestone 12 — Reconciliation engine (allocate installments + wallet overpay + intent TTL expiry)
- Next: Milestone 13 — Manual reconciliation dashboard

## Git

One commit per milestone after all tasks in that milestone are complete.
