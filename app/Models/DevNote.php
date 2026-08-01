<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * DEV NOTES MODULE — see database/migrations/..._create_dev_notes_table.php.
 * Removable side module for jotting ideas/fixes during development.
 */
class DevNote extends Model
{
    protected $fillable = ['body', 'done'];

    protected $casts = ['done' => 'boolean'];
}
