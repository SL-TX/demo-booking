# API бронирования переговорных — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Довести `store` и `destroy` API бронирования до правил задания (непересекающиеся брони, длительность 15 мин – 4 ч, шаг 15 мин, рабочее время 09:00–20:00 в часовом поясе офиса, отмена только автором, понятные 422) и закрыть каждый пункт feature-тестами; попутно выпустить `REVIEW.md` и `AI_NOTES.md`.

**Architecture:** Контроллер остаётся тонким. `StoreBookingRequest` отвечает за форму входа (обязательность, типы, формат ISO 8601 со смещением, существование комнаты), `BookingService` — за доменные правила (длительность, шаг, рабочее время, конфликт) и за атомарность записи, `Booking::scopeConflicting()` — за запрос пересечения. Всё время нормализуется в UTC на входе; колонки `starts_at`/`ends_at` переводятся в `timestamptz`, а TZ сессии Postgres фиксируется как UTC.

**Tech Stack:** PHP 8.4, Laravel 13.39, PostgreSQL 17, Sanctum 4.3, PHPUnit 12.5, Pint 1.32. Docker Compose (`docker compose exec -T app …`).

**Spec:** `docs/superpowers/specs/2026-11-11-booking-api-design.md` — план реализует эту спецификацию; читать оба.

## Global Constraints

- Стек и зависимости не меняются. Новых composer-пакетов нет.
- Рабочее время офиса: **09:00–20:00** по `office.timezone`; длительность **от 900 до 14400 секунд**; шаг **15 минут**.
- На вход принимается **ISO 8601 со смещением** (`+03:00`, `+0300`, `Z`, `+07:00`); дата **без смещения → 422**.
- Все нарушения правил → **422** в формате `{"message": "…", "errors": {поле: ["…"]}}`. Отмена чужой брони → **403**. Неавторизованный запрос → **401**.
- `user_id` из тела запроса **игнорируется**; автор = владелец токена.
- Инвариант времени: в БД лежит **момент времени** (UTC). Наружу API отдаёт UTC с суффиксом `Z`.
- Методы `index` и `today` **не изменяются** (требование задания).
- Русский язык в сообщениях об ошибках и в пользовательских текстах.
- Стиль кода — Pint (`vendor/bin/pint --dirty --format agent`) перед каждым коммитом.
- Каждый коммит собирается и проходит тесты.

## Review Focus

Пять классов входных данных, которые спецификация подразумевает, но которые ни один явный пункт задания не называет — каждый закрыт тестом в указанной задаче:

1. **Один и тот же момент, присланный с разными смещениями** (`10:00+07:00` и `03:00+03:00`) — это один и тот же слот: вторая бронь должна получить 422 «Комната занята в это время.», а не создаться. → Task 2.
2. **Дата без смещения** (`2026-11-02T10:00:00`) — 422, а не молчаливая трактовка как UTC (иначе бронь уезжает на 3–7 часов). → Task 3.
3. **Секунды ≠ 0** (`10:00:30`) — 422: бронь не кратна 15 минутам. → Task 3.
4. **Бронь в прошлом** — создаётся (201): «других ограничений на даты нет». → Task 3.
5. **`title` длиннее 255 символов** — 422, а не 500 от ограничения БД. → Task 3.

> Дополнение, найденное при проверке на стенде: `CarbonImmutable::parse('2026-02-30T10:00:00+03:00')` **не бросает исключение**, а перекатывает дату на 2026-03-02. Поэтому правило `Iso8601WithOffset` обязано дополнительно сверять разобранную дату с датой в исходной строке (шаг Task 3, Step 3) — иначе «2026-02-30» молча превратится в 2 марта.

---

## Карта файлов

| Файл | Ответственность |
| --- | --- |
| `app/Rules/Iso8601WithOffset.php` (создать) | Правило валидации: строка — корректная ISO 8601 со смещением |
| `app/Http/Requests/StoreBookingRequest.php` (создать) | Форма входа: правила, сообщения, `startsAt()`/`endsAt()`/`room()` |
| `app/Services/BookingService.php` (создать) | Доменные правила брони + транзакция/блокировка + проверка автора |
| `app/Models/Booking.php` (изменить) | `scopeConflicting()` — запрос пересечения со строгими неравенствами |
| `app/Http/Controllers/BookingController.php` (изменить) | `store` и `destroy`; `index`/`today` не трогаем |
| `routes/api.php` (изменить) | `{id}` → `{booking}` для привязки модели |
| `bootstrap/app.php` (изменить) | `redirectGuestsTo(fn () => null)` — убрать 500 «Route [login] not defined» |
| `config/database.php` (изменить) | `'timezone' => 'UTC'` для соединения `pgsql` |
| `database/migrations/*_change_bookings_time_columns_to_timestamptz.php` (создать) | `starts_at`/`ends_at` → `timestamptz` (имя файла генерирует `make:migration`) |
| `tests/Feature/BookingServiceTest.php` (создать) | Доменные правила через сервис |
| `tests/Feature/BookingStoreTest.php` (создать) | HTTP-контракт создания брони |
| `tests/Feature/BookingDestroyTest.php` (создать) | HTTP-контракт отмены брони |
| `tests/Feature/BookingConcurrencyTest.php` (создать) | Блокировка комнаты при одновременных запросах |
| `phpunit.xml` (изменить) | `APP_DEBUG=false` |
| `REVIEW.md`, `AI_NOTES.md`, `README.md` (создать/изменить) | Части 1 и 3 задания, запуск и тесты |

---

### Task 1: Ревью API бронирования

**Files:**
- Create: `REVIEW.md`

**Interfaces:**
- Consumes: ничего (задача 1, самостоятельная).
- Produces: `REVIEW.md` — разбор исходного кода; фиксирует терминологию и список дефектов, на которые ссылаются остальные задачи.

- [ ] **Step 1: Собрать факты о дефектах на стенде**

Поднять окружение и собрать доказательства (стенд уже поднимался — команды ниже воспроизводимы):

```sh
docker compose up -d --wait
docker compose exec -T app php artisan migrate:fresh --seed
```

Проверить и зафиксировать факты (по одному `curl` + запрос в БД):

