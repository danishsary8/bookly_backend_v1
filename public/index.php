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
// Hosts like Wasmer inject config as real env vars with no .env file, and
// $_ENV stays empty unless variables_order includes "E". Mirror them in.
foreach (getenv() as $key => $value) {
    $_ENV[$key] ??= $value;
}
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

// Build controllers on first use, so routes like /health don't need a
// database connection and each request only constructs what it touches.
$lazy = static function (string $class, string $method): callable {
    return static function (...$args) use ($class, $method) {
        static $instances = [];
        $instances[$class] ??= new $class();
        return $instances[$class]->$method(...$args);
    };
};

$router->get('/', static function (): void {
    ApiResponse::success([
        'service' => 'book-ecommerce-server',
        'message' => 'Bookly API is running',
        'health' => '/health',
    ]);
});

$router->get('/health', static function (): void {
    ApiResponse::success([
        'data' => [
            'service' => 'book-ecommerce-server',
            'timestamp' => gmdate('c'),
        ],
    ]);
});

$router->get('/auth/me', $lazy(AuthController::class, 'me'));
$router->post('/auth/refresh', $lazy(AuthController::class, 'refresh'));
$router->post('/auth/logout', $lazy(AuthController::class, 'logout'));

$router->get('/books', $lazy(BookController::class, 'index'));
$router->get('/books/{id}', $lazy(BookController::class, 'show'));
$router->get('/books/{id}/reviews', $lazy(BookReviewController::class, 'index'));
$router->post('/books/{id}/reviews', $lazy(BookReviewController::class, 'store'));
$router->post('/books', $lazy(BookController::class, 'save'));
$router->put('/books/{id}', $lazy(BookController::class, 'update'));
$router->delete('/books/{id}', $lazy(BookController::class, 'delete'));
$router->get('/bookprice', $lazy(BookController::class, 'getBookPrice'));
$router->get('/books/countbook', $lazy(BookController::class, 'countBooks'));
$router->get('/books/new-arrivals', $lazy(BookController::class, 'newArrivals'));
$router->get('/books/best-sellers', $lazy(BookController::class, 'bestSellers'));
$router->get('/authors', $lazy(AuthorsController::class, 'index'));
$router->post('/authors', $lazy(AuthorsController::class, 'store'));
$router->put('/authors/{id}', $lazy(AuthorsController::class, 'update'));
$router->delete('/authors/{id}', $lazy(AuthorsController::class, 'delete'));

$router->get('/customers', $lazy(CustomerController::class, 'index'));
$router->get('/customers/{id}', $lazy(CustomerController::class, 'show'));
$router->post('/customers/register', $lazy(CustomerController::class, 'store'));
$router->post('/customers/login', $lazy(CustomerController::class, 'login'));
$router->put('/customers/{id}', $lazy(CustomerController::class, 'update'));
$router->delete('/customers/{id}', $lazy(CustomerController::class, 'delete'));
$router->post('/customers/forgot-password', $lazy(CustomerController::class, 'forgotPassword'));
$router->post('/customers/reset-password', $lazy(CustomerController::class, 'resetPassword'));
$router->get('/customers/count', $lazy(CustomerController::class, 'countCustomers'));

$router->get('/cart', $lazy(CartController::class, 'getCart'));
$router->post('/cart/items', $lazy(CartController::class, 'addItem'));
$router->put('/cart/items/{book_id}', $lazy(CartController::class, 'setItemQuantity'));
$router->delete('/cart/items/{book_id}', $lazy(CartController::class, 'removeItem'));

$router->post('/cart/checkout', $lazy(InvoiceController::class, 'checkout'));
$router->post('/cart/checkout-preview', $lazy(InvoiceController::class, 'preview'));
$router->get('/invoices', $lazy(InvoiceController::class, 'index'));
$router->get('/invoices/{invoiceId}', $lazy(InvoiceController::class, 'show'));
$router->post('/invoices/{invoiceId}/returns', $lazy(ReturnController::class, 'customerStore'));
$router->get('/admin/invoices', $lazy(InvoiceController::class, 'adminIndex'));
$router->put('/admin/invoices/{invoiceId}', $lazy(InvoiceController::class, 'adminUpdate'));
$router->get('/admin/returns', $lazy(ReturnController::class, 'adminIndex'));
$router->put('/admin/returns/{returnId}', $lazy(ReturnController::class, 'adminUpdate'));
$router->get('/admin/inventory-movements', $lazy(InventoryController::class, 'index'));

$router->post('/admin/register', $lazy(AdminController::class, 'store'));
$router->post('/admin/login', $lazy(AdminController::class, 'login'));
$router->get('/admin/analytics', $lazy(AdminController::class, 'analytics'));
$router->get('/admin/customers', $lazy(AdminController::class, 'customers'));
$router->get('/admin/settings', $lazy(AdminController::class, 'settings'));
$router->put('/admin/settings', $lazy(AdminController::class, 'updateSettings'));
$router->get('/admin/promotions', $lazy(PromotionController::class, 'index'));
$router->post('/admin/promotions', $lazy(PromotionController::class, 'store'));
$router->put('/admin/promotions/{id}', $lazy(PromotionController::class, 'update'));
$router->delete('/admin/promotions/{id}', $lazy(PromotionController::class, 'delete'));
$router->get('/storefront/settings', $lazy(AdminController::class, 'publicSettings'));
$router->get('/bookcategory', $lazy(BookCategoriesController::class, 'index'));
$router->post('/bookcategory', $lazy(BookCategoriesController::class, 'store'));
$router->put('/bookcategory/{id}', $lazy(BookCategoriesController::class, 'update'));
$router->delete('/bookcategory/{id}', $lazy(BookCategoriesController::class, 'delete'));

$router->dispatch();
