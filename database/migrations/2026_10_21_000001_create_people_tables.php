<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The standalone version's own people data. Inside the HR app these come from
 * the HR tables (employees, contracts, positions); here they are kept simple:
 *
 *   employees.media_role     member | manager | null  (the Media team)
 *   program_managers         who manages which program (country-wide)
 *   users.role               super-admin | general-manager | manager | employee
 *
 * When the module moves into the HR app, these tables are dropped and
 * App\Support\MediaRequests\MediaTeam goes back to reading contracts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('slug', 10)->unique();
            $table->string('code', 10)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('code', 10);
            $table->string('slug', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->string('first_name_en');
            $table->string('first_name_ar')->nullable();
            $table->string('last_name_en')->nullable();
            $table->string('last_name_ar')->nullable();
            $table->string('username')->nullable()->unique();
            $table->string('media_role', 10)->nullable()->comment('member | manager: the Media team');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('program_managers', function (Blueprint $table) {
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->primary(['employee_id', 'program_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->after('id')->constrained('employees')->nullOnDelete();
            $table->string('role', 30)->default('employee')->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_id');
            $table->dropColumn('role');
        });
        Schema::dropIfExists('program_managers');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('programs');
        Schema::dropIfExists('cities');
    }
};
