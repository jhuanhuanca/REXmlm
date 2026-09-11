<?php

declare(strict_types=1);

namespace App\Modules\Report\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Report\Http\Requests\UpsertPeriodGoalsRequest;
use App\Modules\Report\Services\MonthlyClosingService;
use App\Modules\Report\Services\PeriodGoalsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function monthlyClosing(Request $request, MonthlyClosingService $service): JsonResponse
    {
        try {
            return response()->json(
                $service->build($request->user(), $request->query('period'))
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function upsertGoals(
        UpsertPeriodGoalsRequest $request,
        PeriodGoalsService $goals,
        MonthlyClosingService $service,
    ): JsonResponse {
        $period = (string) $request->validated('period');
        $user = $request->user();

        $goals->upsert($user, $period, $request->validated('goals'));

        $nextGoals = $request->validated('next_goals');
        if (is_array($nextGoals)) {
            $nextPeriod = Carbon::createFromFormat('Y-m-d', $period.'-01')
                ?->addMonthNoOverflow()
                ->format('Y-m');
            if ($nextPeriod) {
                $goals->upsert($user, $nextPeriod, $nextGoals);
            }
        }

        try {
            $report = $service->build($user, $period);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($report['goals']);
    }

    public function downloadReport(Request $request, MonthlyClosingService $service): StreamedResponse|JsonResponse
    {
        try {
            $data = $service->build($request->user(), $request->query('period'));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $filename = 'reporte_'.$data['month'].'.csv';

        return response()->streamDownload(function () use ($data) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Métrica', 'Valor']);
            fputcsv($file, ['Mes', $data['month']]);
            fputcsv($file, ['Zona horaria', $data['timezone_label'] ?? $data['timezone'] ?? '']);
            fputcsv($file, ['Alcance', 'Red propia del líder']);
            fputcsv($file, ['Empresa', $data['organization']['name'] ?? '']);
            fputcsv($file, ['--- Plano A · plataforma REXmlm ---', '']);
            fputcsv($file, ['Ventas pagadas (tienda)', $data['sales']]);
            fputcsv($file, ['Ventas del equipo (tienda)', $data['sales_attributed'] ?? 0]);
            fputcsv($file, ['Ventas directas (tienda)', $data['sales_direct'] ?? 0]);
            fputcsv($file, ['Pedidos pagados (tienda)', $data['orders_count'] ?? 0]);
            fputcsv($file, ['Comisiones SaaS', $data['commissions']]);
            fputcsv($file, ['Comisiones pendientes', $data['commissions_breakdown']['pending'] ?? 0]);
            fputcsv($file, ['Comisiones pagadas', $data['commissions_breakdown']['paid'] ?? 0]);
            fputcsv($file, ['Nuevos miembros', $data['new_team_members']]);
            fputcsv($file, ['Socios que se volvieron líderes', $data['new_leaders'] ?? 0]);
            fputcsv($file, ['Invitaciones enviadas', $data['invitations']['sent'] ?? 0]);
            fputcsv($file, ['Invitaciones aceptadas', $data['invitations']['accepted'] ?? 0]);
            fputcsv($file, ['Seguimientos del mes', $data['follow_ups_due'] ?? 0]);
            fputcsv($file, ['Seguimientos vencidos', $data['team']['follow_ups_overdue'] ?? 0]);
            fputcsv($file, ['Socios sin ventas de tienda', $data['team']['partners_without_sales'] ?? 0]);
            fputcsv($file, ['--- Plano B · empresa ---', '']);
            fputcsv($file, ['Volumen disponible', ($data['company_volume']['available'] ?? false) ? 'sí' : 'no']);
            fputcsv($file, ['Unidad de volumen', $data['company_volume']['unit'] ?? '']);
            fputcsv($file, ['Volumen personal', $data['company_volume']['personal'] ?? 'no disponible']);
            fputcsv($file, ['Origen volumen personal', $data['company_volume']['personal_origin'] ?? '']);
            fputcsv($file, ['Volumen de grupo', $data['company_volume']['group'] ?? 'no disponible']);
            fputcsv($file, ['Origen volumen de grupo', $data['company_volume']['group_origin'] ?? '']);
            fputcsv($file, ['Bonos de empresa', $data['company_volume']['bonuses'] ?? 'no disponible']);
            fputcsv($file, ['Calificación', $data['qualification']['status'] ?? 'unpublished']);
            fputcsv($file, ['Calificación (nota)', $data['qualification']['message'] ?? '']);
            fputcsv($file, ['Rango importado', $data['company_volume']['rank']['name'] ?? '']);
            fputcsv($file, ['Proxy tienda (no es PV) personal', $data['store_proxy']['personal'] ?? 0]);
            fputcsv($file, ['Proxy tienda (no es PV) equipo', $data['store_proxy']['team'] ?? 0]);
            fputcsv($file, ['--- Metas plano A ---', '']);
            foreach ($data['goals']['items'] ?? [] as $item) {
                if (($item['plane'] ?? '') !== 'a') {
                    continue;
                }
                fputcsv($file, [
                    $item['label'].' (meta)',
                    $item['target'] ?? 'sin meta',
                ]);
                fputcsv($file, [
                    $item['label'].' (avance)',
                    $item['status'] === 'unavailable' ? 'no disponible' : ($item['actual'] ?? ''),
                ]);
            }
            fputcsv($file, ['--- Metas plano B ---', '']);
            foreach ($data['goals']['items'] ?? [] as $item) {
                if (($item['plane'] ?? '') !== 'b') {
                    continue;
                }
                fputcsv($file, [
                    $item['label'].' (meta)',
                    $item['target'] ?? 'sin meta',
                ]);
                fputcsv($file, [
                    $item['label'].' (avance)',
                    $item['status'] === 'unavailable' || $item['actual'] === null ? 'no disponible' : $item['actual'],
                ]);
            }
            fclose($file);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
