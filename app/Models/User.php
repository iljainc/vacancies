<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Master;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'telegram_id',
        'state',
        'lang',
        'lang_priority',
        'lang_check_date'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
    ];

    // Связь "один к одному" с моделью TelegramUser
    public function telegramUser()
    {
        return $this->hasOne(TelegramUser::class, 'uid');
    }

    // Связь "один ко многим" с моделью LanguagePriority
    public function languagePriorities()
    {
        return $this->hasMany(LanguagePriority::class, 'uid');
    }

    public function master()
    {
        return $this->hasOne(Master::class, 'uid')->latest('id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'uid', 'id');
    }

    public function setLang($languageCode, $priority = 0)
    {
        if ($this->lang == $languageCode) return false;

        if ($this->lang_priority > $priority) return false;

        $languagePriority = LanguagePriority::create([
            'uid' => $this->id,
            'lang' => $languageCode,
            'priority' => $priority
        ]);

        $this->update(['lang' => $languageCode, 'lang_priority' => $priority]);

        return true;
    }

    public function orderInWork() {
        return $this->orders()->where('status', Order::STATUS_IN_WORK)->get();
    }

}
