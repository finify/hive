**Admin › Integrations** shows what Hive is connected to.

## Microsoft sign-in

Staff can sign in with their Microsoft work account. Only people who already have a Hive account get in. It is set up on the server (tenant, client id and secret).

## Xero

When connected, **approved supplier invoices** go to Xero as bills, coded by budget category, and **payments** come back and mark them paid, every hour. **Sync now** runs it straight away.

## Webhooks

Webhooks tell another system when something happens in Hive: **an event changes stage** or **a booking is confirmed**.

1. Enter the receiving **HTTPS** address and choose the events.
2. **Copy the signing secret** shown: it appears once. The receiver uses it to check each message really came from Hive.
3. **Send test** checks the connection. Recent deliveries and their result are listed; failed ones retry for about an hour.

Pause or remove a webhook at any time. Messages never include rates or personal details beyond names.

## Calendar feeds

Each person turns on their own feed in [Notifications and calendar](/help/notifications).

## Import from CSV

**Admin › Import from CSV** adds many people, clients, suppliers or venues at once.

1. Choose what you are importing and **download the template** for its columns.
2. Fill it in and upload it. **Check the file** tests every row with the same rules as the add form, and lists any problems by line. **Nothing is saved yet.**
3. When every row is ready, **Import**. It is all or nothing: if any row has a problem, fix the file and check it again.

Up to 500 rows at a time.
