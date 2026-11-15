# Free hosting for Upon a Star

Prepared on 15 November 2026. The planned deployment is **an Oracle Cloud Always Free VM + a MySQL-compatible TiDB Cloud Starter instance with spending limit 0**. **Render Free is the fallback** if an eligible Oracle VM cannot be provisioned. These files prepare a deployment; they do not establish that the application is live or fully configured.

## What runs where

The included Docker image runs Apache/PHP and the Express API together. PHP is the original complete website; `/api` reaches Express through Apache on the same public host. The optional React interface remains in the repository but is not substituted for the PHP website. Accounts, screenings, reservations and tickets live in the external database, so application restarts do not erase them.

On Oracle, the VM runs the application container and a Caddy HTTPS proxy. TiDB remains the persistent database. Select only an **Always Free-eligible** VM and boot volume within the account's free allowance; do not add paid capacity, a paid load balancer or a paid database. The VM's home region must have available capacity. Oracle can reclaim idle free VMs, so this does not guarantee permanent uptime. [Oracle free resources and idle-reclamation rules](https://docs.oracle.com/en-us/iaas/Content/FreeTier/freetier_topic-Always_Free_Resources.htm).

Oracle requires account verification and may place a temporary card authorization hold. Keep the account and resources within the free offering; do not upgrade to paid resources as a workaround for unavailable capacity. [Oracle Free Tier FAQ](https://www.oracle.com/cloud/free/faq/).

Select the MySQL-compatible TiDB **Starter** plan, keep its spending limit at **0**, and avoid paid upgrades. Starter creation with limit 0 does not require a credit card. Free storage and monthly database work are limited; exhausted quotas can restrict or suspend usage rather than enabling paid capacity. It is a MySQL-compatible database, not identical to every MySQL release; the deployed application's booking and seat-conflict paths still require verification against the actual instance. [TiDB account and spending rules](https://docs.pingcap.com/tidbcloud/create-tidb-cluster-serverless/), [TiDB limits](https://docs.pingcap.com/tidbcloud/serverless-limitations/).

## Prepare the shared database

Use a **Starter / MySQL Compatible** instance in Singapore with spending limit **0**, near the intended app host. Wait for provisioning to finish. Set the database password in the provider's secure interface and retain the connection hostname, port and full prefixed username; do not paste the password into chat, code or this document.

The startup initializer imports the public catalogue only when `silver_screen` is empty. An existing complete schema is preserved. An incomplete or unrelated schema stops startup without replacing it. It also enables TiDB's global CHECK-constraint setting when needed; the database user needs permission for that first-run step. See [initialization details](../deployment/INITIALIZATION.md).

## Primary: Oracle Always Free VM

The deployment files are [Compose](../deployment/oracle/compose.yml), [Caddy configuration](../deployment/oracle/Caddyfile), [environment example](../deployment/oracle/environment.example) and [launcher](../deployment/oracle/run.sh). They run the existing complete PHP website and Express API without changing the submitted interface.

1. Provision an eligible Ubuntu VM in the Oracle account's home region. An Ampere A1 VM with **1 OCPU, 2 GB RAM and a 50 GB boot volume** is a starting allocation; confirm that the console labels the selected resources as free and that the account's combined usage stays within its allowance. Availability is not guaranteed.
2. Install Docker Engine and its Compose plugin on the VM, then clone this repository. Allow inbound TCP ports **80 and 443** in the Oracle network rules and the VM firewall; restrict SSH to the administrator's address. The app and database ports should not be public.
3. Point a controlled DNS hostname at the VM's public address. A working HTTPS hostname remains required for public Google sign-in and secure cookies. Use existing or free resources; this setup does not authorize purchasing a domain. Caddy obtains the public certificate after DNS and the network rules are ready.
4. Copy `deployment/oracle/environment.example` to `deployment/oracle/.env` on the VM. Fill `CINEMA_HOSTNAME` with only the hostname, and provide the TiDB `MYSQL_HOST`, `MYSQL_PORT`, `MYSQL_USER` and `MYSQL_PASSWORD`. Keep `MYSQL_DATABASE=silver_screen`. Fill the Google and EmailJS values described below through this private, gitignored file. Restrict its file permissions to the deploying user.
5. For the first initialization of a fresh database, set `INITIALIZE_EMPTY_DATABASE=true`. From the repository root, run `./deployment/oracle/run.sh`. After a successful import, set the flag back to `false` and run the launcher again. The launcher validates the Compose configuration without displaying secret values, then builds and starts the containers.
6. Check the HTTPS homepage and `/api/catalog`. `/api/health` establishes that the API process responds; it does not prove database access, sign-in, bookings or email. Complete the release checks below before sharing the address as a working full application.

The Oracle configuration derives `PUBLIC_URL=https://CINEMA_HOSTNAME`, keeps the application behind Caddy, persists Caddy's certificate data, and enables verified TiDB TLS using the system CA bundle. Never disable certificate validation to work around a remote connection failure. The proxy's private address and subnet defaults must remain consistent if changed.

The VM does not have Render's routine request-idle sleep behavior, but Oracle's idle-reclamation policy and free-service limits still apply. Application rebuilds or restarts can reset PHP form sessions; accounts and saved bookings remain in TiDB. Keep recoverable copies of operational data and test restart behavior before relying on the deployment.

## Fallback: Render Free

The [Render blueprint](../render.yaml) explicitly selects a free web service and creates no paid database, disk or worker. Create a Blueprint from `michaeljc2605/upon-a-star`, confirm **Free**, and supply the TiDB credentials through the dashboard's private environment fields. The blueprint defaults to database port `4000`; use the port shown by TiDB if different. Use the provider's free subdomain. The image derives its HTTPS public address from `RENDER_EXTERNAL_URL`; for a controlled custom hostname, set `PUBLIC_URL` to the exact HTTPS origin without a path.

Render Free sleeps after 15 idle minutes and waking can take about a minute. Its workspace shares 750 free running hours per month. The filesystem is temporary, so PHP form sessions can reset when the service sleeps or restarts; saved accounts/bookings remain in TiDB. Build, bandwidth and external-service traffic limits also apply. Do not add paid capacity to solve a free-plan quota stop. [Render Free limits](https://render.com/docs/free).

Complete the same Google, email and hosted release checks. If moving between Oracle and Render, update the precise Google callback and public origin to match the active host. Do not run two public deployments against the same live database unless that arrangement has been deliberately configured and verified.

## Activate account sign-in and owner tools

Create or use a Google OAuth **Web application** and register the precise callback:

```text
https://YOUR-CINEMA-HOSTNAME/api/auth/google/callback
```

Set `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` and `GOOGLE_OWNER_EMAILS` in the Oracle VM's private deployment environment file or Render's private environment settings. The startup script supplies the matching callback/origins from the public address. `GOOGLE_OWNER_EMAILS` is the comma-separated list of intended, verified Google owner accounts. Do not put untrusted accounts in that list. The public callback must match the registered HTTPS URL exactly; a raw public IP address or wildcard is not valid. [Google callback rules](https://developers.google.com/identity/protocols/oauth2/web-server#uri-validation).

The application requests only `openid email profile`. Google's Testing exception for these sign-in scopes means users need not be on a test-user list, do not see the Testing warning, and their authorization does not expire after seven days. Adding other scopes removes that exception. Workspace and Advanced Protection restrictions can still prevent sign-in, and app branding/verification remains a separate configuration concern. [Google audience rules](https://support.google.com/cloud/answer/15549945?hl=en).

With Google unconfigured, the catalogue can load but sign-in returns a controlled unavailable response. Do not treat that as a completed full deployment. After configuration, verify customer sign-in, owner access and staff assignment separately.

## Activate ticket email

Add `EMAILJS_SERVICE_ID`, `EMAILJS_TEMPLATE_ID` and `EMAILJS_PUBLIC_KEY`, plus `EMAILJS_PRIVATE_KEY` only if required by the account, through the chosen host's private environment configuration. The [README](../README.md#ticket-email-configuration) contains the original template fields. Set the service to accept the application's server-side HTTPS requests and verify an actual ticket email.

Both hosting paths use the existing EmailJS HTTPS integration. Render Free blocks common SMTP ports. Check the EmailJS account's free allowance and keep it on the free plan. A failed email does not cancel a persisted booking; the website must accurately report that state. There is no integrated payment provider in the submitted application.

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
| Upon a Star | Full PHP website + Node API + MySQL-compatible database | The independent PHP guest-booking version is a possible fit. The complete application uses the proposed Oracle/TiDB setup, with Render/TiDB as a fallback. |
| GM Ebookshop (`IDP-Task-2`) | Java/Tomcat + MySQL | Cannot run its Java backend here. A separate container host and compatible database are needed, or a deliberately limited static demonstration. |
| StudySynth | Next.js + Python/FastAPI + PostgreSQL, plus worker/storage/AI services | Cannot run the full backend here. Its storage, worker and AI dependencies need separate provisioning; full feature operation at $0 has not been established. |

InfinityFree cannot be used merely as the remote database behind these externally hosted apps. This guide prepares Upon a Star; it does not claim deployments or architecture changes for the other two projects.
