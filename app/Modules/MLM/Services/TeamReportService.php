<?php

declare(strict_types=1);

namespace App\Modules\MLM\Services;

use App\Models\User;
use App\Shared\Exports\WorkbookExport;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class TeamReportService
{
    public function __construct(
        private readonly TeamRosterService $roster,
    ) {}

    public function download(User $leader, string $kind, string $format): Response
    {
        $kind = $kind === 'company' ? 'company' : 'referrals';
        $roster = $this->roster->roster($leader);
        $rows = collect($roster['data']);

        if ($kind === 'company') {
            $subset = $rows->where('kind', 'company')->values();
            $export = new WorkbookExport(
                'Socios de empresa',
                $leader->name.' · '.count($subset).' registros',
            );
            $export->addSheet('Empresa', [
                'Nombre',
                'Correo',
                'Teléfono',
                'Código empresa',
                'Rango',
                'Estado',
                'Invitación pendiente',
                'Se puede convertir',
                'Alta',
            ], $subset->map(fn (array $row) => [
                $row['name'] ?? '',
                $row['email'] ?? '',
                $row['phone'] ?? '',
                $row['company_code'] ?? '',
                $row['rank_name'] ?? '',
                $row['status'] ?? '',
                ! empty($row['invite_pending']) ? 'Sí' : 'No',
                ! empty($row['can_convert']) ? 'Sí' : 'No',
                $this->date($row['created_at'] ?? null),
            ])->all());

            return $export->download('socios_empresa_'.now()->format('Y-m-d'), $format);
        }

        $subset = $rows->whereIn('kind', ['partner', 'leader'])->values();
        $export = new WorkbookExport(
            'Socios referidos',
            $leader->name.' · socios y líderes de plataforma (no incluye empresa)',
        );
        $export->addSheet('Referidos', [
            'Tipo',
            'Nombre',
            'Correo',
            'Nivel',
            'Estado red',
            'CRM',
            'Su equipo',
            'Órdenes pagadas',
            'Ventas mes',
            'Ventas total',
            'Seguimiento',
            'Último contacto',
            'Notas',
            'Alta',
        ], $subset->map(fn (array $row) => [
            ($row['kind'] ?? '') === 'leader' ? 'Líder' : 'Socio',
            $row['name'] ?? ($row['referred']['name'] ?? ''),
            $row['email'] ?? ($row['referred']['email'] ?? ''),
            $row['level'] ?? '',
            $row['status'] ?? '',
            $this->crm($row['crm_stage'] ?? null),
            (int) ($row['downline_count'] ?? 0),
            (int) ($row['orders_count'] ?? 0),
            round((float) ($row['sales_month'] ?? 0), 2),
            round((float) ($row['sales_total'] ?? 0), 2),
            $this->date($row['follow_up_at'] ?? null),
            $this->date($row['last_contacted_at'] ?? null),
            $row['notes'] ?? '',
            $this->date($row['created_at'] ?? null),
        ])->all());

        return $export->download('socios_referidos_'.now()->format('Y-m-d'), $format);
    }

    private function crm(mixed $stage): string
    {
        $value = is_object($stage) && isset($stage->value) ? $stage->value : (string) $stage;

        return match ($value) {
            'contacted' => 'Contactado',
            'active' => 'Activo',
            'follow_up' => 'Seguimiento',
            'needs_support' => 'Necesita apoyo',
            'independent' => 'Independiente',
            default => 'Nuevo',
        };
    }

    private function date(mixed $value): string
    {
        if ($value instanceof Carbon) {
            return $value->toDateTimeString();
        }
        if ($value === null || $value === '') {
            return '';
        }

        return (string) $value;
    }
}
