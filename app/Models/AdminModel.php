<?php
namespace App\Models;

class AdminModel
{
    public ?int $id;
    public string $first_name;
    public string $last_name;
    public string $email;
    public string $phone;
    public string $role;
    public string $address;
    public string $password;
    public string $created_at;

    public function __construct(array $data = [])
    {
        $this->first_name = $data['first_name'] ?? '';
        $this->last_name  = $data['last_name'] ?? '';
        $this->email      = $data['email'] ?? '';
        $this->phone      = $data['phone'] ?? '';
        $this->role       = $data['role'] ?? 'admin';
        $this->address    = $data['address'] ?? '';
        $this->password   = $data['password'] ?? '';
        $this->created_at = $data['created_at'] ?? '';
    }
}