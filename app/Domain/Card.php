<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

final class Card
{
    private ?int $id;
    private int $boardId;
    private int $sectionId;
    private string $name;
    private string $description;
    private DateTimeImmutable $createdAt;

    public function __construct(?int $id, int $boardId, int $sectionId, string $name, string $description, ?DateTimeImmutable $createdAt = null)
    {
        $this->id = $id;
        $this->boardId = $boardId;
        $this->sectionId = $sectionId;
        $this->name = $name;
        $this->description = $description;
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
    }

    public static function create(int $boardId, int $sectionId, string $name, string $description): self
    {
        return new self(null, $boardId, $sectionId, $name, $description);
    }

    public function getId(): ?int { return $this->id; }
    public function getBoardId(): int { return $this->boardId; }
    public function getSectionId(): int { return $this->sectionId; }
    public function getName(): string { return $this->name; }
    public function getDescription(): string { return $this->description; }

    public function setName(string $name): void { $this->name = $name; }
    public function setDescription(string $description): void { $this->description = $description; }
    public function setSectionId(int $sectionId): void { $this->sectionId = $sectionId; }

    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'board_id'    => $this->boardId,
            'section_id'  => $this->sectionId,
            'name'        => $this->name,
            'description' => $this->description,
            'created_at'  => $this->createdAt->format(DATE_ATOM),
        ];
    }

    public static function fromArray(array $data): self
    {
        $createdAt = isset($data['created_at']) ? new DateTimeImmutable($data['created_at']) : null;
        return new self(
            $data['id'] ?? null,
            (int) $data['board_id'],
            (int) $data['section_id'],
            $data['name'] ?? '',
            $data['description'] ?? '',
            $createdAt
        );
    }
}