```sh
curl -s -X POST http://localhost:8000/api/bookings -H "Authorization: Bearer anna-token" -H "Accept: application/json" -H "Content-Type: application/json" -d '{"room_id":1,"user_id":2,"title":"X","starts_at":"2026-11-02T10:00:00+03:00","ends_at":"2026-11-02T11:00:00+03:00"}'
docker compose exec -T postgres psql -U booking -d booking -c "SELECT id, room_id, user_id, starts_at, ends_at FROM bookings ORDER BY id;"
docker compose exec -T postgres psql -U booking -d booking -c "SELECT column_name, data_type FROM information_schema.columns WHERE table_name='bookings';"
```

Подтвердить: (1) `user_id` в ответе равен присланному `2`, а не владельцу токена; (2) в БД `starts_at = 2026-11-02 10:00:00` — стенное время клиента, а не момент; (3) тип колонки `timestamp without time zone`.

Остальные дефекты — по коду `app/Http/Controllers/BookingController.php`:
SQL-инъекция в `DB::select` (интерполяция `room_id`, `starts_at`, `ends_at`); конфликт ищется с `starts_at <= … AND ends_at >= …` («впритык» запрещён); рабочее время проверяется в смещении запроса вместо `office.timezone` и только по началу; нет проверок длительности, шага 15 минут, `ends_at > starts_at`; нет валидации (FK/NOT NULL → 500); `destroy` без проверки автора; `Log::withContext()` не доходит до канала `bookings`; без `Accept: application/json` → 500 «Route [login] not defined».

- [ ] **Step 2: Написать `REVIEW.md`**

Структура:

1. **Краткое резюме** — что чинили и почему.
2. **Проблемы** — каждая: код места, что происходит (с примером запроса/ответа), серьёзность (critical / major / minor), как исправить. Обязательный состав:
   - critical: SQL-инъекция; `user_id` из тела запроса; `destroy` без проверки автора; хранение наивного стенного времени вместо момента (с примером `14:00+03:00` → в БД `14:00`, в ответе `14:00Z`);
   - major: неверное сравнение границ интервалов; рабочее время в смещении запроса вместо TZ офиса; конец брони не проверяется; нет проверки длительности; нет шага 15 минут; нет проверки `ends_at > starts_at`; отсутствие валидации → 500; гонка check-then-insert;
   - minor: `Log::withContext()` не доходит до канала `bookings`; 500 вместо 401 без `Accept`; неоднородный формат ошибок (`{"error": …}`); `Booking::create($request->all())` возвращает наружу все поля модели; нет `max` для `title` → 500;
   - **разбор `index` и `today` без правок**: `today` считает «сегодня» в TZ сервера (UTC), а не офиса (между 17:00 и 24:00 UTC «сегодня» офиса уже наступило, а ответ за вчера); `whereDate` не использует индекс; `today` отдаёт время строкой `"2026-11-02 14:00:00 – 15:00:00"` без часового пояса; `index`/`store` отдают время с суффиксом `Z`, **заявляя UTC**, хотя там стенное время клиента — суффикс `Z` был ложью.
3. **«Выглядит подозрительно, но проблемой не является»** — отдельный раздел:
   - `orderByRaw('starts_at DESC')` — константа, пользовательского ввода нет, инъекции нет; менять на `orderBy()` — вопрос стиля;
   - `#[Fillable]` действительно ограничивает масс-присваивание (нельзя записать произвольные поля) — проблема только в том, что `user_id` в списке;
   - **в `Europe/Moscow` и `Asia/Novosibirsk` нет перехода на летнее время** (смещения постоянны +3 и +7, проверено `DateTimeZone::getTransitions()`) — поэтому правило рабочего времени стабильно и отдельной обработки DST не нужно; решение всё равно опирается на часовой пояс офиса, а не на фиксированное смещение;
   - `Log::channel('bookings')` в конфигурации есть и работает — проблема не в канале, а в способе передачи контекста;
   - `Booking::create()` возвращает 201 — это поведение Laravel для только что созданной модели, а не баг.
4. **Что не трогаем и почему** — `index`/`today` (требование задания), версионирование API, ресурсы-сериализаторы, rate limiting, ограничения на даты.

Каждый пункт — 3–6 строк. Файл на русском.

- [ ] **Step 3: Проверить `REVIEW.md`**

Прочитать файл и убедиться, что каждый дефект из Step 1 присутствует, у каждого есть серьёзность и способ исправления, и что есть раздел «подозрительно, но не баг». Ничего не запускать — файл документальный.

- [ ] **Step 4: Commit**

```sh
git add REVIEW.md
git commit -m "Ревью API бронирования: разбор дефектов и план исправлений"
```

---

### Task 2: Доменные правила брони в сервисе

**Files:**
- Create: `app/Services/BookingService.php`
- Modify: `app/Models/Booking.php`
- Test: `tests/Feature/BookingServiceTest.php`

**Interfaces:**
- Consumes: существующие модели `App\Models\User`, `App\Models\Room`, `App\Models\Office`, `App\Models\Booking`.
- Produces:
  - `App\Services\BookingService::create(User $user, array $data): Booking` — `$data` = `['room_id' => int, 'title' => string, 'starts_at' => CarbonInterface, 'ends_at' => CarbonInterface]`. Бросает `Illuminate\Validation\ValidationException` (сообщения по полям) при нарушении правил.
  - `App\Services\BookingService::delete(Booking $booking, User $user): void` — бросает `Illuminate\Auth\Access\AuthorizationException`, если не автор. В Task 2 реализуется как заглушка `throw new AuthorizationException(...)` + `delete()` — используется в Task 4.
  - `App\Models\Booking::scopeConflicting(Builder $query, int $roomId, CarbonInterface $startsAt, CarbonInterface $endsAt): Builder` — `starts_at < $endsAt` **AND** `ends_at > $startsAt` (строго), оба значения приводятся к UTC.

- [ ] **Step 1: Написать падающие тесты доменных правил**

Создать `tests/Feature/BookingServiceTest.php` (класс `BookingServiceTest extends Tests\TestCase`, `use RefreshDatabase;`).

