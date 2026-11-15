# Hosting Upon a Star Cinema

**Authors:** Celine Emily Wijaya and Michael Joseph Candra
**Course and group:** IE4727 · F34-DG02
**Repository:** [michaeljc2605/upon-a-star](https://github.com/michaeljc2605/upon-a-star)
**Live website:** [Upon a Star Cinema](https://upon-a-star.infinityfreeapp.com/)

## Application architecture

The complete cinema is a PHP-led application with persistent MySQL/MariaDB storage. Its original backend is Express; the live InfinityFree deployment uses the opt-in native PHP backend. Both modes retain the canonical PHP pages and design. The original Node/React source and independent assessed PHP base remain in the repository. Publishing this source does not deploy a website.

| Component | Runtime and purpose |
| --- | --- |
| Complete PHP site | Apache, PHP 8.0+, PDO MySQL and PHP cURL; public pages, catalogue, bookings and staff screens |
| Express API | Node.js 22; Google account authentication, sessions and enhanced booking/staff operations |
| Opt-in native PHP backend | PHP 8.1+, PDO MySQL, cURL and mbstring; complete same-origin authentication, customer and staff/owner API without a Node server |
| MySQL/MariaDB | Persistent programme, account, booking, ticket and operational data |
| Optional React interface | Built with Vite and served alongside the API; uses the same real application backend |
| Independent PHP base | Apache/PHP and its own MySQL database; guest booking and confirmation without the Express service |

GitHub Pages cannot execute the PHP or Express services or host MySQL. A working public application requires a full application host. No Pages deployment or simulated replacement is configured by this repository's validation workflow.

## Validate before deployment

Use Node.js 22 and run these commands from the repository root:

```sh
node scripts/check-publication.mjs
npm ci --ignore-scripts
npm run check
```

The workflow at [`.github/workflows/validate.yml`](../.github/workflows/validate.yml) runs these checks and PHP syntax checks for pushes and pull requests targeting `main`. It has read-only repository permissions and does not deploy the application. Passing source checks does not establish that a live database, account provider or email service is configured.

## Local installation and databases

The [README installation instructions](../README.md#install-and-import-the-databases) preserve the submitted setup. Use the folder name `SilverVillage` under XAMPP's `htdocs` directory, enable Apache rewriting, and import the reviewed submission snapshots into a fresh database installation.

- `database/submission/silver_screen_base.sql` supplies the independent PHP base database, `silver_screen_base`.
- `database/submission/silver_screen.sql` supplies the complete application's database, `silver_screen`.
- Both snapshots contain the schema and public programme data. Existing users, sessions, bookings and other private operational records are omitted.
- Imports recreate application tables. Do not import snapshots over data you need to keep, or apply older seeds/migrations after importing these complete snapshots.

The standalone base runs at `/SilverVillage/base/` with Apache and MySQL. The original enhanced PHP installation additionally uses Express, Google OAuth and the configured email service. The optional original React interface uses the same API and `silver_screen` database. Native shared-hosting installation uses its own package and host-assigned database name; follow [the InfinityFree guide](INFINITYFREE.md) instead of importing the original destructive snapshots into an existing hosted database.

## Native PHP shared hosting

The live deployment is **native PHP with InfinityFree's own MySQL/MariaDB database**, at [upon-a-star.infinityfreeapp.com](https://upon-a-star.infinityfreeapp.com/). The homepage, catalogue, Google sign-in and owner access, saved bookings after reload, ticket email with QR images, and staff check-in with duplicate-ticket rejection have been verified on the live site. See [the native deployment guide](INFINITYFREE.md) for packaging, private `php/config.local.php`, the empty-database import and exact HTTPS callback.

The existing free TiDB instance is unused by this route because InfinityFree blocks outbound database port 4000. Native mode is explicitly enabled with `PHP_BACKEND=native`; without that setting, the original Express integration remains the default. The shared-hosting package serves the complete PHP site at `/` and routes `/api` to PHP. It does not include the optional React build or the independent base as a public second site.

## Original Docker / Express hosting

The original Docker image still runs Apache/PHP and Express together. The [Oracle Compose files](../deployment/oracle/compose.yml) and [Render blueprint](../render.yaml) remain optional references. Oracle signup was blocked, Northflank signup is unavailable during an ongoing security-incident review, and Render's free-service cold start does not meet the current preference. None is a claimed live deployment. See [hosting status and limitations](FREE-HOSTING.md).

A host for the original Express-backed mode must support PHP, a persistent Node process and MySQL/MariaDB. Keep database storage independent of application rebuilds and restarts, using a persistent database volume or a managed database. Configure backups before storing operational data.

1. Serve browser-facing PHP, React (if used) and `/api` on one HTTPS host. Reverse-proxy `/api` to the Express process. The session cookie is host-only, so keeping these routes together matters.
2. Run Express on the same machine or in the same application container as PHP, reachable through a loopback address. The PHP integration intentionally restricts `PHP_AUTH_API_ORIGIN` to a local HTTP origin; its default is `http://127.0.0.1:4000`. A deployment that separates PHP and Express across machines requires an explicit change to that integration.
3. Configure Apache's `BASE_DB_*` connection values for PHP and the `MYSQL_*` values for Node. Keep the independent base database separate from the complete application's database. Supply credentials through the host's environment or a private server environment file.
4. Start from [`server/.env.production.example`](../server/.env.production.example). Set the real HTTPS public origins, Google callback URL and permitted owner account configuration. Register that exact callback with the Google OAuth application. The intended verified Google account receives owner access; an owner can then assign staff access.
5. Configure the ticket email service with the required template and server-side credentials described in the README. Check delivery with the actual hosted application. The original project assumes payment occurs externally; there is no integrated payment gateway to enable through configuration.
6. Preserve the Apache access rules that protect source directories, environment files and SQL exports. Configure equivalent rules if changing servers. Keep PHP sessions and MySQL data available across ordinary application restarts.
7. Verify the hosted catalogue, sign-in, booking persistence, seat availability, staff authorization, ticket lookup and email delivery. Confirm that created records survive a restart before treating the deployment as ready for use.

For another deployment, establish its hosting destination, HTTPS hostname and private service configuration, then complete the hosted checks above. The source-publication workflow does not create those accounts or resources. Keep real credentials out of the repository and use the configuration method for the selected backend.

## Programme dates

The native PHP deployment supports a [rolling programme](ROLLING-PROGRAMME.md) for the current 18 published films through **31 December 2099**. After its opt-in migration, visits replenish a **45-day timetable** in Singapore time, at most two dates per visit. No Node service or scheduled job is required. Upload the updated runtime before applying the incremental migration to the existing database; keep existing bookings and private configuration intact.

Without that native feature, the supplied snapshot ends on **18 December 2026**. Local installations using the original Node backend or independent PHP base retain manual scheduling: extend movie run dates and add future screenings through the owner tools. The base's `base/database/refresh-schedule.php` can add screenings for eligible published films within their run dates. Past screenings remain unbookable in either mode.

## Publication hygiene

Run `node scripts/check-publication.mjs` before committing. This dependency-free check examines publishable source files, reports paths and line numbers without printing detected values, and rejects live environment files, private-key/token patterns, or private database inserts. It is a bounded source check, not a substitute for reviewing newly added exports or configuration.

The `.gitignore` excludes real environment files, dependencies, build output, logs, local backups and operating-system files while preserving reviewed configuration examples. Public catalogue snapshots and database schemas are intentionally versioned. Do not add operational database exports containing customer details, sessions, booking references, tickets or service credentials.

## Attribution and licences

This project is credited to **Celine Emily Wijaya and Michael Joseph Candra**, **IE4727, F34-DG02**. Preserve both authors' attribution when presenting the work.

The repository retains the bundled third-party licence files under `php/qr-assets/` and artwork-source information in `client/public/assets/movies/sources.json`. Film names and artwork remain associated with their respective rights holders. No new blanket software or artwork licence is granted by this publication guide.
