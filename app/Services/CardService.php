<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Repository\CardRepository;
use App\Infrastructure\Models\Card;

class CardService
{
    public CardRepository $cardRepository;

    public function __construct()
    {
        $this->cardRepository = resolve(CardRepository::class);
    }

    public function create(array $data): Card
    {
        return $this->cardRepository->save($data);
    }

    public function edit(array $data): Card
    {
        return $this->cardRepository->update($data);
    }
}
