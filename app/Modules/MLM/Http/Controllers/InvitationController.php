<?php

declare(strict_types=1);

namespace App\Modules\MLM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MLM\Actions\CreateInvitationAction;
use App\Modules\MLM\Http\Requests\StoreInvitationRequest;
use App\Modules\MLM\Http\Resources\InvitationResource;
use App\Modules\MLM\Jobs\SendInvitationEmail;
use App\Modules\MLM\Models\Invitation;
use Illuminate\Http\JsonResponse;

class InvitationController extends Controller
{
    public function store(StoreInvitationRequest $request, CreateInvitationAction $action): JsonResponse
    {
        $result = $action->handle($request->user(), $request->validated('email'));

        $invitation = $result['invitation'];

        SendInvitationEmail::dispatch($invitation, $result['token']);

        return (new InvitationResource($invitation))
            ->additional([
                'token' => $result['token'],
                'email_sent' => true,
                'resent' => $result['resent'],
            ])
            ->response()
            ->setStatusCode($result['resent'] ? 200 : 201);
    }

    public function show(string $token): InvitationResource
    {
        $invitation = Invitation::query()
            ->byPlainToken($token)
            ->with('leader:id,name')
            ->firstOrFail();

        return new InvitationResource($invitation);
    }
}
