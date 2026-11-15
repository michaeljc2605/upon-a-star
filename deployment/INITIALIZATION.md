# First-run database initialization

The container startup can run `node deployment/init-database.mjs` before starting the application. This supports hosts without an interactive shell.

Set `INITIALIZE_EMPTY_DATABASE=true` explicitly to permit initialization. Configure `MYSQL_HOST`, `MYSQL_PORT`, `MYSQL_USER`, `MYSQL_PASSWORD` and `MYSQL_DATABASE`; the database name defaults to `silver_screen`. Names must use only letters, digits and underscores, up to 64 characters. The database account needs permission to create the intended database if it does not exist, and to create its tables and import the catalogue.

For a remote database, set `MYSQL_SSL=true`. TLS certificate and hostname verification remain enabled. If the database provider requires a separate CA bundle, provide its readable file path through `MYSQL_SSL_CA`; never disable certificate verification to bypass a connection error.

The initializer only uses the fixed, reviewed `database/submission/silver_screen.sql` snapshot. It checks the intended database twice before importing. If any table or view exists, it does not import: a complete application table set is left unchanged, while an incomplete or unrelated schema stops startup with a recovery message. Table-removal statements are stripped from the first-run import, and only the snapshot's database-selection directives are renamed. SQL data values remain unchanged.

MySQL DDL is not atomic. A failed import can leave a partial schema. The initializer never erases or recreates an existing schema to recover; inspect that database manually. An existing schema check verifies required table names, not a full column migration or catalogue reconciliation. Disable `INITIALIZE_EMPTY_DATABASE` after successful provisioning if first-run handling is no longer needed.

On TiDB, the snapshot's JSON checks require `tidb_enable_check_constraint`. This setting is **global-only**. The initializer reads it, enables it only when needed, and verifies the result before importing. If the account cannot enable it, startup fails before application DDL; a database administrator must enable the setting first. See [TiDB's variable documentation](https://docs.pingcap.com/tidbcloud/system-variables/#tidb_enable_check_constraint-new-in-v720).

This entry point initializes only the complete application database. The independent PHP base database must be installed separately from `database/submission/silver_screen_base.sql`; `INITIALIZE_BASE_DATABASE=true` is rejected rather than silently importing an additional database.

Run the database-free guard tests with:

```sh
node --test deployment/init-database.test.mjs
```
