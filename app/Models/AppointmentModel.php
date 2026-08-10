<?php

declare(strict_types=1);

namespace BYD\Models;

/**
 * AppointmentModel - Data access layer لحجز مواعيد زيارة الفرع
 *
 * القواعد الثابتة:
 * - الفرع مسكر يوم الجمعة فقط (شغال من السبت للخميس).
 * - كل موعد مدته Default 30 دقيقة (قابلة للتعديل من admin_settings).
 * - ساعات الدوام ومدى الحجز المسموح (كم يوم قدام) قابلين للتحكم من
 *   صفحة الأدمن عبر جدول admin_settings (نفس الجدول المستخدم لاسم البوت).
 *
 * مكان هذا الملف: app/Models/AppointmentModel.php
 */
final class AppointmentModel
{
    private Database $db;

    private const DEFAULT_START_TIME  = '09:00';
    private const DEFAULT_END_TIME    = '17:00';
    private const DEFAULT_SLOT_MIN    = 30;
    private const DEFAULT_DAYS_AHEAD  = 14;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * الفرع مسكر يوم الجمعة فقط. date('w') بيرجع 0=Sunday ... 6=Saturday، فالجمعة=5.
     */
    public static function isWorkingDay(string $date): bool
    {
        return (int) date('w', strtotime($date)) !== 5;
    }

    /**
     * دوام الفرع + مدى الحجز المسموح، قابلين للتحكم من صفحة الأدمن.
     * القيم بتتخزن بجدول admin_settings (نفس آلية bot_name).
     */
    public function getWorkingHours(): array
    {
        $settings = \BYD\Controllers\AdminController::loadSettings();

        return [
            'start'        => $settings['appointment_start_time'] ?? self::DEFAULT_START_TIME,
            'end'          => $settings['appointment_end_time'] ?? self::DEFAULT_END_TIME,
            'slot_minutes' => (int) ($settings['appointment_slot_minutes'] ?? self::DEFAULT_SLOT_MIN),
            'days_ahead'   => (int) ($settings['appointment_booking_days_ahead'] ?? self::DEFAULT_DAYS_AHEAD),
        ];
    }

    private function timeToMinutes(string $time): int
    {
        [$h, $m] = explode(':', substr($time, 0, 5));
        return ((int) $h) * 60 + (int) $m;
    }