Фикстуры: `User::factory()->create()`, `Office::factory()->create(['timezone' => 'Europe/Moscow'])` → `$moscow->rooms()->create(['name' => 'Арбат', 'capacity' => 6])`; офис Новосибирска — `Office::factory()->novosibirsk()->create()` → комната.

Тесты (каждое — один вызов сервиса, ожидаемое исключение или результат):

| Тест | Вход | Ожидание |
| --- | --- | --- |
| `test_creates_booking_for_authenticated_user` | `room_id`, `title`, `starts_at` 2026-11-02 10:00 (Europe/Moscow), `ends_at` 11:00 | `Booking::count() === 1`, `user_id` = `$user->id`, `starts_at` в БД начинается с `2026-11-02 07:00:00` (10:00+03:00 → UTC). Проверять через `assertStringStartsWith` — сырое значение переживёт перевод колонки в `timestamptz` в Task 6 |
| `test_rejects_ends_before_starts` | `ends_at` = 09:00 при `starts_at` 10:00 | `ValidationException`, ключ `ends_at` |
| `test_rejects_duration_shorter_than_15_minutes` | 10:00 → 10:14 | `ValidationException`, ключ `ends_at` |
| `test_rejects_duration_longer_than_4_hours` | 10:00 → 14:01 | `ValidationException`, ключ `ends_at` |
| `test_accepts_minimum_and_maximum_duration` | 10:00 → 10:15 и 10:00 → 14:00 | две брони созданы |
| `test_rejects_start_not_on_15_minute_grid` | 10:07 → 11:07 | `ValidationException`, ключ `starts_at` |
| `test_rejects_end_not_on_15_minute_grid` | 10:00 → 11:07 | `ValidationException`, ключ `ends_at` |
| `test_rejects_start_before_working_hours` | 08:45 → 09:15 (Москва) | `ValidationException`, ключ `starts_at` |
| `test_rejects_end_after_working_hours` | 19:45 → 20:15 (Москва) | `ValidationException`, ключ `ends_at` |
| `test_accepts_working_hours_boundaries` | 09:00 → 09:15 и 19:45 → 20:00 | две брони созданы |
| `test_rejects_booking_crossing_midnight` | 23:00 → 01:00 | `ValidationException`, ключ `ends_at`, сообщение «Бронь должна целиком помещаться в один рабочий день офиса.» |
| `test_uses_office_timezone_not_request_offset` | комната Новосибирска, `starts_at` = 2026-11-02 07:00+03:00 (= 11:00 местного), `ends_at` = 08:00+03:00 | бронь создана (в Москве это 08:00 — нерабочее время, но комната в Новосибирске) |
| `test_uses_office_timezone_for_moscow_room` | комната Москвы, `starts_at` = 2026-11-02 21:00+07:00 (= 14:00 местного в Москве), `ends_at` = 22:00+07:00 | бронь создана (в Новосибирске это 21:00 — нерабочее время) |
| `test_rejects_time_outside_office_working_hours` | комната Новосибирска, `starts_at` = 05:00+03:00 (= 08:00 местного), `ends_at` = 06:00+03:00 (= 09:00 местного) | `ValidationException`, ключ `starts_at`, сообщение «Бронь должна начинаться не раньше 09:00 по местному времени офиса (Asia/Novosibirsk).» |
| `test_rejects_overlapping_booking` | существующая бронь 10:00–11:00, новая 10:30–11:30 | `ValidationException`, ключ `starts_at` |
| `test_allows_bookings_touching_in_time` | существующая 10:00–11:00, новая 11:00–12:00 | вторая бронь создана |
| `test_allows_same_time_in_another_room` | существующая 10:00–11:00 в комнате 1, новая в комнате 2 | вторая бронь создана |
| `test_same_moment_with_different_offset_is_a_conflict` | существующая `starts_at` = 2026-11-02 10:00 **+07:00**, новая = 2026-11-02 03:00 **+03:00** (тот же момент) | `ValidationException`, ключ `starts_at` |

Проверка исключения: `expectException(ValidationException::class)` + `expectExceptionMessageMatches('/Комната занята в это время\./')` либо проверка `$e->errors()` — для проверки ключа использовать:

```php
try {
    $service->create($user, $data);
    $this->fail('Ожидался ValidationException');
} catch (ValidationException $e) {
    $this->assertArrayHasKey('ends_at', $e->errors());
}
```

- [ ] **Step 2: Запустить тесты и убедиться, что они падают**

```sh
docker compose exec -T app php artisan test --filter=BookingServiceTest
```

Ожидание: ошибка «Class "App\Services\BookingService" not found».

- [ ] **Step 3: Реализовать `scopeConflicting` в `app/Models/Booking.php`**

Добавить в `Booking` (импорт `Illuminate\Database\Eloquent\Builder` и `Carbon\CarbonInterface`):

```php
public function scopeConflicting(Builder $query, int $roomId, CarbonInterface $startsAt, CarbonInterface $endsAt): Builder
{
    return $query->where('room_id', $roomId)
        ->where('starts_at', '<', $endsAt->utc())
        ->where('ends_at', '>', $startsAt->utc());
}
```

Строгие неравенства — «впритык» не конфликтует. `->utc()` — защита от не-UTC значения.

- [ ] **Step 4: Реализовать `BookingService`**

Создать `app/Services/BookingService.php`.

Константы класса (значения из спецификации, менять нельзя):

```php
private const WORKING_DAY_START = '09:00';
private const WORKING_DAY_END = '20:00';
private const MIN_DURATION_SECONDS = 900;   // 15 минут
private const MAX_DURATION_SECONDS = 14400; // 4 часа
private const GRID_MINUTES = 15;
```

Сигнатура и поведение:

```php
public function create(User $user, array $data): Booking
public function delete(Booking $booking, User $user): void
```

`create()`:

