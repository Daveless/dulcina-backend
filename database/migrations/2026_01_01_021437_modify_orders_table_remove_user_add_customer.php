<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Eliminar la relación con users
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');

            // Agregar información del cliente externo
            $table->string('customer_name')->after('id');
            $table->string('customer_phone')->after('customer_name');
            $table->string('customer_email')->nullable()->after('customer_phone');
            $table->text('delivery_address')->after('customer_email');

            // Agregar campo para notas internas
            $table->text('internal_notes')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Revertir cambios
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->dropColumn([
                'customer_name',
                'customer_phone',
                'customer_email',
                'delivery_address',
                'internal_notes'
            ]);
        });
    }
};