    private function minutesToTime(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * كل المواعيد المحجوزة (status = scheduled) بتاريخ معين.
     */
    public function getBookedTimesForDate(string $date): array
    {
        return $this->db->query(
            "SELECT appointment_time, duration_minutes
             FROM appointments
             WHERE appointment_date = ? AND status = 'scheduled'",
            [$date]
        );
    }

    /**
     * فحص تعارض زمني بسيط (interval overlap) بين السلوت الجديد وأي حجز موجود بنفس اليوم.
     */
public function isSlotFree(string $date, string $time, int $durationMinutes = 30, ?array $bookedTimes = null): bool
{
    $startNew = $this->timeToMinutes($time);
    $endNew   = $startNew + $durationMinutes;

    $booked = $bookedTimes ?? $this->getBookedTimesForDate($date);

    foreach ($booked as $b) {
        $s = $this->timeToMinutes((string) $b['appointment_time']);
        $e = $s + (int) $b['duration_minutes'];
        if ($startNew < $e && $endNew > $s) {
            return false;
        }
    }

    return true;
}

    /**
     * كل السلوتات الفاضية (نص ساعة نص ساعة) ضمن دوام الفرع بتاريخ معين.
     */
public function getFreeSlotsForDate(string $date): array
{
    $hours    = $this->getWorkingHours();
    $slot     = max(5, $hours['slot_minutes']);
    $startMin = $this->timeToMinutes($hours['start']);
    $endMin   = $this->timeToMinutes($hours['end']);

    // جلب الحجوزات مرة وحدة بس بدل ما نجيبها من جديد لكل سلوت (كانت 16 query، صارت query وحدة)
    $bookedTimes = $this->getBookedTimesForDate($date);

    $free = [];
    for ($m = $startMin; $m + $slot <= $endMin; $m += $slot) {
        $t = $this->minutesToTime($m);
        if ($this->isSlotFree($date, $t, $slot, $bookedTimes)) {
            $free[] = $t;
        }
    }

    return $free;
}

    /**
     * يدور على أقرب موعد متاح ابتداءً من تاريخ معين (أو اليوم لو التاريخ فات)،
     * متجاوزاً أيام الجمعة، وبحدود days_ahead يوم قدام (نفس السقف المسموح للحجز).
     *
     * لو انمرر preferredTime ونفس تاريخ البداية عنده سلوتات فاضية، بترجع أقرب
     * سلوت له بالوقت (مش أول سلوت باليوم) — عشان الاقتراح يكون منطقي للعميل.
     */
    public function findNearestAvailableSlot(string $fromDate, ?string $preferredTime = null): ?array
    {
        $hours   = $this->getWorkingHours();
        $today   = date('Y-m-d');
        $maxDate = date('Y-m-d', strtotime($today . " +{$hours['days_ahead']} days"));

        $cursor = max($fromDate, $today);
        if ($cursor > $maxDate) {
            return null;
        }

        $originalCursor = $cursor;
        $iterations     = 0;

        while ($cursor <= $maxDate && $iterations < 60) {
            $iterations++;

            if (self::isWorkingDay($cursor)) {
                $freeSlots = $this->getFreeSlotsForDate($cursor);

                // لو نفس اليوم الحالي، استبعدي أي سلوت فات وقته
                if ($cursor === $today) {
                    $nowMin    = ((int) date('H')) * 60 + ((int) date('i'));
                    $freeSlots = array_values(array_filter(
                        $freeSlots,
                        fn(string $t) => $this->timeToMinutes($t) > $nowMin
                    ));
                }

                if (!empty($freeSlots)) {
                    if ($preferredTime !== null && $cursor === $originalCursor) {
                        $pref = $this->timeToMinutes($preferredTime);
                        usort(
                            $freeSlots,
                            fn(string $a, string $b) =>
                                abs($this->timeToMinutes($a) - $pref) <=> abs($this->timeToMinutes($b) - $pref)
                        );
                    }

                    return ['date' => $cursor, 'time' => $freeSlots[0]];
                }
            }

            $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
        }

        return null;
    }

    /**
     * حجز فعلي — لا تفحص التوفر هون، الاستدعاء المسؤول (VapiWebhookController::bookAppointment)
     * لازم يكون تحقق من isSlotFree قبل ما يوصل لهون.
     */
    public function create(array $data): int
    {
        $this->db->execute(
            "INSERT INTO appointments
                (customer_name, phone_number, appointment_date, appointment_time, duration_minutes, status, source, session_id, notes)
             VALUES (?, ?, ?, ?, ?, 'scheduled', ?, ?, ?)",
            [
                $data['customer_name'],
                $data['phone_number'],
                $data['appointment_date'],
                $data['appointment_time'],
                $data['duration_minutes'] ?? self::DEFAULT_SLOT_MIN,
                $data['source'] ?? 'voice',
                $data['session_id'] ?? null,
                $data['notes'] ?? null,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * قائمة المواعيد لتبويب الأدمن، مع فلاتر اختيارية.
     */
    public function getAll(array $filters = []): array
    {
        $sql    = 'SELECT * FROM appointments WHERE 1=1';
        $params = [];

        if (!empty($filters['status'])) {
            $sql     .= ' AND status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['from'])) {
            $sql     .= ' AND appointment_date >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $sql     .= ' AND appointment_date <= ?';
            $params[] = $filters['to'];
        }

        $sql .= ' ORDER BY appointment_date ASC, appointment_time ASC';

        return $this->db->query($sql, $params);
    }

    public function autoMarkMissed(): int
{
    return $this->db->execute(
        "UPDATE appointments
         SET status = 'missed'
         WHERE status = 'scheduled'
           AND (
                appointment_date < CURDATE()
                OR (
                    appointment_date = CURDATE()
                    AND ADDTIME(appointment_time, SEC_TO_TIME(duration_minutes * 60)) < CURTIME()
                )
           )"
    );
}

    public function findById(int $id): array|false
    {
        return $this->db->queryOne('SELECT * FROM appointments WHERE id = ?', [$id]);
    }

    /**
     * البحث عن حجز مفعّل (scheduled) بناءً على رقم الجوال + الاسم.
     * بيرجع أقرب حجز مستقبلي مرتّب بالتاريخ/الوقت.
     */
    public function findScheduledByPhoneAndName(string $phone, string $name): array|false
    {
        $today = date('Y-m-d');
        $rows  = $this->db->query(
            "SELECT * FROM appointments
             WHERE phone_number = ?
               AND customer_name LIKE ?
               AND status = 'scheduled'
               AND appointment_date >= ?
             ORDER BY appointment_date ASC, appointment_time ASC
             LIMIT 1",
            [$phone, '%' . $name . '%', $today]
        );
        return $rows[0] ?? false;
    }

    /**
     * بحث أشمل: بالرقم فقط (لو الاسم مش متطابق تماماً).
     */
    public function findScheduledByPhone(string $phone): array
    {
        $today = date('Y-m-d');
        return $this->db->query(
            "SELECT * FROM appointments
             WHERE phone_number = ?
               AND status = 'scheduled'
               AND appointment_date >= ?
             ORDER BY appointment_date ASC, appointment_time ASC
             LIMIT 5",
            [$phone, $today]
        );
    }

    /**
     * إلغاء موعد محدد (تحديث status لـ cancelled).
     */
    public function cancelById(int $id): bool
    {
        return $this->db->execute(
            "UPDATE appointments SET status = 'cancelled' WHERE id = ? AND status = 'scheduled'",
            [$id]
        ) > 0;
    }

    /**
     * تعديل تاريخ/وقت موعد موجود (reschedule) — بيبقى مفعّل.
     */
    public function rescheduleById(int $id, string $newDate, string $newTime): bool
    {
        return $this->db->execute(
            "UPDATE appointments
             SET appointment_date = ?, appointment_time = ?, status = 'scheduled'
             WHERE id = ? AND status = 'scheduled'",
            [$newDate, $newTime, $id]
        ) > 0;
    }

    public function updateStatus(int $id, string $status): bool
    {
        $allowed = ['scheduled', 'cancelled', 'completed', 'missed'];
        if (!in_array($status, $allowed, true)) {
            return false;
        }

        return $this->db->execute('UPDATE appointments SET status = ? WHERE id = ?', [$status, $id]) > 0;
    }

    /**
     * تعديل تفاصيل موعد كامل من صفحة الأدمن.
     * يسمح بتعديل التاريخ والوقت والاسم والجوال والملاحظة.
     */
    public function updateDetails(int $id, array $data): bool
    {
        $fields = [];
        $params = [];

        if (isset($data['appointment_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['appointment_date'])) {
            $fields[]  = 'appointment_date = ?';
            $params[]  = $data['appointment_date'];
        }
        if (isset($data['appointment_time']) && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $data['appointment_time'])) {
            $fields[]  = 'appointment_time = ?';
            $params[]  = $data['appointment_time'];
        }
        if (isset($data['customer_name']) && trim($data['customer_name']) !== '') {
            $fields[]  = 'customer_name = ?';
            $params[]  = trim($data['customer_name']);
        }
        if (isset($data['phone_number']) && trim($data['phone_number']) !== '') {
            $fields[]  = 'phone_number = ?';
            $params[]  = trim($data['phone_number']);
        }
        if (array_key_exists('notes', $data)) {
            $fields[]  = 'notes = ?';
            $params[]  = $data['notes'] !== '' ? trim($data['notes']) : null;
        }
        if (isset($data['status'])) {
            $fields[]  = 'status = ?';
            $params[]  = $data['status'];
        }

        if (empty($fields)) {
            return false;
        }

        $params[] = $id;
        $sql = 'UPDATE appointments SET ' . implode(', ', $fields) . ' WHERE id = ?';

        return $this->db->execute($sql, $params) > 0;
    }
}