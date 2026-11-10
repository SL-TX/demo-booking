<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingDestroyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->booking = Booking::factory()->for($this->user)->create();

        Sanctum::actingAs($this->user);
    }

    public function test_author_can_cancel_own_booking(): void
    {
        $this->deleteJson("/api/bookings/{$this->booking->id}")->assertNoContent();

        $this->assertSame(0, Booking::count());
    }

    public function test_cannot_cancel_booking_of_another_user(): void
    {
        $other = User::factory()->create();

        Sanctum::actingAs($other);

        $this->deleteJson("/api/bookings/{$this->booking->id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'Отменить бронь может только её автор.');

        $this->assertSame(1, Booking::count());
    }

    public function test_returns_not_found_for_unknown_booking(): void
    {
        $this->deleteJson('/api/bookings/9999')->assertNotFound();
    }

    public function test_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->deleteJson("/api/bookings/{$this->booking->id}")->assertUnauthorized();

        $this->assertSame(1, Booking::count());
    }
}
