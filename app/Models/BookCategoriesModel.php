<?php
namespace App\Models;


class BookCategories{
      private ?int $id;
      private string $name;

      public function __construct(int $id , string $name){
        $this->id = $id;
        $this->name = $name;
      }
}