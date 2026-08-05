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
php artisan migrate:fresh
php artisan serve
```

Health check: [GET /api/v1/health](http://127.0.0.1:8000/api/v1/health)

Laravel also exposes [GET /up](http://127.0.0.1:8000/up).

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
| [`docs/adr/`](./docs/adr/) | ADRs |

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
- Milestone 3 — Laravel foundation + ERD migrations + Sanctum package
- Next: Milestone 4 — Authentication flows

## Git

One commit per milestone after all tasks in that milestone are complete.
