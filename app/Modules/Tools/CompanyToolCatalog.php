<?php

declare(strict_types=1);

namespace App\Modules\Tools;

final class CompanyToolCatalog
{
    /** @var list<string> */
    public const KEYS = [
        'wellness',
        'imc',
        'wellness_consult',
        'flyers',
        'pdfs',
        'videos',
        'audios',
        'ring_sizer',
    ];

    /**
     * @param  list<mixed>|null  $raw
     * @return list<string>
     */
    public static function normalize(?array $raw): array
    {
        if ($raw === null) {
            return self::KEYS;
        }

        $enabled = [];

        foreach ($raw as $item) {
            if (! is_string($item) || ! in_array($item, self::KEYS, true)) {
                continue;
            }

            if (! in_array($item, $enabled, true)) {
                $enabled[] = $item;
            }
        }

        return $enabled;
    }

    public static function allows(?array $raw, string $key): bool
    {
        return in_array($key, self::normalize($raw), true);
    }

    public static function documentKey(?string $fileType): ?string
    {
        return match ($fileType) {
            'flyer', 'flyers', 'image' => 'flyers',
            'pdf', 'pdfs' => 'pdfs',
            'video', 'videos' => 'videos',
            'audio', 'audios' => 'audios',
            default => null,
        };
    }
}
