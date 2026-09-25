<?php
namespace App\Repositories;
use App\Config\DatabaseConnection;
use App\Models\AdminModel;
use PDO;
use RuntimeException;

class AdminRepository
{
    private PDO $db;
    public function __construct()
    {
        $this->db = DatabaseConnection::getInstance();
    }

    /*
     Get all Admin
    */

    /** Get all customers */
    public function all(): array
    {
        $stmt = $this->db->query("SELECT * FROM admin ORDER BY id DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM admin WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        return $data ?: null;
    }

    /*
    Register :
*/

    public function create(AdminModel $admin): bool
    {
        $sql = "INSERT INTO admin 
                (first_name, last_name, email, phone, role, address , password)
                VALUES (:first_name, :last_name, :email, :phone , :role, :address , :password)";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'first_name' => $admin->first_name,
            'last_name'  => $admin->last_name,
            'email'      => $admin->email,
            'phone'      => $admin->phone,
            'role'       => $admin->role,
            'address'    => $admin->address,
            'password'  => $admin->password
        ]);
      }


    /*
     Find BY Eamil : 
    */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM admin WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        return $data ?: null;
    }

    public function getDashboardAnalytics(): array
    {
        try {
            $overview = $this->db->query("
                SELECT
                    (SELECT COUNT(*) FROM books) AS total_books,
                    (SELECT COUNT(*) FROM customers) AS total_customers,
                    COUNT(*) AS total_orders,
                    COUNT(*) FILTER (WHERE status = 'delivered') AS completed_orders,
                    COUNT(*) FILTER (WHERE status = 'cancelled') AS cancelled_orders,
                    COUNT(*) FILTER (WHERE status IN ('pending', 'paid', 'processing', 'shipped')) AS open_orders,
                    COALESCE(SUM(total) FILTER (WHERE status IN ('paid', 'processing', 'shipped', 'delivered')), 0) AS revenue_total
                FROM invoices
            ")->fetch(PDO::FETCH_ASSOC) ?: [];

            $statusRows = $this->db->query("
                SELECT status, COUNT(*) AS total
                FROM invoices
                GROUP BY status
                ORDER BY status
            ")->fetchAll(PDO::FETCH_ASSOC);

            $topBooks = $this->db->query("
                SELECT
                    ii.book_id,
                    ii.title,
                    ii.author_name,
                    COALESCE(MAX(b.book_img), '') AS book_img,
                    SUM(ii.quantity) AS units_sold,
                    SUM(ii.line_total) AS revenue
                FROM invoice_items ii
                INNER JOIN invoices inv ON inv.id = ii.invoice_id
                LEFT JOIN books b ON b.id = ii.book_id
                WHERE inv.status <> 'cancelled'
                GROUP BY ii.book_id, ii.title, ii.author_name
                ORDER BY units_sold DESC, revenue DESC, ii.title ASC
                LIMIT 5
            ")->fetchAll(PDO::FETCH_ASSOC);

            $lowStockBooks = $this->db->query("
                SELECT
                    b.id,
                    b.title,
                    b.stock,
                    b.price,
                    c.name AS category_name,
                    a.name AS author_name
                FROM books b
                INNER JOIN categories c ON c.id = b.category_id
                INNER JOIN authors a ON a.id = b.author_id
                WHERE b.stock <= 5
                ORDER BY b.stock ASC, b.id DESC
                LIMIT 6
            ")->fetchAll(PDO::FETCH_ASSOC);

            $recentOrders = $this->db->query("
                SELECT
                    id,
                    customer_name,
                    status,
                    total,
                    created_at
                FROM invoices
                ORDER BY created_at DESC
                LIMIT 8
            ")->fetchAll(PDO::FETCH_ASSOC);

            $monthlyRows = $this->db->query("
                WITH months AS (
                    SELECT generate_series(
                        date_trunc('month', CURRENT_DATE) - interval '5 month',
                        date_trunc('month', CURRENT_DATE),
                        interval '1 month'
                    )::date AS month_start
                )
                SELECT
                    m.month_start,
                    COALESCE(COUNT(i.id) FILTER (WHERE i.status <> 'cancelled'), 0) AS order_count,
                    COALESCE(SUM(i.total) FILTER (WHERE i.status IN ('paid', 'processing', 'shipped', 'delivered')), 0) AS revenue
                FROM months m
                LEFT JOIN invoices i
                    ON date_trunc('month', i.created_at)::date = m.month_start
                GROUP BY m.month_start
                ORDER BY m.month_start ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            return [
                'summary' => [
                    'total_books' => (int)($overview['total_books'] ?? 0),
                    'total_customers' => (int)($overview['total_customers'] ?? 0),
                    'total_orders' => (int)($overview['total_orders'] ?? 0),
                    'completed_orders' => (int)($overview['completed_orders'] ?? 0),
                    'cancelled_orders' => (int)($overview['cancelled_orders'] ?? 0),
                    'open_orders' => (int)($overview['open_orders'] ?? 0),
                    'revenue_total' => (float)($overview['revenue_total'] ?? 0),
                ],
                'monthly_sales' => array_map(static fn(array $row): array => [
                    'month' => date('M', strtotime((string)$row['month_start'])),
                    'month_start' => (string)$row['month_start'],
                    'order_count' => (int)$row['order_count'],
                    'revenue' => (float)$row['revenue'],
                ], $monthlyRows),
                'status_breakdown' => array_map(static fn(array $row): array => [
                    'status' => (string)$row['status'],
                    'total' => (int)$row['total'],
                ], $statusRows),
                'top_books' => array_map(static fn(array $row): array => [
                    'book_id' => isset($row['book_id']) ? (int)$row['book_id'] : 0,
                    'title' => (string)$row['title'],
                    'author_name' => (string)$row['author_name'],
                    'book_img' => (string)($row['book_img'] ?? ''),
                    'units_sold' => (int)$row['units_sold'],
                    'revenue' => (float)$row['revenue'],
                ], $topBooks),
                'low_stock_books' => array_map(static fn(array $row): array => [
                    'id' => (int)$row['id'],
                    'title' => (string)$row['title'],
                    'stock' => (int)$row['stock'],
                    'price' => (float)$row['price'],
                    'category_name' => (string)$row['category_name'],
                    'author_name' => (string)$row['author_name'],
                ], $lowStockBooks),
                'recent_orders' => array_map(static fn(array $row): array => [
                    'id' => (string)$row['id'],
                    'customer_name' => (string)$row['customer_name'],
                    'status' => (string)$row['status'],
                    'total' => (float)$row['total'],
                    'created_at' => (string)$row['created_at'],
                ], $recentOrders),
            ];
        } catch (\Throwable $e) {
            throw new RuntimeException('Failed to load dashboard analytics: ' . $e->getMessage());
        }
    }

    public function getCustomerDirectory(): array
    {
        try {
            $rows = $this->db->query("
                SELECT
                    c.id,
                    c.first_name,
                    c.last_name,
                    c.email,
                    c.phone,
                    c.address,
                    c.created_at,
                    COUNT(i.id) FILTER (WHERE i.status <> 'cancelled') AS order_count,
                    COALESCE(SUM(i.total) FILTER (WHERE i.status IN ('paid', 'processing', 'shipped', 'delivered')), 0) AS lifetime_value,
                    MAX(i.created_at) AS last_order_at
                FROM customers c
                LEFT JOIN invoices i ON i.customer_id = c.id
                GROUP BY c.id, c.first_name, c.last_name, c.email, c.phone, c.address, c.created_at
                ORDER BY lifetime_value DESC, c.created_at DESC
            ")->fetchAll(PDO::FETCH_ASSOC);

            return array_map(static fn(array $row): array => [
                'id' => (int)$row['id'],
                'first_name' => (string)$row['first_name'],
                'last_name' => (string)$row['last_name'],
                'email' => (string)$row['email'],
                'phone' => $row['phone'] !== null ? (string)$row['phone'] : null,
                'address' => $row['address'] !== null ? (string)$row['address'] : null,
                'created_at' => (string)$row['created_at'],
                'order_count' => (int)$row['order_count'],
                'lifetime_value' => (float)$row['lifetime_value'],
                'last_order_at' => $row['last_order_at'] !== null ? (string)$row['last_order_at'] : null,
            ], $rows);
        } catch (\Throwable $e) {
            throw new RuntimeException('Failed to load customer directory: ' . $e->getMessage());
        }
    }

    public function getStoreSettings(): array
    {
        try {
            $stmt = $this->db->query("
                SELECT
                    id,
                    store_name,
                    support_email,
                    support_phone,
                    hero_heading,
                    hero_subheading,
                    free_shipping_threshold,
                    shipping_fee,
                    tax_rate,
                    low_stock_threshold,
                    updated_at
                FROM store_settings
                WHERE id = 1
            ");

            $settings = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$settings) {
                throw new RuntimeException('Store settings not found');
            }

            return [
                'id' => (int)$settings['id'],
                'store_name' => (string)$settings['store_name'],
                'support_email' => (string)$settings['support_email'],
                'support_phone' => $settings['support_phone'] !== null ? (string)$settings['support_phone'] : null,
                'hero_heading' => (string)$settings['hero_heading'],
                'hero_subheading' => (string)$settings['hero_subheading'],
                'free_shipping_threshold' => (float)$settings['free_shipping_threshold'],
                'shipping_fee' => (float)$settings['shipping_fee'],
                'tax_rate' => (float)$settings['tax_rate'],
                'low_stock_threshold' => (int)$settings['low_stock_threshold'],
                'updated_at' => (string)$settings['updated_at'],
            ];
        } catch (\Throwable $e) {
            throw new RuntimeException('Failed to load store settings: ' . $e->getMessage());
        }
    }

    public function updateStoreSettings(array $data): array
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE store_settings
                SET
                    store_name = :store_name,
                    support_email = :support_email,
                    support_phone = :support_phone,
                    hero_heading = :hero_heading,
                    hero_subheading = :hero_subheading,
                    free_shipping_threshold = :free_shipping_threshold,
                    shipping_fee = :shipping_fee,
                    tax_rate = :tax_rate,
                    low_stock_threshold = :low_stock_threshold
                WHERE id = 1
            ");

            $stmt->execute([
                'store_name' => $data['store_name'],
                'support_email' => $data['support_email'],
                'support_phone' => $data['support_phone'],
                'hero_heading' => $data['hero_heading'],
                'hero_subheading' => $data['hero_subheading'],
                'free_shipping_threshold' => $data['free_shipping_threshold'],
                'shipping_fee' => $data['shipping_fee'],
                'tax_rate' => $data['tax_rate'],
                'low_stock_threshold' => $data['low_stock_threshold'],
            ]);

            return $this->getStoreSettings();
        } catch (\Throwable $e) {
            throw new RuntimeException('Failed to update store settings: ' . $e->getMessage());
        }
    }
}
