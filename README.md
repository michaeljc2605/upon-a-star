# Upon a Star Cinema — F34-DG02

An IE4727 cinema-booking project by **Celine Emily Wijaya** and **Michael Joseph Candra**, group **F34-DG02**.

This repository contains the complete PHP application, its original Express API, optional React interface, an opt-in native PHP backend, public database snapshots, artwork, tests and bundled library licences. The cinema uses a persistent MySQL/MariaDB database for accounts, screenings, bookings and tickets.

- [Live website](https://upon-a-star.infinityfreeapp.com/)
- [Source repository](https://github.com/michaeljc2605/upon-a-star)
- [Hosting and publication guide](docs/DEPLOYMENT.md)
- [Native PHP deployment for InfinityFree](docs/INFINITYFREE.md)
- [Free hosting status and limitations](docs/FREE-HOSTING.md)
- [Original project report](docs/project-report.pdf)

The live site runs the complete native PHP backend on InfinityFree with its hosted MySQL/MariaDB database, retaining the original PHP pages and design. The homepage, catalogue, Google sign-in and owner access, saved bookings after reload, ticket email with QR images, and staff check-in with duplicate-ticket rejection have been verified on the live site. The original mode uses PHP, Node.js and a persistent database. The original project assumes payment happens externally and does not integrate a payment provider.

The [InfinityFree guide](docs/INFINITYFREE.md) covers the prepared native package, private configuration and fresh database import. This is the complete cinema path, including account, booking and staff/owner operations. The independent assessed base and the original Node/React source remain available; the local installation instructions below retain their original setup.

## Validate the source

Use Node.js 22, then run these commands from the repository root:

```sh
node scripts/check-publication.mjs
npm ci --ignore-scripts
npm run check
```

The validation workflow also checks PHP syntax, builds the deployment image, and exercises a fresh MySQL database and restart. It does not deploy a website or validate private Google/email configuration. The original installation instructions below retain the `SilverVillage` folder name and local routes used by the submitted project.

## Install and import the databases

1. Extract this ZIP into XAMPP's `htdocs` directory. Keep the folder name `SilverVillage`.
2. Start Apache on **port 80** and MySQL/MariaDB on **port 3306**. Use PHP 8.0+ with PDO MySQL; the enhanced site also needs PHP cURL. Enable Apache `mod_rewrite` and allow the included `.htaccess` files.
3. On a fresh installation, use phpMyAdmin's **Import** tab to import both files:

| File | Database and purpose |
| --- | --- |
| `database/submission/silver_screen.sql` | `silver_screen`: complete PHP-led site and optional React client |
| `database/submission/silver_screen_base.sql` | `silver_screen_base`: independent assessed PHP base |

These exports include the complete schema, movies, screenings, rooms, and seat maps. They recreate application tables, so do not import them over data you need to keep. Do not run the older seed files, migrations, or installer after importing these snapshots.

Existing customer records, accounts, sessions, and bookings are omitted. Create new bookings when demonstrating the imported project. Private service credentials are not included.

## Assessed PHP base

Open **http://localhost/SilverVillage/base/**.

This version runs with Apache and MySQL alone, including guest booking and confirmation. No Node service or Google account is needed.

PHP defaults to MySQL at `127.0.0.1:3306`, user `root`, and an empty password. For a different connection, set `BASE_DB_HOST`, `BASE_DB_PORT`, `BASE_DB_USER`, and `BASE_DB_PASSWORD` in Apache's environment, then restart Apache. PHP does not read Node's `.env` for its database connection.

## Complete PHP-led site

These are the original Express-backed local installation steps. For shared hosting without Node, use the separate [native PHP deployment guide](docs/INFINITYFREE.md).

1. Install Node.js 22 and npm. Open a terminal in the `SilverVillage` folder and run `npm ci`.
2. Copy `server/.env.example` to `server/.env`. Set the MySQL connection values and keep `MYSQL_DATABASE=silver_screen`. If these differ from the defaults above, also configure Apache's matching `BASE_DB_*` values.
3. Supply `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET`. Register the callback `http://localhost:4000/api/auth/google/callback` in the Google OAuth configuration. Keep the example's localhost origin settings.
4. Set `GOOGLE_OWNER_EMAILS` to the intended owner's Google email address. That verified account receives owner access on sign-in; the owner can assign staff access in the dashboard.
5. Run `npm run dev:server`, which starts Express on **port 4000**.
6. Open **http://localhost/SilverVillage/**. Use `localhost` consistently in the browser so login cookies work across the PHP site and API.

Enhanced booking, profile/history, and owner/staff tools need the API and Google sign-in. The catalogue remains browsable without the account service. The `php/` directory contains enhanced source files; the public site opens at `/SilverVillage/`.

## Optional React interface

With the API running, open another terminal in the project folder and run `npm run dev:client`. Open **http://localhost:5173**. This interface shares the `silver_screen` database with the complete PHP-led site.

## Ticket email configuration

Set `EMAILJS_SERVICE_ID`, `EMAILJS_TEMPLATE_ID`, and `EMAILJS_PUBLIC_KEY` in `server/.env` for the original mode, or in the private `php/config.local.php` for native mode. Set `EMAILJS_PRIVATE_KEY` only if the EmailJS service requires it. In EmailJS, set the template's **Subject** to `{{email_subject}}`, **To Email** to `{{email}}`, and **From Name** to `Upon a Star Cinema`.

A minimal compatible template body is:

```html
<h2>Upon a Star Cinema</h2>
<p>Your tickets are confirmed. Booking: {{order_id}}</p>
<p>{{ticket_details}}</p>
{{#orders}}
  <p>{{name}}<br>Ticket: {{ticket_code}}</p>
  <img src="{{qr_url}}" width="220" height="220" alt="QR code for {{ticket_code}}">
{{/orders}}
<p>Booking total: Rp {{cost.total}}</p>
<p>Payment is handled separately; this email does not confirm payment.</p>
```

Each order contains one seat's ticket code and QR link. Keep the code visible as text because email clients can hide remote images. A failed email does not cancel the saved booking. The base version does not send email. Camera scanning needs browser permission. Payment is assumed to happen externally; no payment provider is integrated.

## Programme dates and source

The native PHP backend supports an opt-in [rolling programme](docs/ROLLING-PROGRAMME.md) for the current 18 published films through **31 December 2099**. Once enabled with the incremental SQL migration, visits replenish a **45-day timetable** in Singapore time, with no monthly schedule reset or scheduled job. Existing bookings, screenings and owner cancellations remain intact.

The original database snapshot ends on **18 December 2026**. Local installations using the original Node backend or independent base retain manual scheduling: extend film run dates and add screenings through the owner tools. The base's `base/database/refresh-schedule.php` can add future screenings for eligible published films within their run dates. Do not reimport a snapshot over an existing cinema database to extend its programme.

The source includes all PHP/React/Express files, CSS, artwork, routing rules, tests, utility scripts, and bundled library licences. Zootopia 2 uses remote artwork URLs and needs internet for those images. The `php/assets` alias is materialized as ordinary files for Windows extraction. Run npm commands from the project folder; `npm ci` restores dependencies omitted from the ZIP.
