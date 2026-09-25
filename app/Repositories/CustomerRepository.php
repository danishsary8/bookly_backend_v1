<?php

namespace App\Repositories;

use App\Config\DatabaseConnection;
use PDO;
use PDOException;
use App\Models\CustomerModel;
use RuntimeException;

class CustomerRepository{
    private PDO $db;
    public function __construct(){
        $this->db = DatabaseConnection::getInstance();
    }

    /** Get all customers */
    public function all(): array
    {
        $stmt = $this->db->query("SELECT * FROM customers ORDER BY id DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Find customer by ID */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM customers WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        return $data ?: null;
    }

    /** Create customer */
    public function create(CustomerModel $customer): bool
    {
        $sql = "INSERT INTO customers 
                (first_name, last_name, email, phone, address , password)
                VALUES (:first_name, :last_name, :email, :phone, :address , :password)";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'first_name' => $customer->first_name,
            'last_name'  => $customer->last_name,
            'email'      => $customer->email,
            'phone'      => $customer->phone,
            'address'    => $customer->address,
            'password'   => $customer->password
        ]);
    }

    /** Update customer */
    public function update(int $id, CustomerModel $customer): bool
    {
        $sql = "UPDATE customers SET
                first_name = :first_name,
                last_name  = :last_name,
                email      = :email,
                phone      = :phone,
                address    = :address,
                password   = :password
                WHERE id = :id";

        $stmt = $this->db->prepare($sql);

        return $stmt->execute([
            'id'         => $id,
            'first_name' => $customer->first_name,
            'last_name'  => $customer->last_name,
            'email'      => $customer->email,
            'phone'      => $customer->phone,
            'address'    => $customer->address,
            'password'   => $customer->password
        ]);
    }

    /*
        Find by Email :
    */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM customers WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        return $data ?: null;
    }

    /** Delete customer */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM customers WHERE id = :id");
        return $stmt->execute(['id' => $id]);
    }
    
    /** Save reset token */
    public function saveResetToken(string $email, string $token, string $expiry): bool
    {
        try {
            $customer = $this->findByEmail($email);
            if (!$customer) {
                return false;
            }

            $this->db->prepare("
                UPDATE password_reset_tokens
                SET used_at = NOW()
                WHERE customer_id = :customer_id
                  AND used_at IS NULL
            ")->execute([
                'customer_id' => $customer['id'],
            ]);

            $sql = "INSERT INTO password_reset_tokens (customer_id, token, expires_at)
                    VALUES (:customer_id, :token, :expires_at)";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                'customer_id' => $customer['id'],
                'token'  => $token,
                'expires_at' => $expiry
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to save reset token: ' . $e->getMessage());
        }
    } 

    /** Find customer by reset token */
    public function findByResetToken(string $token): ?array
    {
        try {
            $sql = "SELECT c.*
                    FROM customers c
                    INNER JOIN password_reset_tokens prt ON prt.customer_id = c.id
                    WHERE prt.token = :token
                      AND prt.expires_at > NOW()
                      AND prt.used_at IS NULL
                    ORDER BY prt.id DESC
                    LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['token' => $token]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            return $data ?: null;
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to validate reset token: ' . $e->getMessage());
        }
    }

    /** Update password */
    public function updatePassword(int $id, string $hashedPassword): bool
    {
        try {
            $sql = "UPDATE customers SET password = :password WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                'password' => $hashedPassword,
                'id'       => $id
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to update password: ' . $e->getMessage());
        }
    }

    /** Clear reset token */
    public function clearResetToken(int $id): bool
    {
        try {
            $sql = "UPDATE password_reset_tokens
                    SET used_at = NOW()
                    WHERE customer_id = :id
                      AND used_at IS NULL";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute(['id' => $id]);
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to clear reset token: ' . $e->getMessage());
        }
    }

    // Total Customers
    public function countCustomers(): int{
        $stmt = $this->db->query("SELECT COUNT(*) as totalCustomer FROM customers");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)$result['totalCustomer'];   
    }

}
