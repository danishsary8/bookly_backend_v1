<?php
// app/Models/Customer.php
namespace App\Models;

class CustomerModel
{
    public ?int $id;
    public string $first_name;
    public string $last_name;
    public string $email;
    public ?string $phone;
    public ?string $address;
    public string $password;
    public ?string $created_at;

   public function __construct(array $data = [])
    {
        $this->first_name = $data['first_name'] ?? '';
        $this->last_name  = $data['last_name'] ?? '';
        $this->email      = $data['email'] ?? '';
        $this->phone      = $data['phone'] ?? null;
        $this->address    = $data['address'] ?? null;
        $this->password   = $data['password'] ?? null;
        $this->created_at = $data['created_at'] ?? null;
    }
}