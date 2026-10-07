<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['port_connection_id', 'port_a_id', 'port_b_id', 'a_asset_tag', 'a_port_name', 'b_asset_tag', 'b_port_name', 'action', 'cable_label', 'cable_type', 'cable_length_m', 'cable_color', 'changed_by_user_id', 'changed_at', 'notes'])]
class PortConnectionEvent extends Model
{
    /**
     * Valid values of port_connection_events.action.
     */
    public const ACTIONS = ['connected', 'disconnected', 'cable_updated'];

    protected $table = 'port_connection_events';

    /**
     * The ledger records its own `changed_at` moment; the table has no
     * created_at / updated_at columns.
     */
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
            'cable_length_m' => 'decimal:2',
        ];
    }
}
