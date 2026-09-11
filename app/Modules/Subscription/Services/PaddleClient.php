<?php

declare(strict_types=1);

namespace App\Modules\Subscription\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PaddleClient
{
    public function configured(): bool
    {
        return filled(config('services.paddle.api_key'));
    }

    public function baseUrl(): string
    {
        return config('services.paddle.sandbox')
            ? 'https://sandbox-api.paddle.com'
            : 'https://api.paddle.com';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createCustomer(array $payload): array
    {
        return $this->post('/customers', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createTransaction(array $payload): array
    {
        return $this->post('/transactions', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Paddle no está configurado.');
        }

        try {
            $response = Http::baseUrl($this->baseUrl())
                ->withToken((string) config('services.paddle.api_key'))
                ->acceptJson()
                ->asJson()
                ->timeout(20)
                ->post($path, $payload)
                ->throw();
        } catch (RequestException $exception) {
            $detail = $exception->response?->json('error.detail')
                ?? $exception->response?->json('error.code')
                ?? 'Paddle rechazó la petición.';

            throw ValidationException::withMessages([
                'plan_id' => [is_string($detail) ? $detail : 'No se pudo crear el cobro en Paddle.'],
            ]);
        }

        $data = $response->json('data');

        if (! is_array($data)) {
            throw ValidationException::withMessages([
                'plan_id' => ['Paddle no devolvió un cobro válido.'],
            ]);
        }

        return $data;
    }
}
