<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Mouvement refusé : il ferait passer le stock physique ou disponible sous zéro. */
class StockInsuffisantException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly float $disponible,
        public readonly float $demandee,
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message'             => $this->getMessage(),
            'quantite_disponible' => $this->disponible,
            'quantite_demandee'   => $this->demandee,
        ], 422);
    }
}
