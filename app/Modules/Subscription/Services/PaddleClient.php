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

    public function get(string $path, array $query = []): array
    {
        return $this->send('get', $path, [], $query);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function patch(string $path, array $payload): array
    {
        return $this->send('patch', $path, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload): array
    {
        return $this->send('post', $path, $payload);
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
     * @param  list<array<string, mixed>>  $rows
     */
    public function list(string $path, array $query = []): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Paddle no está configurado.');
        }

        $response = Http::baseUrl($this->baseUrl())
            ->withToken((string) config('services.paddle.api_key'))
            ->acceptJson()
            ->timeout(20)
            ->get($path, $query)
            ->throw();

        $data = $response->json('data');

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $payload = [], array $query = []): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Paddle no está configurado.');
        }

        try {
            $pending = Http::baseUrl($this->baseUrl())
                ->withToken((string) config('services.paddle.api_key'))
                ->acceptJson()
                ->asJson()
                ->timeout(20);

            $response = match ($method) {
                'get' => $pending->get($path, $query),
                'patch' => $pending->patch($path, $payload),
                default => $pending->post($path, $payload),
            };

            $response->throw();
        } catch (RequestException $exception) {
            $detail = $exception->response?->json('error.detail')
                ?? $exception->response?->json('error.code')
                ?? 'Paddle rechazó la petición.';

            throw ValidationException::withMessages([
                'plan_id' => [is_string($detail) ? $detail : 'No se pudo completar el cobro en Paddle.'],
            ]);
        }

        $data = $response->json('data');

        if (! is_array($data)) {
            throw ValidationException::withMessages([
                'plan_id' => ['Paddle no devolvió una respuesta válida.'],
            ]);
        }

        return $data;
    }
}
