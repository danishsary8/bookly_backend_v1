<?php
namespace App\Models;

class BookModel{
    private int $id;
    private string $title;
    private string $description;
    private float $price;
    private int $stock;
    private int $author_id;
    private int $category_id;
    private string $book_image;
    private string $published_date;
    

    // Constructor
    public function __construct(
        string $title = '',
        int $author_id = 0,
        int $category_id = 0,
        float $price = 0.0,
        int $stock = 0,
        string $description = '',
        string $published_date = '',
        string $book_image = '',
    ) {
        $this->title = $title;
        $this->description = $description;
        $this->price = $price;
        $this->stock = $stock;
        $this->author_id = $author_id;
        $this->category_id = $category_id;
        $this->book_image = $book_image;
        $this->published_date = $published_date;
    }

    // Getters
    public function getId(): int { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function getDescription(): string { return $this->description; }
    public function getPrice(): float { return $this->price; }
    public function getStock(): int { return $this->stock; }
    public function getAuthorId(): int { return $this->author_id; }
    public function getCategoryId(): int { return $this->category_id; }
    public function getBookImage(): string { return $this->book_image; }
    public function getPublishedDate(): string { return $this->published_date; }

    // Setters
    public function setId(int $id): void { $this->id = $id; }
    public function setTitle(string $title): void { $this->title = $title; }
    public function setDescription(string $description): void { $this->description = $description; }
    public function setPrice(float $price): void { $this->price = $price; }
    public function setStock(int $stock): void { $this->stock = $stock; }
    public function setAuthorId(int $author_id): void { $this->author_id = $author_id; }
    public function setCategoryId(int $category_id): void { $this->category_id = $category_id; }
    public function setBookImage(string $book_image): void { $this->book_image = $book_image; }
    public function setPublishedDate(string $published_date): void { $this->published_date = $published_date; }

}