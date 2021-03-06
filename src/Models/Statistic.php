<?php

namespace Kolgaev\IpInfo\Models;

use Illuminate\Database\Eloquent\Model;

class Statistic extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'date',
        'ip',
    ];

    /**
     * Attributes to be converted.
     *
     * @var array
     */
    protected $casts = [
        'visits' => 'integer',
        'requests' => 'integer',
        'visits_drops' => 'integer',
    ];
}
