# Free hosting for Upon a Star

Prepared on 15 November 2026. The proposed deployment is **Render Free + a MySQL-compatible TiDB Cloud Starter instance with spending limit 0**. Publishing this repository does not create those accounts or make the application live.

## What runs where

The included Docker image runs Apache/PHP and the Express API together. PHP is the original complete website; `/api` reaches Express through Apache on the same public host. The optional React interface remains in the repository but is not substituted for the PHP website. Accounts, screenings, reservations and tickets live in the external database, so application restarts do not erase them.

The [Render blueprint](../render.yaml) explicitly selects a free web service and creates no paid database, disk or worker. Use the provider's free subdomain; a purchased domain is unnecessary. Select the MySQL-compatible TiDB **Starter** plan, keep its spending limit at **0**, and avoid paid upgrades. Starter creation with limit 0 does not require a credit card. [TiDB account and spending rules](https://docs.pingcap.com/tidbcloud/create-tidb-cluster-serverless/).

Render Free sleeps after 15 idle minutes and waking can take about a minute. Its workspace shares 750 free running hours per month. The filesystem is temporary, so PHP form sessions can reset when the service sleeps or restarts; saved accounts/bookings remain in TiDB. Build, bandwidth and external-service traffic limits also apply. Do not add paid capacity to solve a free-plan quota stop. [Render Free limits](https://render.com/docs/free).

TiDB Starter includes limited free storage and monthly database work. With spending limit 0, exhausted quotas can restrict or suspend usage rather than enabling paid capacity. It is a MySQL-compatible database, not identical to every MySQL release; the deployed application's booking and seat-conflict paths still require verification against the actual instance. [TiDB limits](https://docs.pingcap.com/tidbcloud/serverless-limitations/).

## Provision the database and website

1. In TiDB Cloud, create a **Starter / MySQL Compatible** instance with spending limit **0**, ideally near Render's Singapore region. Set its database password in the provider's secure interface. Retain the connection hostname, port and full prefixed username; do not paste the password into chat, code or this document.
2. In Render, create a Blueprint from `michaeljc2605/upon-a-star`. Check that the service is **Free**. Supply `MYSQL_HOST`, `MYSQL_USER` and `MYSQL_PASSWORD` in the dashboard's private environment fields. The blueprint defaults to port `4000`; use the port shown by TiDB if different.
3. The startup initializer imports the public catalogue only when `silver_screen` is empty. An existing complete schema is preserved. An incomplete or unrelated schema stops startup without replacing it. It also enables TiDB's global CHECK-constraint setting when needed; the database user needs permission for that first-run step. See [initialization details](../deployment/INITIALIZATION.md).
4. Wait for the deployment and check the public homepage and `/api/catalog`. `/api/health` checks that the API process responds; it does not prove database access, sign-in, bookings or email.
5. Complete Google sign-in and EmailJS settings below. Test the actual hosted booking flow before sharing the site as fully working.

The image derives its public address from Render's `RENDER_EXTERNAL_URL`. For another host or a custom domain, set `PUBLIC_URL` to the exact HTTPS origin without a path. It maps the same database credentials into both PHP and Node. Verified TLS remains enabled using the system CA bundle. Never disable certificate validation to work around a remote connection failure.

## Activate account sign-in and owner tools

Create or use a Google OAuth **Web application** and register the precise callback:

```text
https://YOUR-RENDER-HOST/api/auth/google/callback
```

Set `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` and `GOOGLE_OWNER_EMAILS` in Render's private environment settings. The startup script supplies the matching callback/origins from the public address. `GOOGLE_OWNER_EMAILS` is the comma-separated list of intended, verified Google owner accounts. Do not put untrusted accounts in that list. In a Google consent app still in Testing, add the intended test users through Google's console; public access depends on the consent app's publishing settings.

With Google unconfigured, the catalogue can load but sign-in returns a controlled unavailable response. Do not treat that as a completed full deployment. After configuration, verify customer sign-in, owner access and staff assignment separately.

## Activate ticket email

Add `EMAILJS_SERVICE_ID`, `EMAILJS_TEMPLATE_ID` and `EMAILJS_PUBLIC_KEY`, plus `EMAILJS_PRIVATE_KEY` only if required by the account, through Render's environment settings. The [README](../README.md#ticket-email-configuration) contains the original template fields. Set the service to accept the application's server-side HTTPS requests and verify an actual ticket email.

Render Free blocks common SMTP ports, so this deployment uses the existing EmailJS HTTPS integration. Check the EmailJS account's free allowance and keep it on the free plan. A failed email does not cancel a persisted booking; the website must accurately report that state. There is no integrated payment provider in the submitted application.

## Release checks

The GitHub workflow checks the source, builds the image and exercises a fresh MySQL database. It cannot establish that private Google/EmailJS settings or the actual TiDB instance work.

Before advertising a live URL, verify:

- Catalogue, artwork, movie details and showtimes on desktop and mobile.
- Google sign-in, sign-out and intended owner/staff restrictions.
- Booking, ticket lookup and the conflict case where two people choose the same seat.
- Ticket email, retry behavior and staff check-in; camera scanning requires the user's browser permission.
- Saved bookings after restarting the service. PHP in-progress form sessions may reset.
- Future screenings: the submitted programme ends on **18 December 2026** and needs extension for later use.

The separate assessed `base/` version uses its own database and is not auto-installed by this full-site deployment. Keep `/base/` disabled on a hosted full-site release unless that second database has been deliberately installed.

## InfinityFree and the other portfolio projects

InfinityFree was considered because an account is available. It supports PHP/MySQL, but does not run Node servers; its MySQL databases cannot be accessed from outside its hosting. [Node limitation](https://forum.infinityfree.com/t/nodejs-and-subdomains/90423/10), [database limitation](https://forum.infinityfree.com/t/issue-with-remote-mysql-database-connection/100347/2).

| Project | What the supplied project needs | InfinityFree fit |
| --- | --- | --- |
| Upon a Star | Full PHP website + Node API + MySQL-compatible database | The independent PHP guest-booking version is a possible fit. The complete application needs the Render/TiDB setup or another host that runs both PHP and Node. |
| GM Ebookshop (`IDP-Task-2`) | Java/Tomcat + MySQL | Cannot run its Java backend here. A separate container host and compatible database are needed, or a deliberately limited static demonstration. |
| StudySynth | Next.js + Python/FastAPI + PostgreSQL, plus worker/storage/AI services | Cannot run the full backend here. Its storage, worker and AI dependencies need separate provisioning; full feature operation at $0 has not been established. |

InfinityFree cannot be used merely as the remote database behind these externally hosted apps. This guide prepares Upon a Star; it does not claim deployments or architecture changes for the other two projects.
