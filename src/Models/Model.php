<?php

declare(strict_types=1);

namespace EloquentColumn\Models;

use EloquentColumn\Attributes\Models\Column;
use EloquentColumn\Traits\HasColumnAttributes;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Traits\Conditionable;

class Model extends \Illuminate\Database\Eloquent\Model
{
    use Conditionable;
    use HasColumnAttributes;
    use SoftDeletes;

    #[Column(name: 'id', type: 'id', hidden: true)]
    protected int $_id;

    #[Column(name: 'created_at', type: 'timestamp', cast: 'datetime', nullable: true)]
    protected ?Carbon $_created_at;

    #[Column(name: 'updated_at', type: 'timestamp', cast: 'datetime', nullable: true)]
    protected ?Carbon $_updated_at;

    #[Column(name: 'deleted_at', type: 'timestamp', hidden: true, cast: 'datetime', nullable: true)]
    protected ?Carbon $_deleted_at;
}
