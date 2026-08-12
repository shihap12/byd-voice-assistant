<?php

declare(strict_types=1);

namespace BYD\Models;

/**
 * VisitModel - Data access layer لحجز زيارات الفرع (غير مواعيد الصيانة).
 *
 * نفس منطق AppointmentModel بالكامل (دوام، سلوتات، فحص تعارض)، بس بيقرا
 * ويكتب من جدول visits المنفصل. بيشارك نفس getWorkingHours() (نفس
 * إعدادات appointment_start_time/end_time/slot_minutes/days_ahead)
 * لأن الفرع مالوش دوام مختلف للزيارات عن الصيانة.
 *
 * مكان هذا الملف: app/Models/VisitModel.php
 */
final class VisitModel
{
    private Database $db;

    private const DEFAULT_SLOT_MIN = 30;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public static function isWorkingDay(string $date): bool
    {
        return AppointmentModel::isWorkingDay($date);
    }

    public function getWorkingHours(): array
    {
        // نفس إعدادات دوام مواعيد الصيانة بالضبط (قرار المشروع: لا إعدادات منفصلة للزيارات)
        return (new AppointmentModel())->getWorkingHours();
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

    public function getBookedTimesForDate(string $date): array
    {
        return $this->db->query(
            "SELECT visit_time AS appointment_time, duration_minutes
             FROM visits
             WHERE visit_date = ? AND status = 'scheduled'",
            [$date]
        );
    }

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

    public function getFreeSlotsForDate(string $date): array
    {
        $hours    = $this->getWorkingHours();
        $slot     = max(5, $hours['slot_minutes']);
        $startMin = $this->timeToMinutes($hours['start']);
        $endMin   = $this->timeToMinutes($hours['end']);

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

    public function create(array $data): int
    {
        $this->db->execute(
            "INSERT INTO visits
                (customer_name, phone_number, car_id, visit_date, visit_time, duration_minutes, status, source, session_id, notes)
             VALUES (?, ?, ?, ?, ?, ?, 'scheduled', ?, ?, ?)",
            [
                $data['customer_name'],
                $data['phone_number'],
                $data['car_id'] ?? null,
                $data['visit_date'],
                $data['visit_time'],
                $data['duration_minutes'] ?? self::DEFAULT_SLOT_MIN,
                $data['source'] ?? 'voice',
                $data['session_id'] ?? null,
                $data['notes'] ?? null,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    public function getAll(array $filters = []): array
    {
        $sql    = 'SELECT v.*, c.model_name, c.model_name_ar
                    FROM visits v
                    LEFT JOIN cars c ON c.id = v.car_id
                    WHERE 1=1';
        $params = [];

        if (!empty($filters['status'])) {
            $sql     .= ' AND v.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['from'])) {
            $sql     .= ' AND v.visit_date >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $sql     .= ' AND v.visit_date <= ?';
            $params[] = $filters['to'];
        }

        $sql .= ' ORDER BY v.visit_date ASC, v.visit_time ASC';

        return $this->db->query($sql, $params);
    }

    public function autoMarkMissed(): int
    {
        return $this->db->execute(
            "UPDATE visits
             SET status = 'missed'
             WHERE status = 'scheduled'
               AND (
                    visit_date < CURDATE()
                    OR (
                        visit_date = CURDATE()
                        AND ADDTIME(visit_time, SEC_TO_TIME(duration_minutes * 60)) < CURTIME()
                    )
               )"
        );
    }

    public function findById(int $id): array|false
    {
        return $this->db->queryOne('SELECT * FROM visits WHERE id = ?', [$id]);
    }

    public function findScheduledByPhoneAndName(string $phone, string $name): array|false
    {
        $today = date('Y-m-d');
        $rows  = $this->db->query(
            "SELECT * FROM visits
             WHERE phone_number = ?
               AND customer_name LIKE ?
               AND status = 'scheduled'
               AND visit_date >= ?
             ORDER BY visit_date ASC, visit_time ASC
             LIMIT 1",
            [$phone, '%' . $name . '%', $today]
        );
        return $rows[0] ?? false;
    }

    public function findScheduledByPhone(string $phone): array
    {
        $today = date('Y-m-d');
        return $this->db->query(
            "SELECT * FROM visits
             WHERE phone_number = ?
               AND status = 'scheduled'
               AND visit_date >= ?
             ORDER BY visit_date ASC, visit_time ASC
             LIMIT 5",
            [$phone, $today]
        );
    }

    public function cancelById(int $id): bool
    {
        return $this->db->execute(
            "UPDATE visits SET status = 'cancelled' WHERE id = ? AND status = 'scheduled'",
            [$id]
        ) > 0;
    }

    public function rescheduleById(int $id, string $newDate, string $newTime): bool
    {
        return $this->db->execute(
            "UPDATE visits
             SET visit_date = ?, visit_time = ?, status = 'scheduled'
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

        return $this->db->execute('UPDATE visits SET status = ? WHERE id = ?', [$status, $id]) > 0;
    }

    public function updateDetails(int $id, array $data): bool
    {
        $fields = [];
        $params = [];

        if (isset($data['visit_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['visit_date'])) {
            $fields[] = 'visit_date = ?';
            $params[] = $data['visit_date'];
        }
        if (isset($data['visit_time']) && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $data['visit_time'])) {
            $fields[] = 'visit_time = ?';
            $params[] = $data['visit_time'];
        }
        if (isset($data['customer_name']) && trim($data['customer_name']) !== '') {
            $fields[] = 'customer_name = ?';
            $params[] = trim($data['customer_name']);
        }
        if (isset($data['phone_number']) && trim($data['phone_number']) !== '') {
            $fields[] = 'phone_number = ?';
            $params[] = trim($data['phone_number']);
        }
        if (array_key_exists('notes', $data)) {
            $fields[] = 'notes = ?';
            $params[] = $data['notes'] !== '' ? trim($data['notes']) : null;
        }
        if (isset($data['status'])) {
            $fields[] = 'status = ?';
            $params[] = $data['status'];
        }

        if (empty($fields)) {
            return false;
        }

        $params[] = $id;
        $sql = 'UPDATE visits SET ' . implode(', ', $fields) . ' WHERE id = ?';

        return $this->db->execute($sql, $params) > 0;
    }
}