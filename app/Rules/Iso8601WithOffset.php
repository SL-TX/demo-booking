<?php

namespace App\Rules;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Throwable;

class Iso8601WithOffset implements ValidationRule
{
    /**
     * Дата, время и обязательное смещение: `2026-11-02T10:00:00+03:00`, `...T10:00Z`, `... 10:00+0300`.
     */
    private const FORMAT = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(\.\d{1,6})?(Z|[+-]\d{2}:?\d{2})$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match(self::FORMAT, $value)) {
            $fail('Укажите время в формате ISO 8601 со смещением, например 2026-11-02T10:00:00+03:00.');

            return;
        }

        try {
            $parsed = CarbonImmutable::parse($value);
        } catch (Throwable) {
            $fail('Укажите корректную дату и время.');

            return;
        }

        // Carbon молча перекатывает несуществующие даты (2026-02-30 → 2026-03-02),
        // поэтому разобранную дату сверяем с датой в исходной строке.
        if ($parsed->format('Y-m-d') !== substr($value, 0, 10)) {
            $fail('Укажите корректную дату и время.');
        }
    }
}
