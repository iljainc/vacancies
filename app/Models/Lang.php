<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;


class Lang extends Model
{
    protected $fillable = ['lang'];

    public $timestamps = false; // Отключаем автоматическое управление временными метками

}


