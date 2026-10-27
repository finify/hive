Some work runs in the background: Ask Hive answers, webhook deliveries, reminders, digests and the daily budget health check. **Admin › Queue** shows how many jobs are waiting and any that **failed**.

## When a job fails

Read the error first. Common ones:

- **Ask Hive is busy** (the AI service was overloaded): retry later.
- **A webhook endpoint answered 500**: the receiving system had a problem; check with its owner.
- **Xero 401**: Xero’s credentials need updating.

Then:

- **Retry** once the cause is fixed.
- **Clear** if it no longer matters. Reminders and digests are never retried automatically, because a second run could email people twice: if one failed part-way, clearing it is usually right.

Only administrators can retry or clear.
