<?php

declare(strict_types=1);

namespace App\Modules\MLM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MLM\Actions\ConvertCompanyPartnerAction;
use App\Modules\MLM\Actions\RegisterCompanyPartnerAction;
use App\Modules\MLM\Http\Requests\StoreCompanyPartnerRequest;
use App\Modules\MLM\Http\Requests\StoreTeamActivityRequest;
use App\Modules\MLM\Http\Requests\UpdateTeamMemberRequest;
use App\Modules\MLM\Http\Resources\InvitationResource;
use App\Modules\MLM\Services\TeamCrmService;
use App\Modules\MLM\Services\TeamReportService;
use App\Modules\MLM\Services\TeamRosterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeamMemberController extends Controller
{
    public function index(Request $request, TeamCrmService $crm): JsonResponse
    {
        $members = $crm->list($request->user());

        return response()->json([
            'data' => $members,
            'summary' => $crm->summary($request->user(), collect($members)),
        ]);
    }

    public function roster(Request $request, TeamRosterService $roster): JsonResponse
    {
        return response()->json($roster->roster($request->user()));
    }

    public function export(
        Request $request,
        TeamReportService $reports,
    ): \Illuminate\Http\Response {
        $kind = $request->string('kind')->toString();

        return $reports->download(
            $request->user(),
            $kind,
            $request->string('format')->toString(),
        );
    }

    public function storeCompanyPartner(
        StoreCompanyPartnerRequest $request,
        RegisterCompanyPartnerAction $action,
    ): JsonResponse {
        $member = $action->handle($request->user(), $request->validated());

        return response()->json([
            'data' => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'phone' => $member->phone,
                'company_code' => $member->external_code,
                'rank_name' => $member->rank_name,
            ],
        ], 201);
    }

    public function convertCompanyPartner(
        Request $request,
        int $id,
        ConvertCompanyPartnerAction $action,
    ): JsonResponse {
        if (! $request->user()?->can('invitation.create')) {
            abort(403, 'No puedes invitar socios de plataforma.');
        }

        $result = $action->handle($request->user(), $id);

        return (new InvitationResource($result['invitation']))
            ->additional([
                'token' => $result['token'],
                'member_id' => $result['member']->id,
            ])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, int $id, TeamCrmService $crm): JsonResponse
    {
        return response()->json($crm->show($request->user(), $id));
    }

    public function update(UpdateTeamMemberRequest $request, int $id, TeamCrmService $crm): JsonResponse
    {
        return response()->json($crm->update($request->user(), $id, $request->validated()));
    }

    public function storeActivity(StoreTeamActivityRequest $request, int $id, TeamCrmService $crm): JsonResponse
    {
        $activity = $crm->addActivity($request->user(), $id, $request->validated());

        return response()->json($activity, 201);
    }
}
