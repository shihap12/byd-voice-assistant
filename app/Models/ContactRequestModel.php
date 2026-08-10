<?php

declare(strict_types=1);

namespace BYD\Models;

/**
 * ContactRequestModel - طلبات "تواصل معي مختص" (منفصلة عن حجز الزيارة)
 * مكان هذا الملف: app/Models/ContactRequestModel.php
 */
final class ContactRequestModel
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function create(array $data): int
    {
        $this->db->execute(
            "INSERT INTO specialist_contact_requests
                (customer_name, phone_number, car_id, channel, session_id, status)
             VALUES (?, ?, ?, ?, ?, 'pending')",
            [
                $data['customer_name'],
                $data['phone_number'],
                $data['car_id'] ?? null,
                $data['channel'] ?? 'voice',
                $data['session_id'] ?? null,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * قائمة الطلبات لتبويب الأدمن، مع اسم السيارة عبر JOIN.
     */
    public function getAll(array $filters = []): array
    {
        $sql = "SELECT r.*, c.model_name, c.model_name_ar
                FROM specialist_contact_requests r
                LEFT JOIN cars c ON c.id = r.car_id
                WHERE 1=1";
        $params = [];

        if (!empty($filters['status'])) {
            $sql     .= ' AND r.status = ?';
            $params[] = $filters['status'];
        }

        $sql .= ' ORDER BY r.created_at DESC';

        return $this->db->query($sql, $params);
    }

    public function updateStatus(int $id, string $status): bool
    {
        if (!in_array($status, ['pending', 'contacted'], true)) {
            return false;
        }

        return $this->db->execute(
            'UPDATE specialist_contact_requests SET status = ? WHERE id = ?',
            [$status, $id]
        ) > 0;
    }
}