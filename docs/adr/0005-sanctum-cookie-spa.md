# ADR 0005 — Sanctum cookie SPA authentication

**Status:** Accepted  
**Date:** 2026-08-05  
**Deciders:** Moses (Lead)  
**Related:** D5, FR-A*, Q2

## Context

The ops UI is a first-party Vue SPA. We need session-based auth without storing long-lived bearer tokens in `localStorage`.

## Decision

Use **Laravel Sanctum stateful (cookie) SPA authentication**:

1. Browser calls `GET /sanctum/csrf-cookie`
2. `POST /api/v1/login` with credentials (session regenerated)
3. Subsequent API calls send session cookie + CSRF header
4. `GET /api/v1/me` and `POST /api/v1/logout` require `auth:sanctum`

CORS allows the configured `FRONTEND_URL` with `supports_credentials = true`.  
`SANCTUM_STATEFUL_DOMAINS` includes the Vite dev host(s).

Login is rate-limited (`throttle:auth`, 5/min/IP).

## Consequences

- Resolves open question Q2 in favor of cookie SPA (not token-primary).
- Vue must use `withCredentials` / axios `withCredentials: true`.
- Domain policies can assume an authenticated ops `User` via Sanctum.
