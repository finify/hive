# Hive runbook

For whoever is looking after Hive. Hive runs on Laravel Cloud (decision 69): one application, a staging and a production environment, each with its own MySQL database, cache, queue worker and scheduler.

## Deploying

- **Staging** deploys itself: every push to `main` runs CI (Pint, Larastan, Pest on MySQL, audits) and, only if all of it passes, the `deploy-staging` job calls the staging environment's deploy hook for that exact commit. Turn **off** push-to-deploy on the staging environment, or pushes deploy twice.
- **Production**: run the *Deploy to production* workflow in GitHub Actions (optionally with a commit). Protect the `production` GitHub environment with required reviewers.
- Secrets in GitHub: `LARAVEL_CLOUD_STAGING_DEPLOY_HOOK`, `LARAVEL_CLOUD_PRODUCTION_DEPLOY_HOOK` (Laravel Cloud › environment › Settings › Deployments › Deploy hook).
- Laravel Cloud deploys without downtime. The deploy commands should be `php artisan migrate --force` then `php artisan optimize`.
- Demo environments only: `HIVE_DEMO=true`, then `php artisan migrate:fresh --seed --force` once. `hive:demo-refresh` runs hourly to put the demo back. Never set `HIVE_DEMO` in production.

### Environment variables to set

| Variable | What |
|---|---|
| `APP_KEY`, `APP_URL`, `APP_ENV=production` | Standard |
| `QUEUE_CONNECTION=database` (or Redis) | Answers, webhooks and notices are queued |
| `CACHE_STORE` | Database or Redis; the Workspace shares reads through it |
| `LOG_STACK=json` | Structured logs for Cloud's log viewer |
| `ANTHROPIC_API_KEY` | Turns Ask Hive onto Claude; check with `php artisan hive:ask "…" --as=email` |
| `ENTRA_TENANT_ID`, `ENTRA_CLIENT_ID`, `ENTRA_CLIENT_SECRET` | Microsoft sign-in (redirect URI: `/auth/microsoft/callback`) |
| `XERO_CLIENT_ID`, `XERO_CLIENT_SECRET` | Xero custom connection |
| `FILESYSTEM_DISK`, `DOCUMENTS_DISK`, AWS keys | Private object storage for documents (versioned bucket) |
| `MAIL_*` | Outgoing email |

## Health and monitoring

- Uptime: point a monitor at `/up`. It fails if the database or the cache can't be reached.
- Errors: Laravel Cloud's logs and metrics; set `LOG_LEVEL=warning` in production. Every failure an Action catches (Ask Hive, Xero, webhooks) is reported to the log with context.
- Queue: Admin › Queue lists failed jobs with their error.

## When the queue backs up or jobs fail

1. Admin › Queue: read the error. Common ones: Anthropic 429/529 (busy: retry later), a webhook endpoint returning 5xx (the receiver's problem; deliveries retry for about an hour), Xero 401 (credentials rotated).
2. Fix the cause, then **Retry**. Senders (reminders, digests, clash notices) are never retried automatically, so retrying one can email people twice; clear it instead if it ran partly.
3. If the worker is stuck: restart the queue worker from Laravel Cloud. Jobs are safe to pick up again.
4. Ask Hive requests that fail are marked failed and show a message; people can ask again.

## Restoring a backup

1. Laravel Cloud › database › Backups: choose the snapshot (daily, kept 30 days; check the retention on the database plan).
2. Restore into a **new** database first, point staging at it and check the walkthrough.
3. For production: put the app in maintenance (`php artisan down --secret=…`), restore, run `php artisan migrate --force`, clear caches (`php artisan cache:clear`, which also resets the Workspace cache), then `php artisan up`.
4. Uploaded documents live in the versioned bucket; restore object versions there if files were lost.

## Rotating secrets

- **APP_KEY**: encrypted columns (passport, emergency contact, notes, webhook secrets) use it. Set `APP_PREVIOUS_KEYS` to the old key when rotating so existing values still decrypt, then re-save or re-encrypt before removing it.
- **Anthropic, Entra, Xero**: create the new secret with the provider, update the environment variable, redeploy, then revoke the old one. Check with `hive:ask` and the Integrations page.
- **Webhook signing secrets**: add a new endpoint (new secret), switch the receiver, then remove the old endpoint.
- **Deploy hooks**: regenerate in Laravel Cloud, update the GitHub secret.
- **Calendar feed links**: people make a new address on Notifications and calendar; the old one stops working.

## Scheduled work (UK time)

| When | What |
|---|---|
| Hourly | `hive:accounting-sync` (if Xero is set up), `hive:demo-refresh` (demo only) |
| Daily 07:00 | Document expiry reminders |
| Daily 07:30 | Event budget health check |
| Daily 08:00 | Daily digests |
| Monday 08:00 / 09:00 | Rota digest / approver reminders |
| Friday 14:00 | Missing timesheet reminders |
| 1st of the month 06:00 | Records past retention flagged for review |
| First day of the leave year 01:00 | `hive:leave-year` |

The scheduler must run every minute (`php artisan schedule:run`); Laravel Cloud does this when the scheduler is enabled on the environment.
