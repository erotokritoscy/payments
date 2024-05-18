<?php

namespace Erotokritoscy\Payments\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JccTransaction extends Model
{
    use HasFactory;

    const STATUS_PENDING = 1;
    const STATUS_SUCCESS = 2;
    const STATUS_FAIL = 3;

}
