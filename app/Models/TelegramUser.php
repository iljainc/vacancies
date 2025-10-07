<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TelegramUser extends Model
{
    use HasFactory;

    public const SET_LANG = 'Set lang';
    public const CREATE_ORDER = 'Creating order';
    public const BECOME_MASTER = 'Become a master';
    public const MASTER_ACCEPTS_ORDER = 'Master accepts order';
    public const ADMIN_CHAT = 'Admin chat';
    
    // Activity status constants
    public const ACTIVITY_STATUS_ACTIVE = 'active';
    public const ACTIVITY_STATUS_BLOCKED = 'blocked';
    


    protected $fillable = ['uid', 'state', 'name', 'username', 'lang', 'tid', 'json', 'activity_status'];

    protected $casts = [
        'json' => 'array',  // Указывает, что 'json' должен быть автоматически преобразован в JSON и обратно
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'uid');
    }

    public function logs()
    {
        return $this->hasMany(TelegramLog::class, 'tid', 'tid');
    }

    public function setStateNULL() {
        $this->state = null;
        $this->json = '';
        $this->save();
    }

    public function setStateSetLang() {
        $this->state = self::SET_LANG;
        $this->save();
    }

    public function setStateOrder() {
        $this->state = self::CREATE_ORDER;
        $this->save();
    }

    /*
    public function setStateAdminChat() {
        $this->state = self::ADMIN_CHAT;
        $this->json = ['time' => date('U')];
        $this->save();
    }
    */

    public function setStateOrderAddLocation($orderId) {
        $this->state = self::CREATE_ORDER;
        $this->json = ['order_id' => $orderId];
        $this->save();
    }

    public function setStateMaster() {
        $this->state = self::BECOME_MASTER;
        $this->save();
    }

    public function setStateMasterAcceptsOrder($masterOrderId) {
        $this->state = self::MASTER_ACCEPTS_ORDER;
        $this->json = ['master_order_id' => $masterOrderId];
        $this->save();
    }

}
