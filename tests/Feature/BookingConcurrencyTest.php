<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BookingConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * SQLSTATE Postgres для «не дождались блокировки» (lock_not_available).
     */
    private const LOCK_NOT_AVAILABLE = '55P03';

    public function test_second_writer_waits_for_room_lock(): void
    {
        $user = User::factory()->create();
        $room = $this->moscowRoom();

        $other = DB::connection($this->secondConnection());
        $other->beginTransaction();
        $other->table('rooms')->where('id', $room->id)->lockForUpdate()->first();

        DB::statement('SET lock_timeout = 250');

        try {
            app(BookingService::class)->create($user, $this->data($room));

            $this->fail('Ожидалась блокировка по строке комнаты.');
        } catch (QueryException $exception) {
            $this->assertSame(self::LOCK_NOT_AVAILABLE, $exception->getCode());

            // Важно: блокироваться должна именно `SELECT ... FOR UPDATE` по комнате,
            // взятая до проверки конфликта. Без неё вставку блокирует проверка внешнего
            // ключа (KEY SHARE на той же строке) — то есть уже после проверки конфликта.
            $this->assertStringContainsString('for update', $exception->getMessage());
        } finally {
            DB::statement('RESET lock_timeout');
            $other->rollBack();
        }

        $this->assertSame(0, Booking::count());
    }

    private function secondConnection(): string
    {
        config([
            'database.connections.pgsql_alt' => array_merge(
                config('database.connections.pgsql'),
                ['name' => 'pgsql_alt'],
            ),
        ]);

        DB::purge('pgsql_alt');

        return 'pgsql_alt';
    }

    private function moscowRoom(): Room
    {
        $office = Office::factory()->create(['timezone' => 'Europe/Moscow']);

        return $office->rooms()->create(['name' => 'Арбат', 'capacity' => 6]);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Room $room): array
    {
        return [
            'room_id' => $room->id,
            'title' => 'Встреча',
            'starts_at' => CarbonImmutable::parse('2026-11-02T10:00:00+03:00'),
            'ends_at' => CarbonImmutable::parse('2026-11-02T11:00:00+03:00'),
        ];
    }
}
