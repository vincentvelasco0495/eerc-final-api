<?php



use Illuminate\Database\Migrations\Migration;

use Illuminate\Database\Schema\Blueprint;

use Illuminate\Support\Facades\DB;

use Illuminate\Support\Facades\Schema;



return new class extends Migration

{

    public function up(): void

    {

        if (! Schema::hasTable('package_enrolls')) {

            return;

        }



        Schema::table('package_enrolls', function (Blueprint $table) {

            if (! Schema::hasColumn('package_enrolls', 'name')) {

                $table->string('name', 512)->nullable()->after('public_id');

            }

        });



        if (Schema::hasColumn('package_enrolls', 'label') && Schema::hasColumn('package_enrolls', 'name')) {

            DB::table('package_enrolls')

                ->whereNull('name')

                ->update(['name' => DB::raw('label')]);

        }

    }



    public function down(): void

    {

        if (! Schema::hasTable('package_enrolls') || ! Schema::hasColumn('package_enrolls', 'name')) {

            return;

        }



        Schema::table('package_enrolls', function (Blueprint $table) {

            $table->dropColumn('name');

        });

    }

};

