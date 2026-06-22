# Email Magic Link for Laravel

Passwordless email authentication for Laravel — magic links and one-time codes — that works **standalone** or alongside **Laravel Fortify**.

Plenty of packages send a magic link. This one is built around two properties most of them get wrong:

### 1. A correct, no-bypass Fortify two-factor handoff

If a user has confirmed TOTP through Fortify, clicking a magic link does **not** log them in. Instead they are handed off to Fortify's own two-factor challenge in a not-yet-authenticated state, and the login only completes inside Fortify after the code is verified. There is no path that signs a two-factor user in without the second factor — and an end-to-end test runs the real Fortify challenge to keep it that way across Fortify upgrades.

### 2. Scanner-safe and prefetch-safe link consumption

The emailed link is a `GET` that **only renders a confirmation page** — it performs no authentication and no state change. The single-use token is consumed solely by an explicit `POST` from that page. Corporate email security scanners (Microsoft SafeLinks, Mimecast, Proofpoint) and browser prefetch follow the `GET` and cannot burn the link before the human clicks "Sign in".
