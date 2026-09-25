<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Controllers\AdminController;
use App\Controllers\AuthorsController;
use App\Controllers\AuthController;
use App\Controllers\BookCategoriesController;
use App\Controllers\BookController;
use App\Controllers\BookReviewController;
use App\Controllers\CartController;
use App\Controllers\CustomerController;
use App\Controllers\InventoryController;
use App\Controllers\InvoiceController;
use App\Controllers\PromotionController;
use App\Controllers\ReturnController;
use App\Helpers\ApiResponse;
use App\Helpers\ErrorHandler;
use App\Routes\Router;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();
ErrorHandler::register();

header('Content-Type: application/json');
// Catalogue data contains mutable image URLs and must not be served from a
// stale browser/proxy cache after an admin updates book_img.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$origin = $_ENV['FRONTEND_ORIGIN'] ?? '';
if (!empty($origin)) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Access-Control-Allow-Credentials: true');
} else {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Credentials: false');
}

header('Vary: Origin');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (empty($_ENV['JWT_SECRET'])) {
    ApiResponse::error('Server configuration error: JWT_SECRET is missing', 500);
    exit;
}

$router = new Router();

$router->get('/', static function (): void {
    ApiResponse::success([
        'service' => 'book-ecommerce-server',
        'message' => 'Bookly API is running',
        'health' => '/health',
    ]);
});

$bookController = new BookController();
$bookReviewController = new BookReviewController();
$customerController = new CustomerController();
$adminController = new AdminController();
$authorsController = new AuthorsController();
$bookCategoriesController = new BookCategoriesController();
$cartController = new CartController();
$invoiceController = new InvoiceController();
$authController = new AuthController();
$inventoryController = new InventoryController();
$promotionController = new PromotionController();
$returnController = new ReturnController();

$router->get('/health', static function (): void {
    ApiResponse::success([
        'data' => [
            'service' => 'book-ecommerce-server',
            'timestamp' => gmdate('c'),
        ],
    ]);
});

$router->get('/auth/me', [$authController, 'me']);
$router->post('/auth/refresh', [$authController, 'refresh']);
$router->post('/auth/logout', [$authController, 'logout']);

$router->get('/books', [$bookController, 'index']);
$router->get('/books/{id}', [$bookController, 'show']);
$router->get('/books/{id}/reviews', [$bookReviewController, 'index']);
$router->post('/books/{id}/reviews', [$bookReviewController, 'store']);
$router->post('/books', [$bookController, 'save']);
$router->put('/books/{id}', [$bookController, 'update']);
$router->delete('/books/{id}', [$bookController, 'delete']);
$router->get('/bookprice', [$bookController, 'getBookPrice']);
$router->get('/books/countbook', [$bookController, 'countBooks']);
$router->get('/books/new-arrivals', [$bookController, 'newArrivals']);
$router->get('/books/best-sellers', [$bookController, 'bestSellers']);
$router->get('/authors', [$authorsController, 'index']);
$router->post('/authors', [$authorsController, 'store']);
$router->put('/authors/{id}', [$authorsController, 'update']);
$router->delete('/authors/{id}', [$authorsController, 'delete']);

$router->get('/customers', [$customerController, 'index']);
$router->get('/customers/{id}', [$customerController, 'show']);
$router->post('/customers/register', [$customerController, 'store']);
$router->post('/customers/login', [$customerController, 'login']);
$router->put('/customers/{id}', [$customerController, 'update']);
$router->delete('/customers/{id}', [$customerController, 'delete']);
$router->post('/customers/forgot-password', [$customerController, 'forgotPassword']);
$router->post('/customers/reset-password', [$customerController, 'resetPassword']);
$router->get('/customers/count', [$customerController, 'countCustomers']);

$router->get('/cart', [$cartController, 'getCart']);
$router->post('/cart/items', [$cartController, 'addItem']);
$router->put('/cart/items/{book_id}', [$cartController, 'setItemQuantity']);
$router->delete('/cart/items/{book_id}', [$cartController, 'removeItem']);

$router->post('/cart/checkout', [$invoiceController, 'checkout']);
$router->post('/cart/checkout-preview', [$invoiceController, 'preview']);
$router->get('/invoices', [$invoiceController, 'index']);
$router->get('/invoices/{invoiceId}', [$invoiceController, 'show']);
$router->post('/invoices/{invoiceId}/returns', [$returnController, 'customerStore']);
$router->get('/admin/invoices', [$invoiceController, 'adminIndex']);
$router->put('/admin/invoices/{invoiceId}', [$invoiceController, 'adminUpdate']);
$router->get('/admin/returns', [$returnController, 'adminIndex']);
$router->put('/admin/returns/{returnId}', [$returnController, 'adminUpdate']);
$router->get('/admin/inventory-movements', [$inventoryController, 'index']);

$router->post('/admin/register', [$adminController, 'store']);
$router->post('/admin/login', [$adminController, 'login']);
$router->get('/admin/analytics', [$adminController, 'analytics']);
$router->get('/admin/customers', [$adminController, 'customers']);
$router->get('/admin/settings', [$adminController, 'settings']);
$router->put('/admin/settings', [$adminController, 'updateSettings']);
$router->get('/admin/promotions', [$promotionController, 'index']);
$router->post('/admin/promotions', [$promotionController, 'store']);
$router->put('/admin/promotions/{id}', [$promotionController, 'update']);
$router->delete('/admin/promotions/{id}', [$promotionController, 'delete']);
$router->get('/storefront/settings', [$adminController, 'publicSettings']);
$router->get('/bookcategory', [$bookCategoriesController, 'index']);
$router->post('/bookcategory', [$bookCategoriesController, 'store']);
$router->put('/bookcategory/{id}', [$bookCategoriesController, 'update']);
$router->delete('/bookcategory/{id}', [$bookCategoriesController, 'delete']);

$router->dispatch();
