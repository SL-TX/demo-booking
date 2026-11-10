<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Внимание: Postgres переводит существующие строки из наивного типа в момент времени,
        // трактуя их как UTC. В свежей установке таблица пуста (сидер брони не создаёт), поэтому
        // миграция ничего не искажает. Если в базе есть брони, созданные старым кодом, их
        // моменты и так были неверными (в них лежало стенное время в смещении клиента) —
        // пересчитывать их нечем, исходное смещение не сохранялось.
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestampTz('starts_at')->change();
            $table->timestampTz('ends_at')->change();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('starts_at')->change();
            $table->timestamp('ends_at')->change();
        });
    }
};
