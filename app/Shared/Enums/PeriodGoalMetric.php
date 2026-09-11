<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum PeriodGoalMetric: string
{
    case StoreSales = 'store_sales';
    case Commissions = 'commissions';
    case NewMembers = 'new_members';
    case Invitations = 'invitations';
    case PersonalVolume = 'personal_volume';
    case GroupVolume = 'group_volume';

    public function plane(): string
    {
        return match ($this) {
            self::PersonalVolume, self::GroupVolume => 'b',
            default => 'a',
        };
    }

    public function unit(): string
    {
        return match ($this) {
            self::StoreSales, self::Commissions => 'USD',
            self::NewMembers, self::Invitations => 'count',
            self::PersonalVolume, self::GroupVolume => 'volume',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::StoreSales => 'Ventas de tienda',
            self::Commissions => 'Comisión SaaS',
            self::NewMembers => 'Altas de equipo',
            self::Invitations => 'Invitaciones enviadas',
            self::PersonalVolume => 'Volumen personal',
            self::GroupVolume => 'Volumen de grupo',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::StoreSales => 'GMV pagado de tu tienda. No es PV.',
            self::Commissions => 'Comisión de plan, no bono de empresa.',
            self::NewMembers => 'Socios que entran a tu red en el mes.',
            self::Invitations => 'Invitaciones que envías en el periodo.',
            self::PersonalVolume => 'PV / puntos de la empresa. Sin conector no se evalúa.',
            self::GroupVolume => 'GV / grupo de la empresa. Sin conector no se evalúa.',
        };
    }
}
