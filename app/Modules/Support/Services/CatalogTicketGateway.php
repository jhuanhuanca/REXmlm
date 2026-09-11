<?php

declare(strict_types=1);

namespace App\Modules\Support\Services;

use App\Services\Catalog\CatalogClient;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class CatalogTicketGateway
{
    public function __construct(
        private readonly CatalogClient $catalog,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     */
    public function send(string $method, string $path, array $query = [], array $body = []): JsonResponse
    {
        try {
            $forwarded = $this->catalog->forward($method, $path, $query, $body);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 502);
        }

        $status = (int) $forwarded['status'];

        return response()->json($forwarded['body'], $status >= 100 && $status < 600 ? $status : 502);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function ticketPayload(JsonResponse $response): ?array
    {
        $payload = $response->getData(true);
        if (! is_array($payload)) {
            return null;
        }

        $row = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        return is_array($row) ? $row : null;
    }
}
