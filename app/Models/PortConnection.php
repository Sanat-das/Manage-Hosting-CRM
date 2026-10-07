<?php

namespace App\Models;

use App\Exceptions\PortConnectionConflictException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['port_a_id', 'port_b_id', 'cable_label', 'cable_type', 'cable_length_m', 'cable_color', 'notes'])]
class PortConnection extends Model
{
    /**
     * Selectable values of port_connections.cable_type.
     */
    public const CABLE_TYPES = [
        'cat5e', 'cat6', 'cat6a', 'cat7', 'om3_fiber', 'om4_fiber',
        'single_mode_fiber', 'dac', 'aoc', 'power', 'console', 'other',
    ];

    protected $table = 'port_connections';

    protected function casts(): array
    {
        return [
            'cable_length_m' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $connection): void {
            $connection->assertOneCablePerPort();
        });
    }

    public function portA(): BelongsTo
    {
        return $this->belongsTo(DevicePort::class, 'port_a_id');
    }

    public function portB(): BelongsTo
    {
        return $this->belongsTo(DevicePort::class, 'port_b_id');
    }

    /**
     * Enforce the one-cable-per-port invariant at the model layer.
     *
     * The two unique indexes only catch the same-column case (a port in
     * port_a_id twice, or port_b_id twice); a port appearing once in each of two
     * different rows is caught here. Canonical ordering (port_a_id < port_b_id)
     * makes a reversed duplicate impossible and display deterministic.
     */
    private function assertOneCablePerPort(): void
    {
        if ($this->port_a_id === null || $this->port_b_id === null) {
            return;
        }

        $portA = (int) $this->port_a_id;
        $portB = (int) $this->port_b_id;

        if ($portA === $portB) {
            throw new PortConnectionConflictException('A port cannot be connected to itself.');
        }

        if ($portA > $portB) {
            [$portA, $portB] = [$portB, $portA];
            $this->port_a_id = $portA;
            $this->port_b_id = $portB;
        }

        $ignoreId = $this->exists ? (int) $this->getKey() : null;

        $taken = static::query()
            ->where(function ($query) use ($portA, $portB): void {
                $query->whereIn('port_a_id', [$portA, $portB])
                    ->orWhereIn('port_b_id', [$portA, $portB]);
            })
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '<>', $ignoreId))
            ->exists();

        if ($taken) {
            throw new PortConnectionConflictException('One of the selected ports already has a cable.');
        }
    }
}
