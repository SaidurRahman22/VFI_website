<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The footer subscribe box appears on roughly thirty public pages and, until
 * now, only changed its own button to "Subscribed" — every address typed into
 * it was discarded. This is where they land.
 *
 * `interest` captures the "I'm interested in" select that sits beside the field
 * on some pages (student / institute / partner / franchisee), so the list can be
 * segmented later rather than being one undifferentiated pile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email', 190)->unique();      // re-subscribing must not duplicate
            $table->string('interest', 40)->nullable();
            $table->string('source_page', 190)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();

            $table->index('unsubscribed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_subscribers');
    }
};
