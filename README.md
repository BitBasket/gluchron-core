# bitbasket/mycgm-core

Shared **engine** for the LibreLink glucose dashboard: PHP poller, `/api/keys` + `/api/librelink/*`, PGP snapshots, and the PWA.

This is a Composer library (`type: library`). It is **not** a product. Products consume it:

| App | Role |
| --- | --- |
| **MyLibre** | One dashboard at `/`. Optional proxy of this API. |
| **MyLibre-Cloud** | N dashboards at `/t/<id>/`. Cloud **wraps** this API; it does not fork it. |

Until GitHub remotes exist, apps path-require this tree (`../mycgm-core`, `dev-trunk`). Do not copy `src/` or `pwa/` into an app.

## License

Dual-licensed. Use **either**:

- Small Business License (SBL) 1.0.0 — `LICENSE.md`
- Open Source Software Alliance License (OSSAL) v1.0 — `LICENSE.ossal.txt`

OSSAL **forbids** using this package in any copyleft-licensed project (GPL, AGPL, LGPL, or any other license that requires disclosure of source code). Redistributions must keep both licenses.

Copyright 2026 BitBasket, FZC-LLC. Third-party files under `pwa/vendor/` keep their own licenses.

## What this package is

- `src/LibreLink/`, `src/Poller/GlucosePoller.php`, buckets, PGP, DTOs
- Dashboard HTTP: `App\Http\Kernel` — prefix-agnostic. It sees `/api/keys` and `/api/librelink/*`, never `/t/<id>/…`
- PWA in `pwa/` (`index.html`, `app.js`, `app.css`, `pgp.js`, service worker, vendor)

Not here: `Tenant*`, `POST /api/tenants`, `signup.html`, Docker, nginx, `bin/serve.php`.

## Cloud wrap (stays in MyLibre-Cloud)

Cloud may add pages and routes this library must not ship. Cloud may **not** own a different graph, a different `pgp.js`, or a different `/api/keys` / `/api/librelink` implementation.

1. Parse `/t/<id>`, 404 unknown ids and **unscoped** `/api/keys` + `/api/librelink/*`.
2. Construct `KeyEnrollmentHandler($tenant->userPublicKeyPath())` and call this Kernel/handlers with the **stripped** path.
3. For LibreLink intake, pass the already-parsed id into `AuthIntakeHandler` (factory `(string $id): ?authenticator`, or `X-Dashboard-Id` on the loopback proxy). The engine does not know what a tenant is.
4. `LibreLinkUpProvider(..., useConfiguredCredentials: false)` so one `.env` login is not applied to every dashboard.
5. Serve `pwa/` at `/app.js` etc.; `/` is signup; `/t/<id>/` is this `index.html`. Relative fetches (`api/keys`, `current.json.asc`) resolve under the capability URL.

## PWA

One `app.js`. Relative snapshot/API URLs. Absolute `/app.js`, `/app.css`, `/vendor/…`.

When the page is at `/t/<22-char-id>/`, localStorage / IndexedDB keys include that id so two dashboards in one browser do not share a vault. Self-host has no such prefix; those keys are unscoped.

## Tests

```bash
composer install
vendor/bin/phpunit
```
