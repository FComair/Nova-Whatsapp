<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Comptes de connexion du dashboard client (docs/01 §1). Un utilisateur peut appartenir à plusieurs organisations.
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('email');
            $table->string('password');
            $table->string('phone_e164', 20)->nullable();
            $table->string('locale', 2)->default('fr');
            $table->timestampTz('email_verified_at')->nullable();
            $table->timestampTz('phone_verified_at')->nullable();
            $table->unsignedSmallInteger('failed_login_count')->default(0);
            $table->timestampTz('locked_until')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->string('status', 20)->default('active');
            $table->rememberToken();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE users ALTER COLUMN email TYPE citext');
        DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (email)');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_locale_check CHECK (locale IN ('fr','nl','en'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active','disabled'))");

        // Comptes Super Admin : table et guard séparés des clients (docs/02 §3). 2FA obligatoire.
        Schema::create('platform_admins', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('email')->unique();
            $table->string('password');
            $table->text('totp_secret_enc')->nullable();
            $table->timestampTz('totp_confirmed_at')->nullable();
            $table->text('recovery_codes_enc')->nullable();
            $table->jsonb('ip_allowlist')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestampsTz();
        });

        // Le rôle client n'a AUCUN accès à la table des Super Admins.
        DB::statement('REVOKE ALL ON platform_admins FROM nova_app');
        DB::statement('GRANT SELECT, INSERT, UPDATE ON platform_admins TO nova_platform');
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admins');
        Schema::dropIfExists('users');
    }
};
