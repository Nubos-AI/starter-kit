<?php

declare(strict_types=1);

use App\Enums\Users\Salutation;
use App\Enums\Users\UserStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'users',
            function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->ulid('tenant_id')->nullable()->index();
                $table->ulid('current_team_id')->nullable();
                $table->ulid('default_dashboard_id')->nullable();
                $table->boolean('is_service')->default(false);
                $table->string('status')->default(UserStatus::Accepted->value)->index();
                $table->string('salutation')->default(Salutation::Unknown->value);
                $table->string('first_name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('email')->unique();
                $table->string('timezone')->nullable();
                $table->time('quiet_hours_start')->nullable();
                $table->time('quiet_hours_end')->nullable();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password')->nullable();
                $table->ulid('invited_by_id')->nullable()->index();
                $table->string('invitation_token_hash')->nullable()->index();
                $table->timestamp('invitation_sent_at')->nullable();
                $table->timestamp('invitation_expires_at')->nullable();
                $table->timestamp('invitation_accepted_at')->nullable();
                $table->text('two_factor_secret')->nullable();
                $table->text('two_factor_recovery_codes')->nullable();
                $table->timestamp('two_factor_confirmed_at')->nullable();
                $table->rememberToken();
                $table->softDeletes();
                $table->timestamps();
            }
        );

        Schema::create(
            'password_reset_tokens',
            function (Blueprint $table) {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            }
        );

        Schema::create(
            'sessions',
            function (Blueprint $table) {
                $table->string('id')->primary();
                $table->foreignUlid('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
