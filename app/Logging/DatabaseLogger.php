<?php
namespace App\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use App\Models\LogEntry; // Убедитесь, что вы используете правильное имя вашей модели

class DatabaseLogger extends AbstractProcessingHandler
{
    protected function write(LogRecord  $record): void
    {
        try {
            // Проверяем наличие менеджера БД
            if (!app()->bound('db')) {
                return;
            }

            // Пытаемся получить активное соединение и PDO; при любой ошибке выходим тихо
            try {
                $connection = app('db')->connection();
                if (!$connection) {
                    return;
                }
                $pdo = $connection->getPdo();
                if (!$pdo) {
                    return;
                }
            } catch (\Throwable $e) {
                return;
            }

            // Получаем полное сообщение
            $message = $record->message ?? ($record['message'] ?? '');
            
            // Если есть исключение в контексте, добавляем его к сообщению
            $contextArr = $record->context ?? ($record['context'] ?? []);
            if (isset($contextArr['exception']) && $contextArr['exception'] instanceof \Throwable) {
                $exception = $contextArr['exception'];
                $message = $exception->getMessage() . "\nFile: " . $exception->getFile() . 
                          "\nLine: " . $exception->getLine() . "\nTrace:\n" . $exception->getTraceAsString();
            }

            // Преобразуем сообщение в UTF-8
            $message = mb_convert_encoding($message, 'UTF-8', 'auto');

            // Преобразуем контекст в JSON, убедившись, что он также в UTF-8
            $context = json_encode($contextArr, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

            // Создаем запись в логе
            LogEntry::create([
                'level' => ($record->level_name ?? ($record['level_name'] ?? '')),
                'message' => $message,
                'context' => $context,
            ]);
        } catch (\Throwable $e) {
            // Если не удалось записать в БД, игнорируем ошибку
            return;
        }
    }
}
