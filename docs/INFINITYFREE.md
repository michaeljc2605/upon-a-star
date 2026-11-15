# Complete cinema on PHP shared hosting

The complete native PHP backend is deployed at [Upon a Star Cinema](https://upon-a-star.infinityfreeapp.com/) with InfinityFree's hosted MySQL/MariaDB database. It retains the PHP site's design, Google accounts, profiles, seat holds, bookings, ticket history, email and staff/owner operations. The homepage, catalogue, Google sign-in and owner access, saved bookings after reload, ticket email with QR images, and staff check-in with duplicate-ticket rejection have been verified on the live site. The original Express and React source remains available.

## Build the upload package

From the repository root, using Node.js 22 locally:

```sh
node scripts/package-infinityfree.mjs
```

This creates a new `build/infinityfree/` directory and refuses to replace an existing output directory. An optional path argument selects another new destination. Node is used to prepare the package locally; the uploaded website runs PHP without a Node process.

- Upload only the **contents of `build/infinityfree/htdocs/`** to the hosting account's `htdocs`, including its `.htaccess`.
- Keep `database/empty-database-import.sql`, `manifest.json` and `README.txt` outside the public web directory.
- The packager excludes private configuration, Node dependencies, original SQL exports and the independent base's installer. Its routing rules protect the PHP source/configuration directories while exposing the canonical pages, assets and same-origin API.

## Database and private configuration

1. Use an active InfinityFree hosting account with a direct free subdomain and create a **new empty database** in its control panel. Retain the exact assigned database hostname, name and username; the hostname is not necessarily `localhost`.
2. Select that empty database in phpMyAdmin and import the package's `database/empty-database-import.sql`. It contains the schema and public catalogue, removes table-drop statements and does not create or select a database. It is an **empty-database import, not a migration**: do not import it into existing data or rerun it over a partial import without inspecting the result.
3. On the host, copy `php/config.example.php` to the private, gitignored `php/config.local.php`. Keep the PHP array syntax and supply the settings below. Never commit or share the populated file.

| Setting | Hosted value |
| --- | --- |
| `PHP_BACKEND` | `native` |
| `PHP_SITE_PATH` | Empty string for the site at `/` |
| `PHP_PUBLIC_URL` | Exact `https://` origin of the hosted site, without a path |
| `BASE_DB_HOST`, `BASE_DB_PORT` | Provider-assigned MySQL host and port, normally `3306` |
| `PHP_DB_NAME` | Full provider-assigned database name |
| `BASE_DB_USER`, `BASE_DB_PASSWORD` | Provider-assigned database credentials |
| `MYSQL_SSL` | Use the provider's database connection requirements; the example targets its internal MySQL service |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | Google Web application credentials |
| `GOOGLE_REDIRECT_URI` | Exact HTTPS callback shown below |
| `GOOGLE_OWNER_EMAILS` | Comma-separated verified Google accounts intended to receive owner access |
| `EMAILJS_SERVICE_ID`, `EMAILJS_TEMPLATE_ID`, `EMAILJS_PUBLIC_KEY` | Actual EmailJS service/template settings |
| `EMAILJS_PRIVATE_KEY` | Only when required by the EmailJS account |

Use InfinityFree's own database for this deployment. The existing TiDB Starter instance remains on its free limit and is unused here: InfinityFree permits outbound ports 80, 443 and 587, blocking TiDB's port 4000. [Outbound connection restrictions](https://forum.infinityfree.com/t/can-i-connect-to-other-servers-from-my-website-sockets-curl-and-apis/49348).

## Google sign-in and ticket email

The live site's Google OAuth **Web application** uses this exact redirect. For a separate deployment, replace the hostname with that site's address:

```text
https://upon-a-star.infinityfreeapp.com/api/auth/google/callback
```

Set `PHP_PUBLIC_URL` to the site's HTTPS origin and `GOOGLE_REDIRECT_URI` to that origin followed by `/api/auth/google/callback`, matching the registered redirect exactly. The app requests only `openid email profile`; Google's Testing exception removes the test-user-list requirement and seven-day authorization expiry for those scopes. Other scopes and organizational account restrictions can change that behavior. Unconfigured sign-in reports unavailable rather than providing a substitute login. [Google audience rules](https://support.google.com/cloud/answer/15549945?hl=en), [callback rules](https://developers.google.com/identity/protocols/oauth2/web-server#uri-validation).

The package includes `/privacy.php` and `/terms.php`. Use their full hosted HTTPS URLs, together with the homepage, when configuring Google's app details.

Use the [README's EmailJS template fields](../README.md#ticket-email-configuration). PHP sends a server-side HTTPS POST, so the actual EmailJS account must permit those requests and stay within its free allowance. A failed email does not undo a saved booking; check the displayed result and retry flow. Ticket emails use external QR image URLs plus human-readable ticket codes. Live verification confirmed inbox delivery, loaded QR images and the correct movie, date, time and seat details. Recheck delivery and image loading after changing the email configuration. No payment gateway is integrated. [EmailJS send endpoint](https://www.emailjs.com/docs/rest-api/send/).

## Host limits and release checks

InfinityFree provides automatic SSL for direct free subdomains, not `www` or other nested subdomains. Use one consistent HTTPS hostname. Its mandatory browser protection requires JavaScript and cookies: same-origin browser requests work, but external API clients, webhooks, cross-origin clients and hotlinked site assets can be blocked. The PHP pages call the native backend internally; do not replace that with HTTP loopback requests. Google OAuth uses browser redirects and outbound HTTPS, which InfinityFree staff confirm is compatible with this policy. [SSL rules](https://forum.infinityfree.com/t/changes-to-ssl-for-free-subdomains/110169/1), [browser protection](https://forum.infinityfree.com/t/browser-security-system-features-and-limitations/49353), [Google login clarification](https://forum.infinityfree.com/t/anti-bot-help/112174/4).

Live browser checks on **15 November 2026** verified Google sign-in and owner access, profile saving, showtime filtering, a reservation retained after reload, ticket email received with loaded QR images and correct screening/seat details, and staff check-in followed by duplicate-ticket rejection. Recheck the following when deploying or changing configuration:

- HTTPS, desktop/mobile pages, artwork, showtimes and the real hosted database.
- Google sign-in/out, profile creation and the intended owner/staff restrictions.
- Seat selection, expired/conflicting holds, saved bookings and ticket lookup; two customers must not obtain the same seat.
- Email delivery/resend, QR readability and authorized staff check-in, including duplicate-scan handling. Camera access requires browser permission.
- Protected configuration/source paths do not expose contents, and saved records survive a new session.

`/api/health` checks the API process only; it does not establish that those integrations work. The local integration harness at `scripts/test-native-integration.mjs` uses a disposable loopback database and deliberately leaves Google and email unconfigured. Local checks do not replace hosted verification.

The supplied programme ends on **18 December 2026**. Extend movie run dates and screenings through owner tools for later use. Retain the project attribution to **Celine Emily Wijaya and Michael Joseph Candra**, **IE4727 · F34-DG02**, the [original report](project-report.pdf) and bundled third-party licences.
