Supplier invoices are recorded in Hive, checked against their purchase order, approved, and then paid.

## Recording an invoice

**Finance › Supplier invoices › Record an invoice**: the supplier, PO, invoice number, dates, net and VAT, and the PDF. An invoice without a PO needs a reason.

## Matching and tolerance

Hive compares the invoice with what is still open on its PO. Within **tolerance** (5% or £250, whichever is set in [Settings](/help/settings)) it is **Matched** and goes to the event’s producer. Over tolerance, it is flagged and finance must approve too.

## Approval and payment

- Approval turns the committed cost into **actual** cost on the budget.
- **Dispute** sends it back to the supplier with a note; the event can’t be reconciled until disputes are settled. **Reopen** when it is resolved.
- **Mark paid** records payment. If Xero is connected, approved invoices go to Xero as bills and payments come back automatically (see [Integrations](/help/integrations)).
