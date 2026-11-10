<?php

namespace App\Http\Requests;

use App\Rules\Iso8601WithOffset;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'string', new Iso8601WithOffset],
            'ends_at' => ['required', 'string', new Iso8601WithOffset],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'room_id.required' => 'Укажите комнату.',
            'room_id.integer' => 'Номер комнаты должен быть целым числом.',
            'room_id.exists' => 'Комната с таким id не найдена.',
            'title.required' => 'Укажите название встречи.',
            'title.string' => 'Название встречи должно быть строкой.',
            'title.max' => 'Название не длиннее 255 символов.',
            'starts_at.required' => 'Укажите время начала.',
            'starts_at.string' => 'Время должно быть строкой в формате ISO 8601 со смещением.',
            'ends_at.required' => 'Укажите время окончания.',
            'ends_at.string' => 'Время должно быть строкой в формате ISO 8601 со смещением.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'room_id' => 'комната',
            'title' => 'название встречи',
            'starts_at' => 'время начала',
            'ends_at' => 'время окончания',
        ];
    }

    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->string('starts_at')->toString())->utc();
    }

    public function endsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->string('ends_at')->toString())->utc();
    }
}
