<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingService
{
    private const WORKING_DAY_START_HOUR = 9;

    private const WORKING_DAY_END_HOUR = 20;

    private const MIN_DURATION_SECONDS = 900;

    private const MAX_DURATION_SECONDS = 14400;

    private const GRID_MINUTES = 15;

    /**
     * Создать бронь, проверив все правила: длительность, шаг 15 минут, рабочее время офиса
     * и отсутствие пересечения с другими бронями той же комнаты.
     *
     * @param  array{room_id: int, title: string, starts_at: CarbonInterface, ends_at: CarbonInterface}  $data
     *
     * @throws ValidationException
     */
    public function create(User $user, array $data): Booking
    {
        $startsAt = $data['starts_at']->copy()->utc();
        $endsAt = $data['ends_at']->copy()->utc();

        return DB::transaction(function () use ($user, $data, $startsAt, $endsAt) {
            // Блокируем строку комнаты до проверки конфликта: одновременные брони одной
            // комнаты выстраиваются в очередь и не могут пересечься.
            $room = Room::with('office')->lockForUpdate()->findOrFail($data['room_id']);

            $errors = $this->collectRuleViolations($room, $startsAt, $endsAt);

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            return Booking::create([
                'room_id' => $room->id,
                'user_id' => $user->id,
                'title' => $data['title'],
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);
        });
    }

    /**
     * Отменить бронь. Отменить может только её автор.
     *
     * @throws AuthorizationException
     */
    public function delete(Booking $booking, User $user): void
    {
        if ($booking->user_id !== $user->id) {
            throw new AuthorizationException('Отменить бронь может только её автор.');
        }

        $booking->delete();
    }

    /**
     * Нарушения правил брони: ключ — поле, которое клиент должен изменить.
     *
     * @return array<string, list<string>>
     */
    private function collectRuleViolations(Room $room, CarbonInterface $startsAt, CarbonInterface $endsAt): array
    {
        $timezone = $room->office->timezone;
        $localStart = $startsAt->copy()->setTimezone($timezone);
        $localEnd = $endsAt->copy()->setTimezone($timezone);

        $errors = [];

        if ($endsAt->getTimestamp() <= $startsAt->getTimestamp()) {
            $errors['ends_at'][] = 'Время окончания должно быть позже времени начала.';
        }

        $duration = $endsAt->getTimestamp() - $startsAt->getTimestamp();

        if ($duration < self::MIN_DURATION_SECONDS || $duration > self::MAX_DURATION_SECONDS) {
            $errors['ends_at'][] = 'Длительность брони — от 15 минут до 4 часов.';
        }

        if (! $this->isOnQuarterHourGrid($localStart)) {
            $errors['starts_at'][] = 'Время начала должно быть кратно 15 минутам.';
        }

        if (! $this->isOnQuarterHourGrid($localEnd)) {
            $errors['ends_at'][] = 'Время окончания должно быть кратно 15 минутам.';
        }

        if ($localStart->toDateString() !== $localEnd->toDateString()) {
            $errors['ends_at'][] = 'Бронь должна целиком помещаться в один рабочий день офиса.';
        } else {
            $workingDayStart = $localStart->copy()->startOfDay()->setTime(self::WORKING_DAY_START_HOUR, 0);
            $workingDayEnd = $localStart->copy()->startOfDay()->setTime(self::WORKING_DAY_END_HOUR, 0);

            if ($localStart->lt($workingDayStart)) {
                $errors['starts_at'][] = sprintf(
                    'Бронь должна начинаться не раньше %02d:00 по местному времени офиса (%s).',
                    self::WORKING_DAY_START_HOUR,
                    $timezone,
                );
            }

            if ($localEnd->gt($workingDayEnd)) {
                $errors['ends_at'][] = sprintf(
                    'Бронь должна заканчиваться не позже %02d:00 по местному времени офиса (%s).',
                    self::WORKING_DAY_END_HOUR,
                    $timezone,
                );
            }
        }

        if (Booking::conflicting($room->id, $startsAt, $endsAt)->exists()) {
            $errors['starts_at'][] = 'Комната занята в это время.';
        }

        return $errors;
    }

    private function isOnQuarterHourGrid(CarbonInterface $moment): bool
    {
        return $moment->minute % self::GRID_MINUTES === 0
            && $moment->second === 0
            && $moment->micro === 0;
    }
}
