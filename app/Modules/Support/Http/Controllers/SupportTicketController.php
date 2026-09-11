<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Support\Http\Requests\StorePlatformTicketRequest;
use App\Modules\Support\Http\Requests\StorePublicContactRequest;
use App\Modules\Support\Http\Requests\StoreTicketReplyRequest;
use App\Modules\Support\Http\Requests\UpdateTicketStatusRequest;
use App\Modules\Support\Services\CatalogTicketGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SupportTicketController extends Controller
{
    public function __construct(
        private readonly CatalogTicketGateway $tickets,
    ) {}

    public function contact(StorePublicContactRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->tickets->send('POST', '/support-tickets', [], [
            'source' => 'landing',
            'name' => $data['name'],
            'email' => $data['email'],
            'subject' => filled($data['subject'] ?? null) ? $data['subject'] : 'Consulta desde la web',
            'message' => $data['message'],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->tickets->send('GET', '/support-tickets', [
            'user_id' => $user->id,
            'page' => $request->integer('page', 1),
            'per_page' => $request->integer('per_page', 20),
        ]);
    }

    public function store(StorePlatformTicketRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        return $this->tickets->send('POST', '/support-tickets', [], [
            'source' => 'platform',
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'subject' => $data['subject'],
            'message' => $data['message'],
            'user_id' => $user->id,
            'catalog_company_id' => $user->workingCatalogCompanyId(),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $response = $this->tickets->send('GET', "/support-tickets/{$id}");
        $this->assertOwner($request, $response);

        return $response;
    }

    public function reply(StoreTicketReplyRequest $request, int $id): JsonResponse
    {
        $existing = $this->tickets->send('GET', "/support-tickets/{$id}");
        $this->assertOwner($request, $existing);

        return $this->tickets->send('POST', "/support-tickets/{$id}/replies", [], [
            'author_role' => 'user',
            'author_name' => (string) $request->user()->name,
            'message' => $request->validated('message'),
        ]);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $query = [
            'page' => $request->integer('page', 1),
            'per_page' => $request->integer('per_page', 20),
        ];

        if ($request->filled('status')) {
            $query['status'] = $request->string('status')->toString();
        }

        if ($request->filled('source')) {
            $query['source'] = $request->string('source')->toString();
        }

        return $this->tickets->send('GET', '/support-tickets', $query);
    }

    public function adminShow(int $id): JsonResponse
    {
        return $this->tickets->send('GET', "/support-tickets/{$id}");
    }

    public function adminUpdate(UpdateTicketStatusRequest $request, int $id): JsonResponse
    {
        return $this->tickets->send('PUT', "/support-tickets/{$id}", [], [
            'status' => $request->validated('status'),
        ]);
    }

    public function adminReply(StoreTicketReplyRequest $request, int $id): JsonResponse
    {
        return $this->tickets->send('POST', "/support-tickets/{$id}/replies", [], [
            'author_role' => 'admin',
            'author_name' => (string) $request->user()->name,
            'message' => $request->validated('message'),
        ]);
    }

    private function assertOwner(Request $request, JsonResponse $response): void
    {
        if ($response->getStatusCode() >= 400) {
            return;
        }

        $ticket = $this->tickets->ticketPayload($response);
        if (! $ticket || (int) ($ticket['user_id'] ?? 0) !== (int) $request->user()->id) {
            throw new NotFoundHttpException('Ticket no encontrado.');
        }
    }
}
