<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $keyword
 * @property string $cnae_code
 */
#[Fillable(['keyword', 'cnae_code'])]
class CnaeMapping extends Model {}
