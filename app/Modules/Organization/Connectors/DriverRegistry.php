<?php

declare(strict_types=1);

namespace App\Modules\Organization\Connectors;

use App\Shared\Enums\ConnectionDriver;
use InvalidArgumentException;

class DriverRegistry
{
    /**
     * @param  array<string, ConnectionDriverContract>  $drivers
     */
    public function __construct(
        private readonly array $drivers,
    ) {}

    public static function make(): self
    {
        $drivers = [
            new CatalogDriver,
            new ApiDriver,
            new SpreadsheetDriver,
            new OtherDriver,
        ];

        $map = [];
        foreach ($drivers as $driver) {
            $map[$driver->key()] = $driver;
        }

        return new self($map);
    }

    public function get(ConnectionDriver|string $driver): ConnectionDriverContract
    {
        $key = $driver instanceof ConnectionDriver ? $driver->value : $driver;
        if (! isset($this->drivers[$key])) {
            throw new InvalidArgumentException('Medio de conexión no soportado: '.$key);
        }

        return $this->drivers[$key];
    }
}
