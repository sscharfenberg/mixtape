<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One-time, expiring registration invites. Onboarding is invite-only (open
     * registration is off), so an account can only be created by redeeming a
     * valid row here. Rows are minted by `php artisan app:invite` and DELETED on
     * redemption (App\Actions\Fortify\CreateNewUser), so the table only ever
     * holds outstanding (as-yet unredeemed) invites.
     */
    public function up(): void
    {
        Schema::create('invites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // sha256 hex of the high-entropy code shared in the link; the
            // plaintext is never stored (it lives only in the copied URL).
            $table->string('token')->unique();
            // free-text reminder of who the invite was minted for. The invite is
            // not tied to a user, so this is the only human hint. Optional.
            $table->string('note')->nullable();
            // an invite at/after this instant is invalid; set to now()+days at mint.
            $table->timestamp('valid_until');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invites');
    }
};
