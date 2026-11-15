# Free hosting for Upon a Star

Status checked on **15 November 2026**. The current prepared route is **the complete native PHP backend on InfinityFree, using its included MySQL/MariaDB database**. The existing InfinityFree profile has no active hosting account yet. Nothing is deployed, and real Google OAuth and EmailJS delivery have not been verified. Follow the [native deployment guide](INFINITYFREE.md) for the package, private configuration and empty-database import.

## Application and service requirements

Native mode retains the canonical PHP interface and complete customer, booking and staff/owner operations. It is explicitly enabled with `PHP_BACKEND=native`; the original Express backend, optional React interface, project report and attribution remain in the repository. The native package needs PHP, PDO MySQL, cURL, mbstring and Apache rewriting, without a Node server on the host.

InfinityFree currently advertises PHP 8.4, MySQL 8.0/MariaDB 11.4, `.htaccess` support, a free subdomain and SSL, with no card or expiry. Resource limits and outages still apply; no uninterrupted uptime is guaranteed. Check the actual hosting account and [provider status](https://status.infinityfree.com/) before treating a deployment as available. [Published features](https://www.infinityfree.com/).

Keep the site and its API on the same HTTPS hostname. InfinityFree's browser protection requires JavaScript/cookies and blocks external API clients, webhooks and cross-origin access; ordinary same-origin browser requests are supported. Google sign-in still needs a real OAuth client and exact callback, and ticket email needs actual EmailJS settings. Their outbound HTTPS calls are compatible with the published connection rules, but actual delivery and sign-in remain release checks. [Browser protection](https://forum.infinityfree.com/t/browser-security-system-features-and-limitations/49353), [Google-login clarification](https://forum.infinityfree.com/t/anti-bot-help/112174/4).

## Database and other hosting routes

The already-created TiDB Starter instance remains free and unused by this path. Keep its spending limit at **0**. InfinityFree blocks outbound ports other than 80, 443 and 587, including TiDB's database port 4000, so the PHP package uses the hosting account's own database. Its database also cannot serve as a remote database for an app hosted elsewhere. [Outbound restrictions](https://forum.infinityfree.com/t/can-i-connect-to-other-servers-from-my-website-sockets-curl-and-apis/49348), [remote database restriction](https://forum.infinityfree.com/t/issue-with-remote-mysql-database-connection/100347/2), [TiDB free-plan rules](https://docs.pingcap.com/tidbcloud/create-tidb-cluster-serverless/).

| Route | Current disposition |
| --- | --- |
| InfinityFree + its MySQL/MariaDB | Native PHP package prepared; active hosting and live verification remain outstanding. |
| Northflank + TiDB | Not recommended for this deployment: signup is disabled, and its official status page reports an ongoing security-incident investigation/review. [Provider status](https://status.northflank.com/). |
| Nullstone | Its platform still requires a separate cloud account and infrastructure billed by that provider; it is not a standalone $0 hosting replacement. [How it works](https://docs.nullstone.io/getting-started/how-it-works.html). |
| Oracle Always Free + TiDB | Signup encountered a request-limit block; no VM was provisioned. Existing [Compose files](../deployment/oracle/compose.yml) remain an optional reference. |
| Render Free + TiDB | Existing [blueprint](../render.yaml) remains available, but its 15-minute idle sleep and subsequent wake-up delay do not meet the current preference. [Free-service limits](https://render.com/docs/free). |

No paid resource or upgrade is part of this setup. The original Docker deployment remains available for a host that runs PHP and Express together; it is separate from the native shared-host package.

## Other portfolio projects

This PHP port applies only to Upon a Star. GM Ebookshop requires Java/Tomcat and MySQL; StudySynth requires Next.js, Python/FastAPI, PostgreSQL and additional services. InfinityFree does not supply those backend runtimes, and this guide claims no deployment or feature-preserving port for either project.
