# Changelog

All notable changes to Connectix are documented in this file.

## [4.0.0] - 2026-09-30

First Laravel release. A drop-in replacement for the legacy PHP bot with
full business-logic parity, running webhook-only on shared hosting.

### Added

- Complete Telegram webhook bot: menus, plan purchase, renewal, free-test
  accounts, guides, coupons, wallet, card/receipt payments, profile and
  download links — every legacy flow and message preserved.
- Laravel admin panel: approvals, clients, plans, orders, coupons, wallet,
  settings, broadcast and audit, behind throttled authentication.
- First-run installation wizard (`/setup`) covering requirements, database,
  migrations, panel and bot credentials, webhook, admin account and import.
- Legacy data migration (`legacy:import` with dry-run + `legacy:verify`).
- Sync and scheduled tasks: panel client sync, profile refresh, wallet
  import, deposit pruning and duplicate-update ledger.
- Broadcast with test-to-admin mode, media rules and SSE progress stream.
- Production deployment guide with release verification and rollback
  (`docs/deployment.md`).

### Fixed

- Idempotent GETs now survive transient panel timeouts (a live paid order
  was lost to one 10s connect timeout on `clients/show`); POSTs are never
  retried so no duplicate accounts can be created.
- Renewal of an account whose plans have all expired now shows its last
  purchased plan instead of claiming the account has none.
- Client listing calls `/v1/seller/clients`, the endpoint the live panel
  actually serves (the bare `/v1/seller` answered 404).
- Loopback tunnel proxy is trusted and URLs are forced to https behind it.

### Security

- Secrets removed from source; `.env` and `config.php` stay out of git.
- Fail-closed webhook secret validation, throttled admin login with decoy
  hash, CSRF on all state changes, role-gated routes.
- Upload validation (broadcast media, receipts), path-traversal guard on
  guides, SSRF guard on link downloads, redacted logs, CSP/security headers.

## [3.3.6] - legacy

Final legacy PHP release, kept in-tree as the rollback target.

[4.0.0]: https://github.com/MehdiSalari/Connectix-Bot/releases/tag/v4.0.0
