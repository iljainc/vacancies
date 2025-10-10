<?php

namespace App\Http\Controllers\Api;


use App\Helpers\OfferHelper;

use App\Models\AdminRequest;
use App\Models\Location;
use App\Models\MasterFile;
use App\Models\MasterOrder;
use App\Models\Offer;
use App\Models\TelegramLog;
use App\Models\TelegramMessageQueue;
use App\Models\TelegramUser;
use App\Models\Order;
use App\Models\Master;
use App\Models\User;
use App\Models\ExportProjectPost;

use App\Http\Controllers\Controller;
use App\Services\LangService;
use Idpromogroup\LaravelOpenaiResponses\Services\OpenAIService;
use Carbon\Carbon;
use Illuminate\Http\Request;

use App\Services\TelegramService;
use App\Services\ChatGptService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class TelegramAssistantController extends Controller
{
    protected $tUser = false;

    protected $log = false;

    protected $request = false;

    public function webhook(Request $request)
    {
        if ($request->ip() !== '172.29.0.1') {
            // Получаем secret_token из заголовка запроса
            $secretToken = $request->header('X-Telegram-Bot-Api-Secret-Token');

            // Сравниваем токены
            if (trim($secretToken) != env('TELEGRAM_SECRET_TOKEN')) {
                abort(403, 'Unauthorized: invalid token.');
            };
        };

        $this->request =$request->json()->all();

        // Игнорируем все что не приватные чаты
        $chatTypes = ['message', 'edited_message', 'channel_post', 'edited_channel_post'];
        foreach ($chatTypes as $type) {
            if (isset($this->request[$type]['chat']['type']) && $this->request[$type]['chat']['type'] !== 'private') {
                return response()->json(['status' => 'ignored - not private chat']);
            }
        }
        
        // Игнорируем другие типы обновлений
        $ignoreTypes = ['inline_query', 'chosen_inline_result', 'shipping_query', 'pre_checkout_query', 'poll', 'poll_answer', 'my_chat_member', 'chat_member', 'chat_join_request'];
        foreach ($ignoreTypes as $type) {
            if (isset($this->request[$type])) {
                return response()->json(['status' => 'ignored - ' . $type]);
            }
        }

        // Проверяем callback_query отдельно
        if (isset($this->request['callback_query']['message']['chat']['type']) && $this->request['callback_query']['message']['chat']['type'] !== 'private') {
            return response()->json(['status' => 'ignored - not private chat']);
        }
        
        // Логирование всех входящих сообшений
        $this->log();

        $this->getUser();
        
        $return = [];

        if (isset($this->request['callback_query']))        $this->handleCallback($this->request['callback_query']);
        else                                                $return = $this->handleMessage($this->request['message']);

        return response()->json($return ?? ['status' => 'success']);
    }

    function handleMessage($message) {
        // Получаем текст из сообщения или описания к медиа
        $text = $message['text'] ?? $message['caption'] ?? null;
        
        // Обрабатываем текст если есть
        if (!empty($text)) {
            if ($text == '/start')                                               $this->sendWelcomeMessage();
            else if ($text =='/help')                                            $this->actionHelpMessage();
            else if ($this->tUser->state == TelegramUser::MASTER_ACCEPTS_ORDER)  $this->actionMasterAcceptOrder($text);
            else if (!empty($text)){
                // Отправляем индикатор набора сообщения
                TelegramService::sendTypingAction($this->tUser->tid);
                
                // Отправляем текст в ИИ
                $service = new OpenAIService('telegram_' . $this->tUser->tid, $text);
                $result = $service
                    ->setConversation((string)$this->tUser->tid)
                    ->useTemplate(2)
                    ->execute();
                
                if ($result->success) {
                    $answer = $result->getAssistantMessage();
                    if ($answer) {
                        $this->sendMessage($answer);
                    }
                } else {
                    Log::error('OpenAI error for user ' . $this->tUser->tid . ': ' . $result->error);
                    $this->sendMessage(__('Sorry, a technical error occurred. Please try again later. #1'));
                }
            };
        };
        
        // Обрабатываем файлы если есть
        $downloadedFiles = $this->handleMediaFiles($message);
        if (!empty($downloadedFiles)) {
            foreach ($downloadedFiles as $filePath) {
                try {
                    TelegramService::sendTypingAction($this->tUser->tid);
                    
                    $service = new OpenAIService('telegram_' . $this->tUser->tid, 'Analyze this file');
                    $service->setConversation((string)$this->tUser->tid)
                            ->useTemplate(2)
                            ->attachLocalFile($filePath);
                    
                    $result = $service->execute();
                    
                    if ($result->success) {
                        $answer = $result->getAssistantMessage();
                        if ($answer) {
                            $this->sendMessage($answer);
                        }
                    } else {
                        Log::error('OpenAI file error for user ' . $this->tUser->tid . ': ' . $result->error);
                        $this->sendMessage(__('Sorry, an error occurred while processing the file. #2'));
                    }
                } finally {
                    if (file_exists($filePath)) {
                        @unlink($filePath);
                    }
                }
            }
        }
    }

    function handleCallback($callbackQuery) {
        $data = $callbackQuery['data'];

        // Мастер принимает заказ на исполнение.
        if ($data == 'start')                                                             $this->sendWelcomeMessage();
        else if (preg_match('/^extend_order_(\d+)$/', $data, $matches))         $this->actionExtendOrder($matches[1]);
        else if (preg_match('/^fulfill_masterOrder_(\d+)$/', $data, $matches))  $this->actionInitMasterAcceptOrder($matches[1]);
        else if (strpos($data, 'admin_') !== false)                                 $this->handleAdminCallback($data, $callbackQuery["message"]["message_id"]);

        // admin_acceptOrder_
    }

    private function handleAdminCallback($data, $messageId) {
        // Парсим admin_command_id
        if (!preg_match('/^admin_([a-zA-Z]+)_(\d+)$/', $data, $matches)) {
            return false;
        }

        $command = $matches[1];
        $objId = $matches[2];
        
        // acceptExport и rejectExport НЕ требуют проверки админских прав
        if (!in_array($command, ['acceptExport', 'rejectExport'])) {
            // Безопасность - проверяем что это админ для других команд
            $adminTids = config('app.admin_tids');
            if (!in_array($this->tUser->tid, $adminTids)) {
                $this->sendMessage('Realy?');
                return;
            }
        }

        // Обработка заказов
        if (($command == 'acceptOrder' OR $command == 'rejectOrder') AND $order = Order::find($objId)) {
            if ($command == 'acceptOrder') $order->text_admin_check = Order::TEXT_ADMIN_CHECK_INWORK;
            else if ($command == 'rejectOrder') $order->text_admin_check = Order::TEXT_ADMIN_CHECK_BLOCKED;
            $order->save();
            
            // Удаляем все админские сообщения для этого заказа
            $this->deleteAdminMessages("admin_order_{$objId}");
        } 
        // Обработка мастеров
        else if (($command == 'acceptMaster' OR $command == 'rejectMaster') AND $master = Master::find($objId)) {
            if ($command == 'acceptMaster') $master->text_admin_check = Master::TEXT_ADMIN_CHECK_INWORK;
            else if ($command == 'rejectMaster') $master->text_admin_check = Master::TEXT_ADMIN_CHECK_BLOCKED;
            $master->save();
            
            // Удаляем все админские сообщения для этого мастера
            $this->deleteAdminMessages("admin_master_{$objId}");
        }
        // Обработка экспорта
        else if (($command == 'acceptExport' OR $command == 'rejectExport') AND $moderation = ExportProjectPost::find($objId)) {
            if ($command == 'acceptExport') $moderation->admin_status  = ExportProjectPost::ADMIN_STATUS_INWORK;
            else if ($command == 'rejectExport') $moderation->admin_status  = ExportProjectPost::ADMIN_STATUS_BLOCKED;
            $moderation->save();
            
            // Удаляем все админские сообщения для этой модерации
            $this->deleteAdminMessages("admin_export_{$objId}");
        }
    }

    private function deleteAdminMessages($type) {
        // Находим все отправленные сообщения для этого типа
        $logs = \App\Models\TelegramLog::where('type', $type)
            ->where('direction', 'sent')
            ->whereNotNull('message_id')
            ->get();
        
        foreach ($logs as $log) {
            // Убираем только кнопки, сообщение остается
            \App\Services\TelegramService::editMessageReplyMarkup($log->tid, $log->message_id, []);
        };
    }



    private function  actionInitMasterAcceptOrder($masterOrderId) {
        // Удалвяем кнопку принять заказ
        $this->clearInlineKeyboard();

        $masterOrder = MasterOrder::find($masterOrderId);

        if ($masterOrder->order->status == Order::STATUS_CLOSED) {
            return $this->sendMessage(__("Sorry. This order is already closed."));
        };

        $this->tUser->setStateMasterAcceptsOrder($masterOrderId);

        $this->sendMessage(__('Please leave your comments on the order').' <b>#'.$masterOrder->order_id.'</b> '.
            __('If possible, indicate the approximate cost and timeline. Please note that this information will be sent to the client for their consideration.'));
    }

    private function  actionMasterAcceptOrder($text) {
        if (empty($masterOrder = MasterOrder::find($this->tUser->json['master_order_id']))) {
            Log::channel('db')->error('TelegramController::actionMasterAcceptOrder не найден master_order_id #' . $this->tUser->json['master_order_id']);
            return false;
        };

        $masterOrder->master_comments = $text;
        $masterOrder->save();

        $this->tUser->setStateNULL();

        $this->sendMessage(__('Your comments on the order have been received. We will send your contact details to the client.'));
    }

    function sendWelcomeMessage() {
        $this->tUser->setStateNULL();

        $responseText = "Привет! 👋

Я помогу тебе разместить твоё предложение на аукцион.
Просто напиши мне описание, цену и локацию.

Если есть вопросы – просто напиши.";

        $this->sendMessage($responseText, $this->returnMenuButton());
    }

    function actionHelpMessage() {
        $responseText = __("👋 Привет! Я — виртуальный помощник для размещения лотов на аукционе 😊

Могу помочь создать объявление. Чтобы начать, пришлите:
1. Полное описание лота.  
2. Стартовая ставка в шекелях  
3. Локация — адрес, где можно забрать

Можно просто отправить все в одном сообщении, и я всё оформлю. Есть вопросы?");

        $this->sendMessage($responseText, $this->returnMenuButton());
    }

    function clearInlineKeyboard($delMsg = false) {
        $chatId = $this->tUser->tid;
        $messageId = $this->request['message']['message_id'] ?? $this->request['callback_query']['message']['message_id'];

        if ($delMsg) {
            // Удаление сообщения
            $jsonDeleteMessage = ['json' => [
                'chat_id' => $chatId,
                'message_id' => $messageId
            ]];

            $this->sendCommand($jsonDeleteMessage, 'deleteMessage');
        } else {
            // Удаление клавиатуры, если сообщение не удаляется
            $jsonRemoveKeyboard = ['json' => [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'reply_markup' => json_encode(['inline_keyboard' => []]) // Пустой массив удаляет клавиатуру
            ]];

            $this->sendCommand($jsonRemoveKeyboard, 'editMessageReplyMarkup');
        }
    }

    function sendMessage($text, $keyboard = '', $command = 'sendMessage') {
        TelegramService::sendMessage($this->tUser->tid, $text, $keyboard, $command);
    }

    function sendCommand($json, $command) {
        TelegramService::send($this->tUser->tid, $json, $command);
    }

    function returnMenuButton() {
        return [];

        /*
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => __("I'm looking for a master"), 'callback_data' => 'solve_problem'],
                    ['text' => __("I'm looking for new orders"), 'callback_data' => 'become_master']
                ]
            ]
        ];

        // Есть текущий заказ который надо закрыть.
        if ($this->tUser->user->orderInWork()->count() > 0) {
            return $this->returnMenuCloseOrder();
        };


        return $keyboard = [
            'keyboard' => [ // Используем 'keyboard' вместо 'inline_keyboard'
                [
                    ['text' => __("Find master"), 'callback_data' => 'solve_problem'],
                    ['text' => __("Find orders"), 'callback_data' => 'become_master'],
                    // ['text' => __("Chat whith human"), 'callback_data' => 'chat']
                ]
            ],
            'resize_keyboard' => true, // Опционально: подгонка размера клавиатуры под количество кнопок
            'one_time_keyboard' => true // Опционально: клавиатура скрывается после использования
        ];
        */
    }

    function returnMenuCloseOrder() {
        return $keyboard = [
            'keyboard' => [ // Используем 'keyboard' вместо 'inline_keyboard'
                [
                    ['text' => __("Close order"), 'callback_data' => 'close_order'],
                    // ['text' => __("Chat whith human"), 'callback_data' => 'chat']
                ]
            ],
            'resize_keyboard' => true, // Опционально: подгонка размера клавиатуры под количество кнопок
            'one_time_keyboard' => true // Опционально: клавиатура скрывается после использования
        ];
    }

    function log() {
        $data = $this->request;
        // Инициализация переменных
        $from_id = null;

        // Проверка на тип входящего запроса и извлечение данных
        if (isset($data['message'])) {
            // Это текстовое сообщение
            $from_id = $data['message']['from']['id'] ?? null;
        } elseif (isset($data['callback_query'])) {
            // Это коллбэк от нажатия на кнопку
            $from_id = $data['callback_query']['from']['id'] ?? null;
        };

        // TelegramService::sendMessage(402281921, 'LOG '.$from_id.' === '.$text);

        // Логирование полученных данных, если они доступны
        $this->log = TelegramLog::create([
            'tid' => $from_id,
            'text' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'direction' => TelegramLog::DIRECTION_RECEIVED,
            'type' => 'assistant',
        ]);

        // Отправка подтверждения об успешной обработке запроса Telegram
    }

    protected function getUser() {
        $chat = $this->request;

        try {
            if (isset($chat['message'])) {
                $data = $chat['message']['from'];
            } elseif (isset($chat['callback_query'])) {
                $data = $chat['callback_query']['from'];
            } else {
                throw new \Exception("Invalid request user - " . json_encode($chat));
            }
            // Ваш дальнейший код обработки данных в $data
        } catch (\Exception $e) {
            // Логируем ошибку или выполняем другую обработку
            report($e);

            // Возвращаем ответ с кодом 200
            http_response_code(200);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);

            return true;
        }

        $languageCode   = $data['language_code'] ?? null;
        $telegramId     = $data['id'];

        $name = ($data['first_name'] ?? '').' '.($data['last_name'] ?? '');
        $username = $data['username'] ?? null;

        // Найти или создать TelegramUser
        $telegramUser = TelegramUser::firstOrCreate(
            ['tid' => $telegramId],
            ['uid' => null, 'tid' => $telegramId, 'name' => $name, 'username' => $username] // установите значение uid в null для нового пользователя
        );

        // Если TelegramUser новый и не имеет связанного User, создаем User
        if (!$telegramUser->user) {
            $user = User::create(['lang' => $languageCode, 'name' => $name]);

            // Обновляем TelegramUser с новым uid
            $telegramUser->uid = $user->id;
            $telegramUser->save();

            $telegramUser = TelegramUser::firstWhere('tid', $telegramId);
        };

        $langSwitch = $telegramUser->user->setLang($languageCode, 1);

        app()->setLocale($telegramUser->user->lang);

        if ($telegramUser->username != $username OR $telegramUser->name != $name) {
            $telegramUser->update(['name' => $name, 'username' => $username]);
        };

        // Если получили сообщение - пользователь не заблокировал бота
        if ($telegramUser->activity_status !== TelegramUser::ACTIVITY_STATUS_ACTIVE) {
            $telegramUser->activity_status = TelegramUser::ACTIVITY_STATUS_ACTIVE;
            $telegramUser->save();
        }

        $this->tUser = $telegramUser;

        // Авторизация пользователя в Laravel
        Auth::login($telegramUser->user);

        // Обновить текст клавиатуры
        if (!empty($langSwitch)) {
            $this->sendMessage($telegramUser->user->lang, $this->returnMenuButton());
        };
    }

    private function handleMediaFiles($message) {
        $downloadedFiles = [];
        $fileIds = [];
        
        // Собираем file_id из разных типов медиа
        if (isset($message['photo'])) {
            // Берем фото наибольшего размера
            $photo = end($message['photo']);
            $fileIds[] = $photo['file_id'];
        }
        
        if (isset($message['video'])) {
            $fileIds[] = $message['video']['file_id'];
        }
        
        if (isset($message['document'])) {
            $fileIds[] = $message['document']['file_id'];
        }
        
        if (isset($message['audio'])) {
            $fileIds[] = $message['audio']['file_id'];
        }
        
        // Скачиваем файлы
        foreach ($fileIds as $fileId) {
            try {
                $filePath = TelegramService::downloadFile($fileId);
                if ($filePath) {
                    $downloadedFiles[] = $filePath;
                }
            } catch (\Exception $e) {
                Log::error('Error downloading file: ' . $e->getMessage());
            }
        }
        
        return $downloadedFiles;
    }
    
}
