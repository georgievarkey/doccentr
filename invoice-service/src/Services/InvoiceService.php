<?php
// src/Services/InvoiceService.php
namespace App\Services;

use App\Models\Invoice;
use App\Exceptions\InvoiceException;
use PDOException;

class InvoiceService
{
    private $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    public function getAllInvoices(): array
    {
        try {
            $stmt = $this->db->query("SELECT * FROM invoices");
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new InvoiceException("Database error: " . $e->getMessage(), 500);
        }
    }

    public function getInvoiceById(int $id): ?array
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM invoices WHERE id = ?");
            $stmt->execute([$id]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $result ?: null;
        } catch (PDOException $e) {
            throw new InvoiceException("Database error: " . $e->getMessage(), 500);
        }
    }

    public function createInvoice(array $data): array
    {
        try {
            $this->db->beginTransaction();
            $stmt = $this->db->prepare("INSERT INTO invoices (client_id, amount, due_date) VALUES (?, ?, ?)");
            $stmt->execute([$data['client_id'], $data['amount'], $data['due_date']]);
            $id = $this->db->lastInsertId();
            $this->db->commit();
            return $this->getInvoiceById($id);
        } catch (PDOException $e) {
            $this->db->rollBack();
            throw new InvoiceException("Failed to create invoice: " . $e->getMessage(), 500);
        }
    }

    public function updateInvoice(int $id, array $data): ?array
    {
        try {
            $this->db->beginTransaction();
            $stmt = $this->db->prepare("UPDATE invoices SET client_id = ?, amount = ?, due_date = ? WHERE id = ?");
            $stmt->execute([$data['client_id'], $data['amount'], $data['due_date'], $id]);
            if ($stmt->rowCount() === 0) {
                $this->db->rollBack();
                return null;
            }
            $this->db->commit();
            return $this->getInvoiceById($id);
        } catch (PDOException $e) {
            $this->db->rollBack();
            throw new InvoiceException("Failed to update invoice: " . $e->getMessage(), 500);
        }
    }

    public function deleteInvoice(int $id): bool
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM invoices WHERE id = ?");
            $stmt->execute([$id]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            throw new InvoiceException("Failed to delete invoice: " . $e->getMessage(), 500);
        }
    }
}