1. `$startsAt = $data['starts_at']->utc(); $endsAt = $data['ends_at']->utc();`
2. Загрузить комнату вместе с офисом: `Room::with('office')->findOrFail($data['room_id'])`; зона — `$room->office->timezone`.
3. Собрать нарушения в массив `$errors` (ключ поля → массив сообщений). **Все проверки выполняются, не прерываясь на первом:**
   - `$endsAt->getTimestamp() > $startsAt->getTimestamp()` → иначе `ends_at`: «Время окончания должно быть позже времени начала.»
   - длительность `$endsAt->getTimestamp() - $startsAt->getTimestamp()` должна лежать в `[900, 14400]` → иначе `ends_at`: «Длительность брони — от 15 минут до 4 часов.»
     (считать через `getTimestamp()`, а не `diffInSeconds()`: в Carbon 3 `diffInSeconds` знаковый и
     поведение зависит от версии, `getTimestamp()` однозначен);
   - сетка 15 минут: `$localStart->minute % 15 !== 0 || $localStart->second !== 0` → `starts_at`: «Время начала должно быть кратно 15 минутам.»; аналогично для конца → `ends_at`: «Время окончания должно быть кратно 15 минутам.»
   - рабочее время (в `$room->office->timezone`), **в этом порядке**:
     1. если `$localStart->toDateString() !== $localEnd->toDateString()` → `ends_at`: «Бронь должна целиком помещаться в один рабочий день офиса.» — и проверки границ часа **не выполняются** (сравнивать конец следующего дня с 20:00 предыдущего бессмысленно);
     2. иначе `$localStart->lt(локальные 09:00 той же даты)` → `starts_at`: «Бронь должна начинаться не раньше 09:00 по местному времени офиса ({timezone}).»;
     3. иначе/дополнительно `$localEnd->gt(локальные 20:00 той же даты)` → `ends_at`: «Бронь должна заканчиваться не позже 20:00 по местному времени офиса ({timezone}).»
   - конфликт: `Booking::conflicting($data['room_id'], $startsAt, $endsAt)->exists()` → `starts_at`: «Комната занята в это время.»
4. Если `$errors` непуст — `throw ValidationException::withMessages($errors)`.
5. Создать бронь: `Booking::create(['room_id' => $data['room_id'], 'user_id' => $user->id, 'title' => $data['title'], 'starts_at' => $startsAt, 'ends_at' => $endsAt])`.

`delete()`:

```php
if ($booking->user_id !== $user->id) {
    throw new AuthorizationException('Отменить бронь может только её автор.');
}
$booking->delete();
```

Замечания по реализации:
- Локальное время для проверок: `$startsAt->setTimezone($timezone)`, `$endsAt->setTimezone($timezone)`. Сравнение с границами 09:00/20:00 делать в **том же** часовом поясе (строить Carbon на ту же дату в этой зоне и сравнивать с ним).
- Тексты сообщений — **дословно** из таблицы §6.3 спецификации, с подстановкой названия зоны офиса.
- Файл — `declare(strict_types=1);` не добавлять: в проекте такого нет.

- [ ] **Step 5: Запустить тесты и убедиться, что они проходят**

```sh
docker compose exec -T app php artisan test --filter=BookingServiceTest
```

Ожидание: PASS, 18 тестов. Если падает `test_same_moment_with_different_offset_is_a_conflict` — значит, сравнение идёт по стенному времени, а не по моменту: проверить, что `->utc()` применён и в сервисе, и в скоупе. Если падает `test_rejects_time_outside_office_working_hours` с ключом `ends_at` вместо `starts_at` — нарушен порядок проверок рабочего времени (сначала «один рабочий день», потом границы часа).

- [ ] **Step 6: Отформатировать и закоммитить**

```sh
docker compose exec -T app vendor/bin/pint --dirty --format agent
git add app/Services/BookingService.php app/Models/Booking.php tests/Feature/BookingServiceTest.php
git commit -m "Правила брони: длительность, шаг 15 минут, рабочее время офиса, конфликт"
```

---

### Task 3: Форма входа и HTTP-контракт создания брони

**Files:**
- Create: `app/Rules/Iso8601WithOffset.php`
- Create: `app/Http/Requests/StoreBookingRequest.php`
- Modify: `app/Http/Controllers/BookingController.php` (только `store`)
- Modify: `bootstrap/app.php`
- Modify: `phpunit.xml`
- Test: `tests/Feature/BookingStoreTest.php`

**Interfaces:**
- Consumes: `BookingService::create(User $user, array $data): Booking` (Task 2), `Booking::scopeConflicting()` (Task 2).
- Produces:
  - `App\Rules\Iso8601WithOffset` — реализует `Illuminate\Contracts\Validation\ValidationRule`, конструктор без аргументов.
  - `App\Http\Requests\StoreBookingRequest`:
    - `authorize(): bool` → `true`
    - `rules(): array` → `['room_id' => ['required', 'integer', 'exists:rooms,id'], 'title' => ['required', 'string', 'max:255'], 'starts_at' => ['required', 'string', new Iso8601WithOffset], 'ends_at' => ['required', 'string', new Iso8601WithOffset]]`
    - `startsAt(): CarbonImmutable` — разобранное значение в UTC
    - `endsAt(): CarbonImmutable` — разобранное значение в UTC
    - `room(): Room` — модель комнаты по `room_id`
  - `BookingController::store(StoreBookingRequest $request, BookingService $bookings): JsonResponse` — 201 с моделью брони.

- [ ] **Step 1: Написать падающие HTTP-тесты**

Создать `tests/Feature/BookingStoreTest.php` (`use RefreshDatabase;`). Аутентификация — `Sanctum::actingAs($user);` (как в `BookingIndexTest`).

Помощник в тесте: `private function post(array $payload): TestResponse` → `$this->postJson('/api/bookings', $payload);`

Фикстуры: по умолчанию во всех тестах — комната **московского** офиса (`Europe/Moscow`); для тестов
про часовые пояса создаётся комната новосибирского офиса (`Office::factory()->novosibirsk()`).
Времена в примерах ниже — московские, если не указано иное.

