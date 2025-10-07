<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TelegramLog extends Model
{
    use HasFactory;

    public const LIMIT = 200;

    public const DIRECTION_SENT = 'sent';
    public const DIRECTION_RECEIVED = 'received';

    protected $fillable = [
        'tid',
        'text',
        'direction',
        'error',
        'response',
        'message_id',
        'type',
        'comm'
    ];

    protected $casts = [
        'message_id' => 'array',
        'comm' => 'array'
    ];

    public $timestamps = true;

    // Переопределяем метод getUpdatedAtColumn, чтобы он возвращал null
    // Это предотвратит ошибку при попытке обновления несуществующего поля 'updated_at'
    public function getUpdatedAtColumn()
    {
        return null;
    }
}
