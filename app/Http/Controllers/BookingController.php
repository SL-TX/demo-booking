<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Room;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        return Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    public function store(StoreBookingRequest $request, BookingService $bookings): JsonResponse
    {
        $booking = $bookings->create($request->user(), [
            'room_id' => $request->integer('room_id'),
            'title' => $request->string('title')->toString(),
            'starts_at' => $request->startsAt(),
            'ends_at' => $request->endsAt(),
        ]);

        Log::channel('bookings')->info('Booking created', [
            'booking_id' => $booking->id,
            'room_id' => $booking->room_id,
            'user_id' => $booking->user_id,
        ]);

        return response()->json($booking, 201);
    }

    public function destroy($id)
    {
        Booking::findOrFail($id)->delete();

        return response()->noContent();
    }

    public function today(Room $room)
    {
        return $room->bookings()
            ->whereDate('starts_at', today())
            ->get()
            ->map(fn ($b) => [
                'time' => $b->starts_at.' – '.$b->ends_at,
                'author' => $b->user->name,
            ]);
    }
}