| Тест | Ожидание |
| --- | --- |
| `test_creates_booking_with_authenticated_user` | 201; `user_id` = владелец токена; `starts_at` в ответе = `2026-11-02T07:00:00.000000Z` (10:00+03:00 → UTC) |
| `test_ignores_user_id_from_request` | тело с `"user_id": $other->id` → 201, в БД `user_id` = владелец токена |
| `test_requires_authentication` | без токена → 401 |
| `test_requires_authentication_without_json_accept_header` | `$this->post('/api/bookings', [...])` (без заголовка `Accept`) → **401** (не 500). `postJson()` здесь не годится — он сам ставит `Accept: application/json` |
| `test_rejects_missing_room_id` | 422, `errors.room_id` |
| `test_rejects_non_numeric_room_id` | 422, `errors.room_id` |
| `test_rejects_unknown_room_id` | 422, `errors.room_id` |
| `test_rejects_missing_title` | 422, `errors.title` |
| `test_rejects_title_longer_than_255` | 422, `errors.title` |
| `test_rejects_malformed_datetime` | `"starts_at": "вчера"` → 422, `errors.starts_at` |
| `test_rejects_datetime_without_offset` | `"2026-11-02T10:00:00"` → 422, `errors.starts_at` |
| `test_rejects_impossible_date` | `"2026-02-30T10:00:00+03:00"` → 422, `errors.starts_at` |
| `test_rejects_seconds_not_on_grid` | `"10:00:30+03:00"` → 422, `errors.starts_at` |
| `test_rejects_ends_before_starts` | 422, `errors.ends_at` |
| `test_rejects_duration_shorter_than_15_minutes` | 422, `errors.ends_at` |
| `test_rejects_duration_longer_than_4_hours` | 422, `errors.ends_at` |
| `test_rejects_start_not_on_15_minute_grid` | `"10:07:00+03:00"` → 422, `errors.starts_at` |
| `test_rejects_start_before_working_hours` | `"08:45:00+03:00"` → 422, `errors.starts_at` |
| `test_rejects_end_after_working_hours` | `"19:45:00+03:00"` → `"20:15:00+03:00"` → 422, `errors.ends_at` |
| `test_uses_office_timezone_not_request_offset` | комната Новосибирска, `"07:00:00+03:00"` (11:00 местного) → 201 |
| `test_rejects_overlapping_booking` | вторая пересекающаяся → 422, `errors.starts_at`, сообщение «Комната занята в это время.» |
| `test_allows_bookings_touching_in_time` | 11:00–12:00 после 10:00–11:00 → 201 |
| `test_creates_booking_in_the_past` | начало и конец вчера в рабочие часы → 201 (ограничений на даты нет) |
| `test_accepts_datetime_with_z_suffix` | `"2026-11-02T07:00:00Z"` → 201 |

Проверка формы ошибки: `$response->assertStatus(422)->assertJsonValidationErrors(['room_id'])` (Laravel проверяет наличие ключа в `errors`).

- [ ] **Step 2: Запустить тесты и убедиться, что они падают**

```sh
docker compose exec -T app php artisan test --filter=BookingStoreTest
```

Ожидание: падения (валидации нет, контроллер по-прежнему принимает любой ввод).

- [ ] **Step 3: Реализовать правило `Iso8601WithOffset`**

Создать `app/Rules/Iso8601WithOffset.php` — класс реализует `Illuminate\Contracts\Validation\ValidationRule`, конструктор без аргументов, единственный метод `validate(string $attribute, mixed $value, Closure $fail): void`.

Алгоритм внутри:

1. Проверить, что значение — строка, и что она совпадает с шаблоном
   `'/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(\.\d{1,6})?(Z|[+-]\d{2}:?\d{2})$/'`
   (секунды и доли секунды опциональны; смещение обязательно; `+0300` тоже принимается).
   Если нет → `fail('Укажите время в формате ISO 8601 со смещением, например 2026-11-02T10:00:00+03:00.')` и выход.
2. Разобрать значение через `CarbonImmutable::parse($value)` в `try/catch (Throwable)`. Исключение →
   `fail('Укажите корректную дату и время.')` и выход.
3. **Сверить дату:** `$parsed->format('Y-m-d')` должна совпадать с первыми 10 символами исходной строки.
   Carbon перекатывает несуществующие даты (`2026-02-30` → `2026-03-02`), поэтому без этой сверки
   «несуществующая дата» прошла бы валидацию. Не совпало → `fail('Укажите корректную дату и время.')`.

- [ ] **Step 4: Реализовать `StoreBookingRequest`**

Создать `app/Http/Requests/StoreBookingRequest.php` (наследник `Illuminate\Foundation\Http\FormRequest`):

- `authorize(): bool` → `true`;
- `rules(): array` → ровно эти правила, без лишних:

  | Поле | Правила |
  | --- | --- |
  | `room_id` | `['required', 'integer', 'exists:rooms,id']` |
  | `title` | `['required', 'string', 'max:255']` |
  | `starts_at` | `['required', 'string', new Iso8601WithOffset]` |
  | `ends_at` | `['required', 'string', new Iso8601WithOffset]` |

- `messages(): array` — русские тексты: `room_id.required` → «Укажите комнату.», `room_id.exists` → «Комната с таким id не найдена.», `title.required` → «Укажите название встречи.», `title.max` → «Название не длиннее 255 символов.», `starts_at.required` → «Укажите время начала.», `ends_at.required` → «Укажите время окончания.», `starts_at.string`/`ends_at.string` → «Время должно быть строкой в формате ISO 8601 со смещением.»
- `attributes(): array` → `['room_id' => 'комната', 'title' => 'название встречи', 'starts_at' => 'время начала', 'ends_at' => 'время окончания']` (подставляются в тексты правил).
- `startsAt(): CarbonImmutable` → `CarbonImmutable::parse($this->string('starts_at')->toString())->utc()`
- `endsAt(): CarbonImmutable` → то же для `ends_at`
- `room(): Room` → `Room::with('office')->findOrFail($this->integer('room_id'))`

- [ ] **Step 5: Переписать `store` в контроллере**

Заменить метод `store` в `app/Http/Controllers/BookingController.php`:

```php
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
```

Убрать из контроллера неиспользуемые после этого импорты: `Illuminate\Support\Facades\DB`, `Carbon\Carbon` (методы `index` и `today` — **не трогать**).

- [ ] **Step 6: Убрать 500 вместо 401**

