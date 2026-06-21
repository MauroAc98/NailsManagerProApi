<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('nombre', 100)->nullable()->after('user_id');
            $table->string('apellido', 100)->nullable()->after('nombre');
        });

        // Migrar datos existentes: primera palabra = nombre, resto = apellido
        DB::table('clientes')->orderBy('id')->chunkById(100, function ($clientes) {
            foreach ($clientes as $cliente) {
                $partes = explode(' ', trim($cliente->nombre_completo), 2);

                DB::table('clientes')->where('id', $cliente->id)->update([
                    'nombre'   => $partes[0] !== '' ? $partes[0] : 'Sin nombre',
                    'apellido' => $partes[1] ?? '',
                ]);
            }
        });

        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn('nombre_completo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('nombre_completo', 200)->nullable()->after('user_id');
        });

        DB::table('clientes')->orderBy('id')->chunkById(100, function ($clientes) {
            foreach ($clientes as $cliente) {
                DB::table('clientes')->where('id', $cliente->id)->update([
                    'nombre_completo' => trim("{$cliente->nombre} {$cliente->apellido}"),
                ]);
            }
        });

        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn(['nombre', 'apellido']);
        });
    }
};