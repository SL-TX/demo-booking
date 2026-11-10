<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BookingServiceTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(BookingService::class);
    }

    public function test_creates_booking_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        $room = $this->moscowRoom();

        $booking = $this->service->create($user, $this->data(
            $room,
            '2026-11-02T10:00:00+03:00',
            '2026-11-02T11:00:00+03:00',
        ));

        $this->assertSame(1, Booking::count());
        $this->assertSame($user->id, $booking->user_id);
        $this->assertSame($room->id, $booking->room_id);
        $this->assertStringStartsWith(
            '2026-11-02 07:00:00',
            (string) DB::table('bookings')->where('id', $booking->id)->value('starts_at'),
        );
    }

    public function test_rejects_ends_before_starts(): void
    {
        $this->assertRuleFails(
            'ends_at',
            $this->data(
                $this->moscowRoom(),
                '2026-11-02T10:00:00+03:00',
                '2026-11-02T09:00:00+03:00',
            ),
        );
    }

    public function test_rejects_duration_shorter_than_15_minutes(): void
    {
        $this->assertRuleFails(
            'ends_at',
            $this->data(
                $this->moscowRoom(),
                '2026-11-02T10:00:00+03:00',
                '2026-11-02T10:14:00+03:00',
            ),
        );
    }

    public function test_rejects_duration_longer_than_4_hours(): void
    {
        $this->assertRuleFails(
            'ends_at',
            $this->data(
                $this->moscowRoom(),
                '2026-11-02T10:00:00+03:00',
                '2026-11-02T14:01:00+03:00',
            ),
        );
    }

    public function test_accepts_minimum_and_maximum_duration(): void
    {
        $user = User::factory()->create();
        $room = $this->moscowRoom();

        $this->service->create($user, $this->data(
            $room,
            '2026-11-02T10:00:00+03:00',
            '2026-11-02T10:15:00+03:00',
        ));

        $this->service->create($user, $this->data(
            $room,
            '2026-11-02T11:00:00+03:00',
            '2026-11-02T15:00:00+03:00',
        ));

        $this->assertSame(2, Booking::count());
    }

    public function test_rejects_start_not_on_15_minute_grid(): void
    {
        $this->assertRuleFails(
            'starts_at',
            $this->data(
                $this->moscowRoom(),
                '2026-11-02T10:07:00+03:00',
                '2026-11-02T11:07:00+03:00',
            ),
        );
    }

    public function test_rejects_end_not_on_15_minute_grid(): void
    {
        $this->assertRuleFails(
            'ends_at',
            $this->data(
                $this->moscowRoom(),
                '2026-11-02T10:00:00+03:00',
                '2026-11-02T11:07:00+03:00',
            ),
        );
    }

    public function test_rejects_start_before_working_hours(): void
    {
        $this->assertRuleFails(
            'starts_at',
            $this->data(
                $this->moscowRoom(),
                '2026-11-02T08:45:00+03:00',
                '2026-11-02T09:15:00+03:00',
            ),
        );
    }

    public function test_rejects_end_after_working_hours(): void
    {
        $this->assertRuleFails(
            'ends_at',
            $this->data(
                $this->moscowRoom(),
                '2026-11-02T19:45:00+03:00',
                '2026-11-02T20:15:00+03:00',
            ),
        );
    }

    public function test_accepts_working_hours_boundaries(): void
    {
        $user = User::factory()->create();
        $room = $this->moscowRoom();

        $this->service->create($user, $this->data(
            $room,
            '2026-11-02T09:00:00+03:00',
            '2026-11-02T09:15:00+03:00',
        ));

        $this->service->create($user, $this->data(
            $room,
            '2026-11-02T19:45:00+03:00',
            '2026-11-02T20:00:00+03:00',
        ));

        $this->assertSame(2, Booking::count());
    }

    public function test_rejects_booking_crossing_midnight(): void
    {
        $exception = $this->captureRuleFailure($this->data(
            $this->moscowRoom(),
            '2026-11-02T23:00:00+03:00',
            '2026-11-03T01:00:00+03:00',
        ));

        $this->assertArrayHasKey('ends_at', $exception->errors());
        $this->assertStringContainsString(
            'один рабочий день',
            $exception->errors()['ends_at'][0],
        );
    }

    public function test_uses_office_timezone_not_request_offset(): void
    {
        $user = User::factory()->create();

        // 11:00 по Новосибирску — переданные как 07:00+03:00 (в Москве это 07:00, нерабочее время).
        $this->service->create($user, $this->data(
            $this->novosibirskRoom(),
            '2026-11-02T07:00:00+03:00',
            '2026-11-02T08:00:00+03:00',
        ));

        $this->assertSame(1, Booking::count());
    }

    public function test_uses_office_timezone_for_moscow_room(): void
    {
        $user = User::factory()->create();

        // 17:00 по Москве — переданные как 21:00+07:00 (в Новосибирске это 21:00, нерабочее время).
        $this->service->create($user, $this->data(
            $this->moscowRoom(),
            '2026-11-02T21:00:00+07:00',
            '2026-11-02T22:00:00+07:00',
        ));

        $this->assertSame(1, Booking::count());
    }

    public function test_rejects_time_outside_office_working_hours(): void
    {
        $exception = $this->captureRuleFailure($this->data(
            $this->novosibirskRoom(),
            '2026-11-02T04:00:00+03:00',
            '2026-11-02T05:00:00+03:00',
        ));

        $this->assertArrayHasKey('starts_at', $exception->errors());
        $this->assertStringContainsString(
            'Asia/Novosibirsk',
            $exception->errors()['starts_at'][0],
        );
    }

    public function test_rejects_overlapping_booking(): void
    {
        $user = User::factory()->create();
        $room = $this->moscowRoom();

        $this->service->create($user, $this->data(
            $room,
            '2026-11-02T10:00:00+03:00',
            '2026-11-02T11:00:00+03:00',
        ));

        $exception = $this->captureRuleFailure($this->data(
            $room,
            '2026-11-02T10:30:00+03:00',
            '2026-11-02T11:30:00+03:00',
        ));

        $this->assertArrayHasKey('starts_at', $exception->errors());
        $this->assertStringContainsString(
            'Комната занята в это время.',
            $exception->errors()['starts_at'][0],
        );
    }

    public function test_allows_bookings_touching_in_time(): void
    {
        $user = User::factory()->create();
        $room = $this->moscowRoom();

        $this->service->create($user, $this->data(
            $room,
            '2026-11-02T10:00:00+03:00',
            '2026-11-02T11:00:00+03:00',
        ));

        $this->service->create($user, $this->data(
            $room,
            '2026-11-02T11:00:00+03:00',
            '2026-11-02T12:00:00+03:00',
        ));

        $this->assertSame(2, Booking::count());
    }

    public function test_allows_same_time_in_another_room(): void
    {
        $user = User::factory()->create();

        $this->service->create($user, $this->data(
            $this->moscowRoom(),
            '2026-11-02T10:00:00+03:00',
            '2026-11-02T11:00:00+03:00',
        ));

        $this->service->create($user, $this->data(
            $this->moscowRoom(),
            '2026-11-02T10:00:00+03:00',
            '2026-11-02T11:00:00+03:00',
        ));

        $this->assertSame(2, Booking::count());
    }

    public function test_same_moment_with_different_offset_is_a_conflict(): void
    {
        $user = User::factory()->create();
        $room = $this->moscowRoom();

        // 10:00 по Москве — переданное как 07:00Z.
        $this->service->create($user, $this->data(
            $room,
            '2026-11-02T10:00:00+03:00',
            '2026-11-02T11:00:00+03:00',
        ));

        // Тот же момент (07:00Z), но с другим смещением.
        $exception = $this->captureRuleFailure($this->data(
            $room,
            '2026-11-02T07:00:00Z',
            '2026-11-02T08:00:00Z',
        ));

        $this->assertArrayHasKey('starts_at', $exception->errors());
        $this->assertStringContainsString(
            'Комната занята в это время.',
            $exception->errors()['starts_at'][0],
        );
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

    /**
     * @param  array<string, mixed>  $data
     */
    private function data(Room $room, string $startsAt, string $endsAt): array
    {
        return [
            'room_id' => $room->id,
            'title' => 'Встреча',
            'starts_at' => CarbonImmutable::parse($startsAt),
            'ends_at' => CarbonImmutable::parse($endsAt),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertRuleFails(string $field, array $data): void
    {
        $exception = $this->captureRuleFailure($data);

        $this->assertArrayHasKey($field, $exception->errors());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function captureRuleFailure(array $data): ValidationException
    {
        try {
            $this->service->create(User::factory()->create(), $data);
        } catch (ValidationException $exception) {
            return $exception;
        }

        $this->fail('Ожидалось нарушение правил брони.');
    }
}