В `bootstrap/app.php` в `->withMiddleware(...)` добавить:

```php
$middleware->redirectGuestsTo(fn () => null);
```

Причина: `Illuminate\Auth\Middleware\Authenticate::unauthenticated()` при запросе без `expectsJson()` вызывает `route('login')`, а такого маршрута в приложении нет → 500. С `redirectGuestsTo(fn () => null)` обработчик отдаёт 401 (для API — JSON, т.к. в `bootstrap/app.php` уже есть `shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson())`).

- [ ] **Step 7: Отключить debug в тестах**

В `phpunit.xml` в блоке `<php>` добавить `<env name="APP_DEBUG" value="false"/>` — иначе тело ошибки содержит `exception`, `file`, `trace` и не совпадает с задокументированным контрактом.

- [ ] **Step 8: Запустить тесты и убедиться, что они проходят**

```sh
docker compose exec -T app php artisan test --filter=BookingStoreTest
docker compose exec -T app php artisan test
```

Ожидание: PASS, оба набора зелёные (включая существующий `BookingIndexTest`).

- [ ] **Step 9: Отформатировать и закоммитить**

```sh
docker compose exec -T app vendor/bin/pint --dirty --format agent
git add app/Rules/Iso8601WithOffset.php app/Http/Requests/StoreBookingRequest.php app/Http/Controllers/BookingController.php bootstrap/app.php phpunit.xml tests/Feature/BookingStoreTest.php
git commit -m "Валидация входа брони и HTTP-контракт создания"
```

---

### Task 4: Отмена брони только автором

**Files:**
- Modify: `app/Http/Controllers/BookingController.php` (только `destroy`)
- Modify: `routes/api.php`
- Test: `tests/Feature/BookingDestroyTest.php`

**Interfaces:**
- Consumes: `BookingService::delete(Booking $booking, User $user): void` (Task 2).
- Produces: `BookingController::destroy(Booking $booking, BookingService $bookings): Response` — 204 / 403 / 404.

- [ ] **Step 1: Написать падающие тесты**

Создать `tests/Feature/BookingDestroyTest.php` (`use RefreshDatabase;`, `Sanctum::actingAs($user)`):

| Тест | Ожидание |
| --- | --- |
| `test_author_can_cancel_own_booking` | 204; `Booking::count() === 0` |
| `test_cannot_cancel_booking_of_another_user` | 403; `Booking::count() === 1` (запись на месте); `message` = «Отменить бронь может только её автор.» |
| `test_returns_not_found_for_unknown_booking` | DELETE несуществующего id → 404 |
| `test_requires_authentication` | без токена → 401 |

- [ ] **Step 2: Запустить и убедиться, что тесты падают**

```sh
docker compose exec -T app php artisan test --filter=BookingDestroyTest
```

Ожидание: `test_cannot_cancel_booking_of_another_user` падает (сейчас `destroy` удаляет чужую бронь).

- [ ] **Step 3: Переписать `destroy` и маршрут**

В `routes/api.php` заменить `Route::delete('/bookings/{id}', …)` на `Route::delete('/bookings/{booking}', …)` (форма URL не меняется; имя параметра нужно для привязки модели).

В контроллере:

```php
public function destroy(Booking $booking, Request $request, BookingService $bookings): Response
{
    $bookings->delete($booking, $request->user());

    return response()->noContent();
}
```

Импорты: `App\Models\Booking`, `Illuminate\Http\Request`, `Illuminate\Http\Response`.

- [ ] **Step 4: Запустить тесты и убедиться, что они проходят**

```sh
docker compose exec -T app php artisan test --filter=BookingDestroyTest
docker compose exec -T app php artisan test
```

Ожидание: PASS.

- [ ] **Step 5: Отформатировать и закоммитить**

```sh
docker compose exec -T app vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/BookingController.php routes/api.php tests/Feature/BookingDestroyTest.php
git commit -m "Отмена брони только автором: проверка прав и понятный 403"
```

---

### Task 5: Защита от гонки при одновременных запросах

**Files:**
- Modify: `app/Services/BookingService.php` (метод `create`)
- Test: `tests/Feature/BookingConcurrencyTest.php`

**Interfaces:**
- Consumes: `BookingService::create(User $user, array $data): Booking` (Task 2).
- Produces: `create()` выполняет проверку конфликта и вставку **внутри одной транзакции**, начатой с блокировки строки комнаты `SELECT … FOR UPDATE`.

- [ ] **Step 1: Написать падающий тест на блокировку**

Создать `tests/Feature/BookingConcurrencyTest.php` — **не** `RefreshDatabase`, а `DatabaseMigrations`
(данные должны быть закоммичены, чтобы их видела вторая сессия Postgres).

Тест `test_second_writer_waits_for_room_lock`:

1. Подготовить данные: пользователь, офис, комната (коммит — `DatabaseMigrations` без оборачивающей транзакции).
2. Создать второе соединение — копия конфигурации `pgsql` под другим именем:

   ```php
   config(['database.connections.pgsql_alt' => array_merge(config('database.connections.pgsql'), ['name' => 'pgsql_alt'])]);
   DB::purge('pgsql_alt');
   $alt = DB::connection('pgsql_alt');
   ```

3. Сессия A захватывает блокировку комнаты и **не коммитит**:

   ```php
   $alt->beginTransaction();
   $alt->table('rooms')->where('id', $room->id)->lockForUpdate()->first();
   ```

4. Сессия B (соединение по умолчанию, его использует сервис) получает малый таймаут блокировки и
   вызывает сервис:

   ```php
   DB::statement('SET lock_timeout = 250');

   try {
       $service->create($user, $data);
       $this->fail('Ожидалась блокировка по строке комнаты');
   } catch (QueryException $e) {
       $this->assertSame('55P03', $e->getCode()); // lock_not_available
   } finally {
       DB::statement('RESET lock_timeout');
   }

   $this->assertSame(0, Booking::count()); // вторая бронь не создана
   ```

   `55P03` — SQLSTATE `lock_not_available` в Postgres.

- [ ] **Step 2: Запустить и убедиться, что тест падает**

