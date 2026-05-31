<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

#[Table(name: 'role_has_permissions', incrementing: true)]
class RoleHasPermission extends Pivot
{
    use HasUuids;

    /**
     * The secondary unique ID columns.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }
}
