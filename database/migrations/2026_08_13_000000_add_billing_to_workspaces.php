<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->boolean('billing_enforced')->default(false)->after('is_active');
            $table->string('billing_email')->nullable()->after('billing_enforced');
            $table->char('billing_currency', 3)->default('mxn')->after('billing_email');
            $table->unsignedInteger('vacant_property_unit_amount')->default(2000)->after('billing_currency');
            $table->unsignedInteger('rented_property_unit_amount')->default(4000)->after('vacant_property_unit_amount');
            $table->unsignedSmallInteger('billing_grace_days')->default(5)->after('rented_property_unit_amount');

            $table->unsignedInteger('property_count')->default(0)->after('billing_grace_days');
            $table->unsignedInteger('rented_property_count')->default(0)->after('property_count');
            $table->timestamp('metrics_reported_at')->nullable()->after('rented_property_count');

            $table->string('stripe_customer_id')->nullable()->unique()->after('metrics_reported_at');
            $table->string('stripe_product_id')->nullable()->after('stripe_customer_id');
            $table->string('stripe_vacant_price_id')->nullable()->after('stripe_product_id');
            $table->string('stripe_rented_price_id')->nullable()->after('stripe_vacant_price_id');
            $table->string('stripe_subscription_id')->nullable()->unique()->after('stripe_rented_price_id');
            $table->string('stripe_vacant_item_id')->nullable()->after('stripe_subscription_id');
            $table->string('stripe_rented_item_id')->nullable()->after('stripe_vacant_item_id');
            $table->string('subscription_status', 32)->nullable()->index()->after('stripe_rented_item_id');
            $table->timestamp('subscription_period_ends_at')->nullable()->after('subscription_status');
            $table->timestamp('billing_grace_ends_at')->nullable()->after('subscription_period_ends_at');
            $table->timestamp('last_invoice_paid_at')->nullable()->after('billing_grace_ends_at');
            $table->string('last_stripe_invoice_id')->nullable()->after('last_invoice_paid_at');
            $table->boolean('stripe_sync_pending')->default(false)->after('last_stripe_invoice_id');
        });

        Schema::table('user_workspace', function (Blueprint $table): void {
            $table->boolean('is_billing_manager')->default(false)->after('is_active');
            $table->boolean('billing_access_override')->default(false)->after('is_billing_manager');
        });

        Schema::create('workspace_usage_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('property_count');
            $table->unsignedInteger('rented_property_count');
            $table->unsignedInteger('vacant_property_count');
            $table->unsignedInteger('vacant_property_unit_amount');
            $table->unsignedInteger('rented_property_unit_amount');
            $table->unsignedBigInteger('calculated_amount');
            $table->timestamp('measured_at');
            $table->timestamps();

            $table->unique(['workspace_id', 'measured_at']);
        });

        Schema::create('stripe_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('stripe_event_id')->unique();
            $table->string('type');
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('processed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_webhook_events');
        Schema::dropIfExists('workspace_usage_snapshots');

        Schema::table('user_workspace', function (Blueprint $table): void {
            $table->dropColumn(['is_billing_manager', 'billing_access_override']);
        });

        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn([
                'billing_enforced',
                'billing_email',
                'billing_currency',
                'vacant_property_unit_amount',
                'rented_property_unit_amount',
                'billing_grace_days',
                'property_count',
                'rented_property_count',
                'metrics_reported_at',
                'stripe_customer_id',
                'stripe_product_id',
                'stripe_vacant_price_id',
                'stripe_rented_price_id',
                'stripe_subscription_id',
                'stripe_vacant_item_id',
                'stripe_rented_item_id',
                'subscription_status',
                'subscription_period_ends_at',
                'billing_grace_ends_at',
                'last_invoice_paid_at',
                'last_stripe_invoice_id',
                'stripe_sync_pending',
            ]);
        });
    }
};