```sh
docker compose exec -T app php artisan test --filter=BookingConcurrencyTest
```

Ожидание: тест падает — без блокировки вставка проходит успешно и `Booking::count()` равен 1,
а ожидается исключение по таймауту блокировки.

- [ ] **Step 3: Реализовать блокировку в `BookingService::create`**

Обернуть тело `create()` в транзакцию, первой операцией взяв блокировку комнаты:

```php
return DB::transaction(function () use ($user, $data, $room, $startsAt, $endsAt) {
    Room::query()->whereKey($room->getKey())->lockForUpdate()->firstOrFail();

    // … все проверки правил, сбор $errors …

    if ($errors !== []) {
        throw ValidationException::withMessages($errors);
    }

    return Booking::create([...]);
});
```

`lockForUpdate()` → `SELECT … FROM rooms WHERE id = ? FOR UPDATE`. Второй одновременный запрос по той же
комнате встаёт на эту строку до коммита первого, после чего его проверка конфликта (в своей транзакции)
увидит чужую бронь. Комнаты не блокируют друг друга.

- [ ] **Step 4: Запустить тесты и убедиться, что они проходят**

```sh
docker compose exec -T app php artisan test --filter=BookingConcurrencyTest
docker compose exec -T app php artisan test
```

Ожидание: PASS, все наборы зелёные.

- [ ] **Step 5: Проверить сквозную гонку вручную на стенде**

```sh
docker compose exec -T app php artisan migrate:fresh --seed
for i in $(seq 1 10); do
  curl -s -o /dev/null -w "%{http_code}\n" -X POST http://localhost:8000/api/bookings \
    -H "Authorization: Bearer anna-token" -H "Accept: application/json" -H "Content-Type: application/json" \
    -d '{"room_id":1,"title":"Гонка","starts_at":"2026-11-20T10:00:00+03:00","ends_at":"2026-11-20T11:00:00+03:00"}' &
done; wait
docker compose exec -T postgres psql -U booking -d booking -c "SELECT count(*) FROM bookings WHERE room_id = 1;"
```

Ожидание: ровно один `201`, остальные `422`; в таблице `count(*) = 1`. Результат (фактические коды и количество) записать в `AI_NOTES.md` на шаге Task 7.

- [ ] **Step 6: Отформатировать и закоммитить**

```sh
docker compose exec -T app vendor/bin/pint --dirty --format agent
git add app/Services/BookingService.php tests/Feature/BookingConcurrencyTest.php
git commit -m "Защита от гонки: транзакция и блокировка комнаты при создании брони"
```

---

### Task 6: Время брони как настоящий момент

**Files:**
- Create: `database/migrations/2026_11_11_000000_change_bookings_time_columns_to_timestamptz.php`
- Modify: `config/database.php`
- Test: `tests/Feature/BookingStoreTest.php` (дополнение)

**Interfaces:**
- Consumes: `BookingController::store` (Task 3) — код уже пишет UTC.
- Produces: колонки `bookings.starts_at` и `bookings.ends_at` типа `timestamptz`; соединение `pgsql` с `'timezone' => 'UTC'`.

- [ ] **Step 1: Написать падающий тест**

Дополнить `tests/Feature/BookingStoreTest.php` двумя тестами.

`test_time_columns_store_timestamptz` — тип колонки в `information_schema.columns` для `starts_at`
и `ends_at` равен `timestamp with time zone`. До миграции там `timestamp without time zone` → тест падает.

`test_stored_value_carries_utc_offset` — создать бронь с `starts_at` = `2026-11-02T10:00:00+07:00`
(Новосибирск, 03:00Z) и проверить сырое значение:

```php
$raw = (string) DB::table('bookings')->where('id', $booking->id)->value('starts_at');
$this->assertStringStartsWith('2026-11-02 03:00:00', $raw);
$this->assertMatchesRegularExpression('/\+\d{2}$/', $raw);
```

До миграции колонка наивная → в ней лежит «стенное время в UTC» без смещения, второе утверждение
не выполняется → тест падает. После миграции Postgres отдаёт момент со смещением (`+00`) → проходит.

- [ ] **Step 2: Запустить и убедиться, что тесты падают**

```sh
docker compose exec -T app php artisan test --filter="test_time_columns_store_timestamptz|test_stored_value_carries_utc_offset"
```

- [ ] **Step 3: Создать миграцию**

```sh
docker compose exec -T app php artisan make:migration change_bookings_time_columns_to_timestamptz --no-interaction
```

В созданном файле:

```php
public function up(): void
{
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
```

- [ ] **Step 4: Зафиксировать часовой пояс сессии БД**

В `config/database.php` в массиве `'pgsql' => [...]` добавить `'timezone' => 'UTC',`. `PostgresConnector::configureTimezone()` выполнит `set time zone 'UTC'` при подключении — без этого наивная строка, которую Laravel отправляет в `timestamptz`, трактуется по TZ сервера.

- [ ] **Step 5: Применить миграцию и прогнать тесты**

```sh
docker compose exec -T app php artisan migrate
docker compose exec -T app php artisan test
```

Ожидание: PASS. Проверить тип колонки:

```sh
docker compose exec -T postgres psql -U booking -d booking -c "SELECT column_name, data_type FROM information_schema.columns WHERE table_name='bookings';"
```

Ожидание: `starts_at` и `ends_at` — `timestamp with time zone`.

- [ ] **Step 6: Отформатировать и закоммитить**

```sh
docker compose exec -T app vendor/bin/pint --dirty --format agent
git add database/migrations config/database.php tests/Feature/BookingStoreTest.php
git commit -m "Время брони: моменты вместо наивных таймстемпов"
```

---

### Task 7: Документы задания

**Files:**
- Create: `AI_NOTES.md`
- Modify: `README.md`

**Interfaces:**
- Consumes: факты из всех предыдущих задач (результаты стенда, реальные сообщения ошибок, коды из Task 5).
- Produces: `AI_NOTES.md`, обновлённый `README.md`.

- [ ] **Step 1: Написать `AI_NOTES.md`**

По трём пунктам задания, на русском:

