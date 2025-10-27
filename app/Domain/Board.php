<?php
declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;


final class Board
{
    private ?int $id;
    private string $name;
    private ?string $description;
    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $updatedAt;

    public function __construct(?int $id, string $name, ?string $description = null, ?DateTimeImmutable $createdAt = null)
    {
        $this->id = $id;
        $this->name = $name;
        $this->description = $description;
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
    }

    public static function create(string $name, ?string $description = null): self
    {
        return new self(null, $name, $description);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'created_at' => $this->createdAt->format(DATE_ATOM),
        ];
    }

    public static function fromArray(array $data): self
    {
        $createdAt = isset($data['created_at']) ? new DateTimeImmutable($data['created_at']) : null;
        return new self(
            $data['id'] ?? null,
            $data['name'] ?? '',
            $data['description'] ?? null,
            $createdAt
        );
    }
}