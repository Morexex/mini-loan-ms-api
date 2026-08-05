# Mini Loan MS — API

Laravel backend for the **Mini Loan Management System** (Demulla technical assessment).

This repository is the **financial transaction processing engine**: customers, products, loans, installments, Daraja B2C/STK, Payment Intents, reconciliation, wallets, webhooks, and primary documentation.

The Vue app is a separate ops console. It must not own allocation or reconciliation rules.

## Companion repository

| Repo | Path | Role |
|------|------|------|
| Web (ops UI) | [`../mini-loan-ms-web`](../mini-loan-ms-web) | Presentation only |
| API (this repo) | `.` | Domain, money, integrations, docs |

Remote: https://github.com/Morexex/mini-loan-ms-api

## Documentation

| Doc | Description |
|-----|-------------|
| [`docs/01-project-understanding.md`](./docs/01-project-understanding.md) | Milestone 0 discovery: problem, FRs/NFRs, risks, failure modes, reconciliation ranking, decisions |
| [`docs/02-system-design.md`](./docs/02-system-design.md) | Milestone 1 architecture: modular domain, sequences, API sketch |
| [`docs/03-erd.md`](./docs/03-erd.md) | Milestone 2 ERD: tables, money types, intents, allocations, wallet, integrity rules |
| [`docs/adr/`](./docs/adr/) | Architecture Decision Records (interest, intents, wallet, SMS) |

**Read first:**

1. [Why reconciliation is hardest](./docs/01-project-understanding.md#9-why-reconciliation-is-the-hardest-part)
2. [System design](./docs/02-system-design.md)
3. [ERD](./docs/03-erd.md)
4. [ADR 0002 — Payment Intent identity](./docs/adr/0002-payment-intent-identity.md)

## Stack (planned)

- Laravel 12 · PHP 8.4 · MySQL · Redis / queues
- Daraja sandbox: B2C disbursement + STK Push
- SMS forwarder webhook (secondary evidence → same reconciliation engine)

## Framing

> Modular monolith with Payment-Intent-driven reconciliation. Safaricom identifiers are metadata only — never primary join keys.

## Status

- Milestone 0 — complete (discovery)
- Milestone 1 — complete (architecture)
- Milestone 2 — complete (ERD)
- Next: Milestone 3 — Laravel foundation + migrations

## Git

One commit per milestone after all tasks in that milestone are complete.
