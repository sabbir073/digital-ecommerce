<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A message sent through a Contact form block. */
class ContactMessage extends Model
{
    public const NEW = 'new';

    public const READ = 'read';

    public const DONE = 'done';

    protected $fillable = ['name', 'email', 'message', 'status'];
}
