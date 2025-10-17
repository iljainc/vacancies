<?php

namespace App\Jobs;

use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class TypingActionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $chatId;
    protected $maxDuration; // Максимальное время в секундах
    protected $interval; // Интервал в секундах

    /**
     * Create a new job instance.
     */
    public function __construct($chatId, $maxDuration = 60, $interval = 5)
    {
        $this->chatId = $chatId;
        $this->maxDuration = $maxDuration;
        $this->interval = $interval;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $startTime = time();
        
        while ((time() - $startTime) < $this->maxDuration) {
            // Проверяем memcached - если ключа нет, останавливаемся
            if (!Cache::has("typing_active_{$this->chatId}")) {
                return;
            }
            
            try {
                TelegramService::sendTypingAction($this->chatId);
            } catch (\Exception $e) {
                Log::error('TypingActionJob error: ' . $e->getMessage());
                break;
            }
            
            sleep($this->interval);
        }
    }
}
