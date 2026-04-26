<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('application_settings', 'exclude_from_master_domain_routing')) {
            Schema::table('application_settings', function (Blueprint $table) {
                $table->boolean('exclude_from_master_domain_routing')->default(false)->after('connect_to_docker_network');
            });
        }

        if (! Schema::hasColumn('services', 'exclude_from_master_domain_routing')) {
            Schema::table('services', function (Blueprint $table) {
                $table->boolean('exclude_from_master_domain_routing')->default(false)->after('connect_to_docker_network');
            });
        }

        if (! Schema::hasColumn('service_applications', 'exclude_from_master_domain_routing')) {
            Schema::table('service_applications', function (Blueprint $table) {
                $table->boolean('exclude_from_master_domain_routing')->default(false)->after('exclude_from_status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('service_applications', 'exclude_from_master_domain_routing')) {
            Schema::table('service_applications', function (Blueprint $table) {
                $table->dropColumn('exclude_from_master_domain_routing');
            });
        }

        if (Schema::hasColumn('services', 'exclude_from_master_domain_routing')) {
            Schema::table('services', function (Blueprint $table) {
                $table->dropColumn('exclude_from_master_domain_routing');
            });
        }

        if (Schema::hasColumn('application_settings', 'exclude_from_master_domain_routing')) {
            Schema::table('application_settings', function (Blueprint $table) {
                $table->dropColumn('exclude_from_master_domain_routing');
            });
        }
    }
};
