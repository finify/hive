<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->char('currency', 3);
            // One unit of the currency in GBP, as a decimal string (no floats).
            $table->string('rate_to_gbp', 20);
            $table->date('effective_on');
            $table->foreignId('set_by')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamps();

            $table->index(['currency', 'effective_on']);
        });

        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('status', 20);
            $table->bigInteger('total_gbp_minor')->default(0);
            // The lines as they stood when this version was approved: the baseline.
            $table->json('lines_snapshot')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'version']);
        });

        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('category', 30);
            $table->string('description');
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('budget_minor');
            $table->char('currency', 3)->default('GBP');
            $table->string('fx_rate', 20)->nullable();
            $table->bigInteger('budget_gbp_minor');
            // Forecast still to spend beyond what is committed or actual.
            $table->bigInteger('remaining_gbp_minor');
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('revenue_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('description');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('GBP');
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained();
            $table->foreignId('budget_line_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 30)->index();
            $table->char('currency', 3)->default('GBP');
            $table->string('fx_rate', 20)->nullable();
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('vat_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->foreignId('raised_by')->nullable()->constrained('people')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('goods_received_at')->nullable();
            $table->foreignId('goods_received_by')->nullable()->constrained('people')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('quantity', 8, 2);
            $table->bigInteger('unit_price_minor');
            $table->string('vat_rate', 20);
            $table->timestamps();
        });

        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            // The PO's line, or the line chosen for an invoice without a PO.
            $table->foreignId('budget_line_id')->nullable()->constrained()->nullOnDelete();
            $table->string('invoice_number', 50);
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->char('currency', 3)->default('GBP');
            $table->string('fx_rate', 20)->nullable();
            $table->bigInteger('net_minor');
            $table->bigInteger('vat_minor')->default(0);
            $table->bigInteger('gross_minor');
            $table->string('status', 20)->index();
            $table->bigInteger('variance_minor')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_note')->nullable();
            $table->text('no_po_reason')->nullable();
            $table->text('notes')->nullable();
            $table->string('file_path')->nullable();
            $table->string('original_name')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->date('paid_on')->nullable();
            $table->timestamps();

            $table->unique(['supplier_id', 'invoice_number']);
        });

        Schema::create('freelancer_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('invoice_number', 50);
            $table->bigInteger('net_minor');
            $table->bigInteger('vat_minor')->default(0);
            $table->bigInteger('gross_minor');
            // Approved time in the period at the rate in force each day.
            $table->bigInteger('expected_minor');
            $table->string('status', 20)->index();
            $table->boolean('is_self_billed')->default(false);
            $table->string('file_path')->nullable();
            $table->string('original_name')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->date('paid_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['person_id', 'invoice_number']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 20);
            $table->date('date');
            $table->string('description');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('GBP');
            $table->string('fx_rate', 20)->nullable();
            $table->bigInteger('amount_gbp_minor');
            $table->bigInteger('vat_minor')->default(0);
            $table->decimal('mileage_miles', 7, 1)->nullable();
            $table->string('receipt_path')->nullable();
            $table->string('receipt_name')->nullable();
            $table->string('status', 20)->index();
            $table->text('decision_comment')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('billing_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->unsignedTinyInteger('percentage')->nullable();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('GBP');
            $table->date('due_date');
            $table->string('status', 20)->index();
            $table->string('invoice_reference', 40)->nullable();
            $table->date('invoiced_on')->nullable();
            $table->date('paid_on')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['billing_milestones', 'expenses', 'freelancer_invoices', 'supplier_invoices', 'purchase_order_lines', 'purchase_orders', 'revenue_lines', 'budget_lines', 'budgets', 'exchange_rates'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