1. **Инструменты и 2–3 ключевых промпта дословно.** Инструменты: ассистент в IDE (OpenCode/Claude Code), чтение исходников Laravel 13 в `vendor/`, `curl` против поднятого стенда, `psql` для проверки данных, `php artisan test`. Промпты привести дословно (те, что действительно использовались), без выдумки.
2. **Где ИИ ошибся или предложил неоптимальное решение — и как это заметили.** Обязательно включить: (а) «впритык» — стандартная трактовка «не пересекаются» как `starts_at <= … AND ends_at >= …` отвергает касающиеся интервалы, задание требует их разрешить — заметно по тесту 11:00–12:00 после 10:00–11:00; (б) `Log::withContext()` выглядит как «приклеить контекст к логу», но контекст уходит в канал по умолчанию, а логи пишет канал `bookings` — заметно по `storage/logs/bookings.log` без `booking_id`; (в) `Carbon::parse($request->starts_at)->hour` выглядит как проверка рабочего времени, но это час **в смещении запроса**, а не в часовом поясе офиса — заметно на запросе `07:00+03:00` для комнаты Новосибирска (11:00 местного), который отвергался как «нерабочее время»; (г) проверка «на глаз», что `timestamp` достаточно — не хватает: `timestamp` не хранит момент. Если что-то из этого проверялось и оказалось верным — так и написать, и указать, какой именно тест это закрепил.
3. **Что проверено вручную и что сознательно не отдано ИИ.** Вручную: эмпирические проверки на стенде (13 сценариев `curl`), содержимое таблицы `bookings` в БД, типы колонок, `SHOW timezone`, поведение `Authenticate::unauthenticated()` (чтение исходников Laravel 13), `DateTimeZone::getTransitions()` для обоих офисов (переходов на летнее время нет), параллельные `curl` на один слот (Task 5, Step 5). Не отдано ИИ: финальные формулировки сообщений об ошибках, решения по кодам статусов и по формату ответа, финальная вычитка `REVIEW.md`.

Приложить (необязательно) выгрузку истории диалога, если инструмент это умеет.

- [ ] **Step 2: Обновить `README.md`**

Сохранить существующие разделы («Задание» — по ссылке на исходное задание, «Запуск», «Тесты», «Данные сидера», «API»). Изменить/добавить:

- **Запуск и тесты одной-двумя командами** (требование задания):

  ```sh
  docker compose up -d --wait
  docker compose exec app php artisan migrate:fresh --seed
  ```

  и тесты:

  ```sh
  docker compose exec app php artisan test
  ```

- **Пример запроса** — без `user_id` в теле (автор = владелец токена), с пояснением, что присланный `user_id` игнорируется:

  ```sh
  curl -X POST http://localhost:8000/api/bookings \
    -H "Authorization: Bearer anna-token" \
    -H "Accept: application/json" \
    -H "Content-Type: application/json" \
    -d '{"room_id": 1, "title": "Планёрка", "starts_at": "2026-11-02T10:00:00+03:00", "ends_at": "2026-11-02T11:00:00+03:00"}'
  ```

- **Правила брони** — коротким списком: не пересекаются («впритык» разрешён), длительность 15 мин – 4 ч, шаг 15 мин, рабочее время 09:00–20:00 по часовому поясу офиса комнаты, время принимается в ISO 8601 **со смещением**, даты не ограничены.
- **Формат ошибок** — 422 `{"message": "…", "errors": {"поле": ["…"]}}`; 403 при отмене чужой брони; 401 без токена. Отдельно отметить, что формат ответа об ошибках изменился (было `409 {"error": "Комната занята"}`).
- **Конкурентность** — как защищено (транзакция + `SELECT … FOR UPDATE` по строке комнаты) и что осталось за рамками (альтернатива: ограничение `EXCLUDE USING gist (room_id WITH =, tstzrange(starts_at, ends_at, '[)') WITH &&)` + `btree_gist`; в этом проекте не внедрено).
- **Часовые пояса** — у офисов постоянные смещения (+3 и +7), перехода на летнее время нет; API принимает любое смещение и приводит время к UTC, рабочие часы считаются в зоне офиса.
- Ссылка на `REVIEW.md` и `AI_NOTES.md`.

- [ ] **Step 3: Проверить, что README соответствует коду**

Прогнать пример `curl` из README и сверить ответ с описанием; проверить, что команды запуска и тестов совпадают с фактическими.

- [ ] **Step 4: Финальная проверка и коммит**

```sh
docker compose exec -T app vendor/bin/pint --dirty --format agent
docker compose exec -T app php artisan test
git add AI_NOTES.md README.md
git commit -m "Документы задания: AI_NOTES и README"
```

---

## Self-Review

**Покрытие спецификации:** §2 (факты) → Task 1; §3 (цели/анти-цели) → сквозное; §5.1–5.2 (подходы, время) → Tasks 2–3, 6; §5.3 (гонка) → Task 5; §5.4 (формат ошибок) → Task 3; §5.5 (TZ сессии БД) → Task 6; §6.1–6.3 (контракт, правила, ключи `errors`) → Tasks 2–4; §7.1–7.8 (файлы, схема, правила, атомарность, скоуп, авторизация, логирование, 401) → Tasks 2–5; §8 (тесты) → Tasks 2–5; §9 (документы) → Tasks 1, 7; §10 (коммиты) → семь коммитов по числу задач.

**Пропуски:** §6.2 «404 для несуществующей брони» покрыт тестом в Task 4. Смена `log` в `booking` (логирование с контекстом) реализуется в Task 3 — отдельного теста нет: канал пишет в файл, а не в БД, поэтому проверяется чтением `storage/logs/bookings.log` при ручной проверке Task 5.

**Согласованность имён:** `BookingService::create(User $user, array $data): Booking` и `delete(Booking $booking, User $user): void` определены в Task 2 и используются в Tasks 3–5 без изменений; `scopeConflicting` — в Task 2; `Iso8601WithOffset` и `StoreBookingRequest` — в Task 3, используются только там; `startsAt()`/`endsAt()`/`room()` — Task 3.

**Пропорция:** план ~430 строк против ~430 строк спецификации — тела функций не приводятся, только сигнатуры, значения и ожидания тестов.
