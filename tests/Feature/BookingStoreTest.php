<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingStoreTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->room = $this->moscowRoom();

        Sanctum::actingAs($this->user);
    }

    public function test_creates_booking_with_authenticated_user(): void
    {
        $this->postBooking([
            'room_id' => $this->room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ])
            ->assertCreated()
            ->assertJsonPath('user_id', $this->user->id)
            ->assertJsonPath('room_id', $this->room->id)
            ->assertJsonPath('starts_at', '2026-11-02T07:00:00.000000Z')
            ->assertJsonPath('ends_at', '2026-11-02T08:00:00.000000Z');

        $this->assertSame(1, Booking::count());
    }

    public function test_ignores_user_id_from_request(): void
    {
        $other = User::factory()->create();

        $this->postBooking([
            'room_id' => $this->room->id,
            'user_id' => $other->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ])->assertCreated();

        $this->assertSame($this->user->id, Booking::first()->user_id);
    }

    public function test_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/bookings', [])->assertUnauthorized();
    }

    public function test_requires_authentication_without_json_accept_header(): void
    {
        $this->app['auth']->forgetGuards();

        $this->post('/api/bookings', [])->assertUnauthorized();
    }

    public function test_rejects_missing_room_id(): void
    {
        $this->postBooking([
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ])->assertStatus(422)->assertJsonValidationErrors(['room_id']);
    }

    public function test_rejects_non_numeric_room_id(): void
    {
        $this->postBooking([
            'room_id' => 'Арбат',
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ])->assertStatus(422)->assertJsonValidationErrors(['room_id']);
    }

    public function test_rejects_unknown_room_id(): void
    {
        $this->postBooking([
            'room_id' => 999,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ])->assertStatus(422)->assertJsonValidationErrors(['room_id']);
    }

    public function test_rejects_missing_title(): void
    {
        $this->postBooking([
            'room_id' => $this->room->id,
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ])->assertStatus(422)->assertJsonValidationErrors(['title']);
    }

    public function test_rejects_title_longer_than_255(): void
    {
        $this->postBooking([
            'room_id' => $this->room->id,
            'title' => str_repeat('я', 256),
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ])->assertStatus(422)->assertJsonValidationErrors(['title']);
    }

    public function test_rejects_malformed_datetime(): void
    {
        $this->postBooking([
            'room_id' => $this->room->id,
            'title' => 'Планёрка',
            'starts_at' => 'вчера',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ])->assertStatus(422)->assertJsonValidationErrors(['starts_at']);
    }

    public function test_rejects_datetime_without_offset(): void
    {
        $this->postBooking([
            'room_id' => $this->room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T10:00:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ])->assertStatus(422)->assertJsonValidationErrors(['starts_at']);
    }

    public function test_rejects_impossible_date(): void
    {
        $this->postBooking([
            'room_id' => $this->room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-02-30T10:00:00+03:00',
            'ends_at' => '2026-03-02T11:00:00+03:00',
        ])->assertStatus(422)->assertJsonValidationErrors(['starts_at']);
    }

    public function test_rejects_seconds_not_on_grid(): void
    {
        $this->postBooking([
            'room_id' => $this->room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T10:00:30+03:00',
            'ends_at' => '2026-11-02T11:00:30+03:00',
        ])->assertStatus(422)->assertJsonValidationErrors(['starts_at']);
    }

    public function test_rejects_ends_before_starts(): void
    {
        $this->postBooking([
            'room_id' => $this->room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T09:00:00+03:00',
        ])->assertStatus(422)->assertJsonValidationErrors(['ends_at']);
    }

    public function test_rejects_duration_shorter_than_15_minutes(): void
    {
        $this->postBooking([
            'room_id' => $this->room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T10:05:00+03:00',
        ])->assertStatus(422)->assertJsonValidationErrors(['ends_at']);
    }

    public function test_rejects_duration_longer_than_4_hours(): void
    {
        $this->postBooking([
            'room_id' => $this->room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T14:15:00+03:00',
        ])->assertStatus(422)->assertJsonValidationErrors(['ends_at']);
    }

    public function test_rejects_start_not_on_15_minute_grid(): void
    {
        $this->postBooking([
            'room_id' => $this->room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T10:07:00+03:00',
            'ends_at' => '2026-11-02T11:07:00+03:00',
        ])->assertStatus(422)->assertJsonValidationErrors(['starts_at']);
    }

    public function test_rejects_start_before_working_hours(): void
    {
        $this->postBooking([
            'room_id' => $this->room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T08:45:00+03:00',
            'ends_at' => '2026-11-02T09:15:00+03:00',
        ])->assertStatus(422)->assertJsonValidationErrors(['starts_at']);
    }

    public function test_rejects_end_after_working_hours(): void
    {
        $this->postBooking([
            'room_id' => $this->room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T19:45:00+03:00',
            'ends_at' => '2026-11-02T20:15:00+03:00',
        ])->assertStatus(422)->assertJsonValidationErrors(['ends_at']);
    }

    public function test_uses_office_timezone_not_request_offset(): void
    {
        $novosibirskRoom = $this->novosibirskRoom();

        // 11:00 по Новосибирску, переданное как 07:00+03:00.
        $this->postBooking([
            'room_id' => $novosibirskRoom->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T07:00:00+03:00',
            'ends_at' => '2026-11-02T08:00:00+03:00',
        ])->assertCreated();
    }

    public function test_rejects_overlapping_booking(): void
    {
        $this->postBooking([
            'room_id' => $this->room->id,
            'title' => 'Первая',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ])->assertCreated();

        $this->postBooking([
            'room_id' => $this->room->id,
            'title' => 'Вторая',
            'starts_at' => '2026-11-02T10:30:00+03:00',
            'ends_at' => '2026-11-02T11:30:00+03:00',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['starts_at'])
            ->assertJsonFragment(['Комната занята в это время.']);
    }

    public function test_allows_bookings_touching_in_time(): void
    {
        $this->postBooking([
            'room_id' => $this->room->id,
            'title' => 'Первая',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ])->assertCreated();

        $this->postBooking([
            'room_id' => $this->room->id,
            'title' => 'Вторая',
            'starts_at' => '2026-11-02T11:00:00+03:00',
            'ends_at' => '2026-11-02T12:00:00+03:00',
        ])->assertCreated();

        $this->assertSame(2, Booking::count());
    }

    public function test_creates_booking_in_the_past(): void
    {
        $this->postBooking([
            'room_id' => $this->room->id,
            'title' => 'Вчерашняя',
            'starts_at' => '2026-11-01T10:00:00+03:00',
            'ends_at' => '2026-11-01T11:00:00+03:00',
        ])->assertCreated();
    }

    public function test_accepts_datetime_with_z_suffix(): void
    {
        $this->postBooking([
            'room_id' => $this->room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T07:00:00Z',
            'ends_at' => '2026-11-02T08:00:00Z',
        ])->assertCreated();
    }

    public function test_time_columns_store_timestamptz(): void
    {
        foreach (['starts_at', 'ends_at'] as $column) {
            $type = DB::selectOne(
                'SELECT data_type FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
                ['bookings', $column],
            )->data_type;

            $this->assertSame('timestamp with time zone', $type, $column);
        }
    }

    public function test_stored_value_carries_utc_offset(): void
    {
        // 17:00 по Новосибирску = 13:00 по Москве = 10:00Z.
        $response = $this->postBooking([
            'room_id' => $this->room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T17:00:00+07:00',
            'ends_at' => '2026-11-02T18:00:00+07:00',
        ])->assertCreated();

        $raw = (string) DB::table('bookings')
            ->where('id', $response->json('id'))
            ->value('starts_at');

        $this->assertStringStartsWith('2026-11-02 10:00:00', $raw);
        $this->assertMatchesRegularExpression('/\+\d{2}$/', $raw);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postBooking(array $payload): TestResponse
    {
        return $this->postJson('/api/bookings', $payload);
    }

    private function moscowRoom(): Room
    {
        $office = Office::factory()->create(['timezone' => 'Europe/Moscow']);

        return $office->rooms()->create(['name' => 'Арбат', 'capacity' => 6]);
    }

    private function novosibirskRoom(): Room
    {
        $office = Office::factory()->novosibirsk()->create();

        return $office->rooms()->create(['name' => 'Обь', 'capacity' => 8]);
    }
}
