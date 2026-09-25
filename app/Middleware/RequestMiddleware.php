<?php
namespace App\Middlewares;

class RequestMiddleware
{
    private string $expectedMethod;

    public function __construct(string $expectedMethod)
    {
        $this->expectedMethod = strtoupper($expectedMethod);
    }

    /**
     * Check HTTP method
     */
    /**
 * Check HTTP method
 */
public function checkMethod(): bool
{
    $currentMethod = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';

    if ($currentMethod !== $this->expectedMethod) {
        http_response_code(405); // Method Not Allowed
        echo json_encode([
            'status'  => 'error',
            'message' => "Expected {$this->expectedMethod} request, got {$currentMethod}"
        ]);
        return false;
    }
    return true;
}

    /**
     * Validate request data (for POST/PUT)
     */
    public function validate(array $data , array $requiredFields ) :array{
          $error =[];
          foreach ($requiredFields as $field){
             if(!isset($data[$field]) || empty($data[$field])){
                  $error = "$field is required";
             }
          } 
          return $error;
    }
    /**
     * Run middleware: validate
     */
    public function handle(array $data, array $requiredFields): bool
    {
         // 1️⃣ Check HTTP method first
    if (!$this->checkMethod()) {
        // checkMethod() already sends 405 + JSON, so just stop
        return false;
    }
        $errors = $this->validate($data, $requiredFields);
        if (!empty($errors)) {
            http_response_code(400);
            echo json_encode(['errors' => $errors]);
            return false;
        }
        
        return true;
         
}
}