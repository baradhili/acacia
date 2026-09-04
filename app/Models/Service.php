<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Service
 *
 * @property $id
 * @property $service_name
 * @property $description
 * @property $required_skills
 * @property $hours_cost
 * @property $created_at
 * @property $updated_at
 *
 * @mixin Builder
 */
class Service extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = ['service_name', 'description', 'required_skills', 'hours_cost'];
}
