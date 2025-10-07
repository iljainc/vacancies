<?php


namespace App\Services;

use danog\MadelineProto\API;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;
use danog\MadelineProto\Settings\Logger as LoggerSettings;
use danog\MadelineProto\Logger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use danog\MadelineProto\Settings\Ipc;



class TelegramDanogService
{
    protected $MadelineProto;
    protected $projectId;
    protected $settings;
    protected $usersCache = []; // Массив для кеширования пользователей
    protected $sessionFile = ''; // Session file path

    public function __construct($projectId = null, $apiId = null, $apiHash = null)
    {
        // Load from config if not provided
        $this->projectId = $projectId ?? config('services.telegram.project_id');
        $apiId = $apiId ?? config('services.telegram.api_id');
        $apiHash = $apiHash ?? config('services.telegram.api_hash');

        if (empty($this->projectId)) {
            echo "TelegramDanogService::__construct empty projectId: {$this->projectId}\n";
            return true;
        };

        // Ensure storage/app/telegram directory exists
        $telegramDir = storage_path('app/telegram');
        if (!is_dir($telegramDir)) {
            mkdir($telegramDir, 0777, true);
        }

        $md5Hash = md5($apiId.'-'.$apiHash);
        $checksum = substr($md5Hash, 0, 15);
        //print_r($checksum);
        $this->sessionFile = storage_path("app/telegram/session_{$checksum}.madeline");

        $this->settings = (new Settings)
            ->setAppInfo(
                (new AppInfo)
                    ->setApiId($apiId)
                    ->setApiHash($apiHash)
            )
            ->setLogger(
                (new LoggerSettings)->setLevel(0)
            );

    }

    public function initSession()
    {
        // Protection against multiple API() calls
        if ($this->MadelineProto instanceof \danog\MadelineProto\API) {
            return;
        }

        $this->MadelineProto = new API($this->sessionFile, $this->settings, [
            'logger' => 0,
            'ipc' => false,
            'peer' => [
                'cache_full_dialogs' => true,
            ]
        ]);

        try {
            $this->MadelineProto->start();
            
            // Disable logging after start
            $this->MadelineProto->updateSettings(
                (new \danog\MadelineProto\Settings\Logger())
                    ->setLevel(0)
            );
            
            echo "Session successfully started for project ID {$this->projectId}\n";
        } catch (\Exception $e) {
            echo "Error starting session: " . $e->getMessage() . "\n";
            $this->closeSession();
            $this->reopenSession();
        }
    }

    public function downloadFile($inputFile, $path)
    {
        $this->initSession();
        return $this->MadelineProto->downloadToFile($inputFile, $path);
    }

    public function downloadToDir($media, $dir)
    {
        $this->initSession();
        return $this->MadelineProto->downloadToDir($media, $dir);
    }

    /**
     * Get full photo information from message
     */
    public function getFullMessage($messageId, $peerId)
    {
        $this->initSession();
        
        try {
            // Получаем полное сообщение
            $result = $this->MadelineProto->messages->getMessages([
                'id' => [$messageId]
            ]);
            
            if (isset($result['messages'][0])) {
                return $result['messages'][0];
            }
            
            return null;
        } catch (\Exception $e) {
            echo "Error getting full message: " . $e->getMessage() . "\n";
            return null;
        }
    }

    /**
     * Send media file to bot and get file ID
     */
    public function sendMediaToBot($originalMedia, $botUsername)
    {
        $this->initSession();
        
        try {
            // Убираем устаревший file_reference из оригинальных данных
            $media = $originalMedia;
            if (isset($media['photo']['file_reference'])) {
                unset($media['photo']['file_reference']);
            }
            if (isset($media['document']['file_reference'])) {
                unset($media['document']['file_reference']);
            }
            
            $result = $this->MadelineProto->messages->sendMedia([
                'peer' => $botUsername,
                'media' => $media
            ]);
                        
            // Получаем file_id из результата
            if (isset($result['media'])) {
                $media = $result['media'];
                if (isset($media['photo'])) {
                    echo "Got photo ID: " . $media['photo']['id'] . "\n";
                    return ['id' => $media['photo']['id']];
                } elseif (isset($media['document'])) {
                    echo "Got document ID: " . $media['document']['id'] . "\n";
                    return ['id' => $media['document']['id']];
                }
            }
            
            // Если нет прямого media, ищем в updates
            if (isset($result['updates']) && is_array($result['updates'])) {
                foreach ($result['updates'] as $update) {
                    if ($update['_'] === 'updateNewMessage' && isset($update['message']['media'])) {
                        $media = $update['message']['media'];
                        if (isset($media['photo'])) {
                            echo "Got photo ID from updates: " . $media['photo']['id'] . "\n";
                            return ['id' => $media['photo']['id']];
                        } elseif (isset($media['document'])) {
                            echo "Got document ID from updates: " . $media['document']['id'] . "\n";
                            return ['id' => $media['document']['id']];
                        }
                    }
                }
            }
            
            echo "No media ID found in response or updates\n";
            return null;
        } catch (\Exception $e) {
            echo "Error sending media to bot: " . $e->getMessage() . "\n";
            return null;
        }
    }

    public function closeSession()
    {
        $this->initSession();

        try {
            $this->MadelineProto->stop(); // Остановка текущей сессии
            echo "Session successfully stopped for project ID {$this->projectId}\n";
        } catch (\Exception $e) {
            echo "Error stopping session: " . $e->getMessage() . "\n";
        }

        /*
        // Удаление файла сессии
        if (file_exists($this->sessionFile)) {
            rmdir($this->sessionFile);
            echo "Файл сессии удален: {$this->sessionFile}\n";
        } else {
            echo "Файл сессии не найден: {$this->sessionFile}\n";
        }
        */
    }
    public function reopenSession()
    {
        $this->initSession();

        echo "Trying to reopen session for project ID {$this->projectId}\n";

        try {
            $this->MadelineProto->start();
            echo "Session successfully started for project ID {$this->projectId}\n";
        } catch (\Exception $e) {
            echo "Error reopening session: " . $e->getMessage() . "\n";
        }
    }



    /**
     * Получить информацию о пользователе по username
     */
    public function getUserInfoByUsername($username)
    {
        $this->initSession();

        try {
            // Получаем информацию о пользователе по username
            $userInfo = $this->MadelineProto->getInfo('@' . $username);
            $userData = $userInfo['User'] ?? [];

            // Получаем полную информацию о пользователе для доступа к телефону
            $fullInfo = $this->MadelineProto->getFullInfo('@' . $username);
            $fullUserData = $fullInfo['User'] ?? [];

            return [
                'id' => $userData['id'] ?? null,
                'username' => $userData['username'] ?? null,
                'first_name' => $userData['first_name'] ?? null,
                'last_name' => $userData['last_name'] ?? null,
                'phone' => $fullUserData['phone'] ?? null,
                'email' => $fullUserData['email'] ?? null,
                'json_data' => array_merge($userData, $fullUserData),
            ];
        } catch (\Exception $e) {
            echo "Error getting user info by username: " . $e->getMessage() . "\n";
            return [
                'id' => null,
                'username' => $username,
                'first_name' => null,
                'last_name' => null,
                'phone' => null,
                'email' => null,
                'json_data' => null,
            ];
        }
    }

    /**
     * Получить информацию о пользователе и сохранить в кеш
     */
    public function getUserInfo($fromId)
    {
        $this->initSession();

        try {
            // Получаем информацию о пользователе через MadelineProto
            $userInfo = $this->MadelineProto->getInfo($fromId);
            $userData = $userInfo['User'] ?? [];

            // Получаем полную информацию о пользователе для доступа к телефону
            $fullInfo = $this->MadelineProto->getFullInfo($fromId);
            $fullUserData = $fullInfo['User'] ?? [];

            return [
                'id' => $fromId,
                'username' => $userData['username'] ?? null,
                'first_name' => $userData['first_name'] ?? null,
                'last_name' => $userData['last_name'] ?? null,
                'phone' => $fullUserData['phone'] ?? null,
                'email' => $fullUserData['email'] ?? null,
                'json_data' => array_merge($userData, $fullUserData),
            ];
        } catch (\Exception $e) {
            echo "Error getting user info: " . $e->getMessage() . "\n";
            return [
                'id' => $fromId,
                'username' => null,
                'first_name' => null,
                'last_name' => null,
                'phone' => null,
                'email' => null,
                'json_data' => null,
            ];
        }
    }


    /**
     * Получить список всех групп, в которых состоит пользователь
     *
     * @return array Массив групп с их ID и названиями
     */
    public function getAllGroups()
    {
        $this->initSession();

        $groups = [];

        try {
            // Получаем все диалоги
            $dialogsResponse = $this->MadelineProto->messages->getDialogs(['limit' => 1000]);

            foreach ($dialogsResponse['chats'] as $chat) {
                // Собираем информацию обо всех чатах
                if (isset($chat['title'])) {
                    /*
                     * ХЗ зачем он нужен
                     *
                     *
                    // Получаем полную информацию о канале или группе
                    try {
                        $chatInfo = $this->MadelineProto->getInfo($chat['id']);
                        $accessHash = $chatInfo['Chat']['access_hash'] ?? null;
                    } catch (\Exception $e) {
                        $this->logError("Ошибка при получении информации о чате ID {$chat['id']}: " . $e->getMessage());
                        $accessHash = null;
                    }
                    */

                    $groups[] = [
                        'id' => $chat['id'],
                        'title' => $chat['title'],
                        'username' => $chat['username'] ?? false,
                        'type' => $chat['_'], // Тип чата: channel, chat и т.д.
                        //'access_hash' => $accessHash,  // Добавляем access_hash
                        'is_megagroup' => $chat['megagroup'] ?? false, // Является ли это мегагруппой
                    ];

                    // Если это мегагруппа, можно попытаться получить подгруппы (темы форума)
                    if (($chat['megagroup'] ?? false)) {
                        try {
                            $topicsResponse = $this->MadelineProto->channels->getForumTopics([
                                'channel' => $chat['id'],
                                'limit' => 100, // Установите необходимый лимит
                            ]);

                            if (isset($topicsResponse['topics'])) {
                                foreach ($topicsResponse['topics'] as $topic) {
                                    if (isset($topic['title'])) {
                                        $groups[] = [
                                            'id' => $topic['id'],
                                            'title' => $topic['title'],
                                            'type' => 'forum_topic', // Это подгруппа (тема форума)
                                            'parent_group_id' => $chat['id'], // ID родительского форума
//                                           'access_hash' => $accessHash,  // Используем тот же access_hash
                                        ];
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                            // $this->logError("Ошибка при получении тем для группы ID {$chat['id']}: " . $e->getMessage());
                        }
                    }
                }
            }

            if (empty($groups)) {
                $this->logMessage("Groups and channels not found.");
            } else {
                $this->logMessage("List of groups and channels successfully received.");
            }
        } catch (\Exception $e) {
            $this->logError("Error getting list of groups and channels: " . $e->getMessage());
        }

        return $groups;
    }

    public function getAllMessages($chatId, $msgId, $console, $minDate = null)
    {
        $this->initSession();

        $dateInfo = '';
        if ($minDate) {
            $dateInfo = ', minDate=' . date('Y-m-d H:i:s', $minDate);
        }
        echo "getAllMessages:: Init for chatId " . json_encode($chatId) . " from id $msgId$dateInfo\n";

        $messagesToSend = [];
        $limit = 100;
        $min_id = $msgId;
        $offset_id = 0;

        try {
            while (true) {
                $sendData = [
                    'peer' => $chatId,
                    'min_id' => $min_id,         // фильтруем всё, что выше заданного ID
                    'offset_id' => $offset_id,   // смещаемся каждый раз
                    'limit' => $limit,
                ];

                $console->info("Requesting batch with all data: min_id={$min_id}, offset_id={$offset_id}, limit={$limit}");

                $response = $this->MadelineProto->messages->getHistory($sendData);
                $msgs = $response['messages'] ?? [];

                if (!empty($msgs)) {
                    $firstMsg = reset($msgs);
                    $firstId = $firstMsg['id'] ?? 'n/a';
                    $firstDate = isset($firstMsg['date']) ? date('Y-m-d H:i:s', $firstMsg['date']) : 'n/a';
                    $lastMsg = end($msgs);
                    $lastId = $lastMsg['id'] ?? 'n/a';
                    $lastDate = isset($lastMsg['date']) ? date('Y-m-d H:i:s', $lastMsg['date']) : 'n/a';
                    $console->info("Received " . count($msgs) . " messages, first message: ID={$firstId}, date={$firstDate}; last message: ID={$lastId}, date={$lastDate}");
                } else {
                    $console->info("Received 0 messages");
                }

                $console->info('TelegramDanogService:: loaded: ' . count($msgs));

                if (empty($msgs)) {
                    $console->info('TelegramDanogService:: break');
                    break;
                }

                // Очистка лишних данных и фильтрация по дате
                foreach ($msgs as $id => $msg) {
                    // Remove heavy and unnecessary fields
                    if (isset($msgs[$id]['media']['webpage']['cached_page'])) {
                        unset($msgs[$id]['media']['webpage']['cached_page']);
                    }
                    if (isset($msgs[$id]['media']['photo']['sizes'])) {
                        unset($msgs[$id]['media']['photo']['sizes']);
                    }
                    if (isset($msgs[$id]['media']['photo']['file_reference'])) {
                        unset($msgs[$id]['media']['photo']['file_reference']);
                    }
                    if (isset($msgs[$id]['media']['webpage']['photo'])) {
                        unset($msgs[$id]['media']['webpage']['photo']);
                    }
                    if (isset($msgs[$id]['media']['document']['thumbs'])) {
                        unset($msgs[$id]['media']['document']['thumbs']);
                    }
                    if (isset($msgs[$id]['media']['document']['attributes'])) {
                        unset($msgs[$id]['media']['document']['attributes']);
                    }
                    if (isset($msgs[$id]['media']['document']['file_reference'])) {
                        unset($msgs[$id]['media']['document']['file_reference']);
                    }
                    if (isset($msgs[$id]['media']['video']['thumbs'])) {
                        unset($msgs[$id]['media']['video']['thumbs']);
                    }
                    if (isset($msgs[$id]['media']['audio']['thumbs'])) {
                        unset($msgs[$id]['media']['audio']['thumbs']);
                    }
                    if (isset($msgs[$id]['media']['sticker']['thumbs'])) {
                        unset($msgs[$id]['media']['sticker']['thumbs']);
                    }
                    if (isset($msgs[$id]['media']['voice']['waveform'])) {
                        unset($msgs[$id]['media']['voice']['waveform']);
                    }
                    if (isset($msgs[$id]['media']['poll'])) {
                        unset($msgs[$id]['media']['poll']);
                    }
                    if (isset($msgs[$id]['reply_markup'])) {
                        unset($msgs[$id]['reply_markup']);
                    }

                }

                $messagesToSend = array_merge($messagesToSend, $msgs);

                // Прерывание по дате: если сообщение старше minDate, прекращаем загрузку
                if ($minDate && isset($lastMsg['date']) && $lastMsg['date'] < $minDate) {
                    $console->info('Break by minDate: last message date ' . date('Y-m-d H:i:s', $lastMsg['date']) . ' < minDate ' . date('Y-m-d H:i:s', $minDate));
                    break;
                }

                // Update offset_id to the minimum ID from the current batch
                $ids = array_column($msgs, 'id');
                $minId = !empty($ids) ? min($ids) : $offset_id;
                if ($minId === $offset_id) {
                    $console->error('TelegramDanogService:: break for $minId = '.$minId.', $offset_id = '.$offset_id);
                    break; // Prevent infinite loop
                }
                $offset_id = $minId;

                if ($msgId + 1 == $offset_id) {
                    $console->error('TelegramDanogService:: break for $msgId = '.$msgId.', $offset_id = '.$offset_id);
                    break;
                }
            }
        } catch (\danog\MadelineProto\RPCErrorException $e) {
            $console->error("TelegramDanogService:: Error getting messages: " . $e->getMessage());
        }

        $console->info('TelegramDanogService:: fully loaded: ' . count($messagesToSend));

        return $messagesToSend;
    }

    /**
     * Получить список сообщений для чата с указанной даты
     * Возвращает массив с текстовыми и медиа-сообщениями.
     */
    public function getMessages($chatId, $fromDateTimestamp, $param, $minId = 0)
    {
        $this->initSession();

        echo "getMessages:: Init for chatId ".json_encode($chatId)." fromDateTimestamp $fromDateTimestamp (".date('d.m.y H:i:s', $fromDateTimestamp).") min id ".$minId."\n";

        $messagesToSend = [];
        $offsetId = 0;
        if (empty($param['last_entry'])) $limit = 100;
        else {
            $limit = 1;
            $minId = 0;
        }

        $i = 1;

        do {
            try {
                $sendData = [
                    'offset_id' => $offsetId,
                    'offset_date' => 0,
                    'add_offset' => 0,
                    'limit' => $limit,
                    'max_id' => 0,
                    'min_id' => $minId,
                    'hash' => 0,
                ];

                if (is_array($chatId)) {
                    $sendData['peer'] = $chatId['chatId'];
                } else {
                    $sendData['peer'] = $chatId;
                }

                $threadIds= [];

                if (is_array($chatId) && isset($chatId['threadId'])) {
                    if (is_array($chatId['threadId'])) $threadIds = $chatId['threadId'];
                    else $threadIds[] = $chatId['threadId'];
                } else $threadIds = false;

                $response = $this->MadelineProto->messages->getHistory($sendData);

                // print_r($response['messages']);
                // exit;

                echo "Received messages: " . count($response['messages']) . "\n";

                foreach ($response['messages'] as $i => $message) {
                    $rowLogData = 'i = '.$i.', offsetId = '.$offsetId.' ';

                    if (empty($param['last_entry'])) {
                        // Проверяем, не старше ли сообщение $fromDateTimestamp (если указано)
                        $daysAgo = strtotime('-4 days');
                        if ($message['date'] <= $daysAgo) {
                            $this->logMessage("Throttling by daysAgo " . date('d.m.Y H:i:s', $daysAgo));
                            break 2; // Reached messages older than the specified date
                        }

                        // Проверяем, не старше ли сообщение $fromDateTimestamp (если указано)
                        if ($fromDateTimestamp > 0 && $message['date'] <= $fromDateTimestamp) {
                            $this->logMessage("Throttling by " . date('d.m.Y H:i:s', $message['date']) . ' < ' . date('d.m.Y H:i:s', $fromDateTimestamp));
                            break 2; // Reached messages older than the specified date
                        }
                    };

                    // Это общалка
                    if (!empty($message['from_id'])) {
                        $userInfo = $this->getUserInfo($message['from_id']);

                        if (empty($param['get_username_unknown'])) {
                            // Пропускаем анкноунов
                            if (empty($userInfo['username'])) {
                                $this->logError($rowLogData."Skipping - user does not have username");
                                continue;
                            };
                        };

                        $channel = false;
                    } else {
                        $channel = true;
                    }

                    // Пропускаем реплаи
                    if (empty($threadIds) AND isset($param['get_reply_to']) AND $param['get_reply_to'] == 0 AND !empty($message['reply_to'])) {
                        $this->logError($rowLogData."Skipping replies");
                        continue;
                    };

                    // Проверка обязательных текстов
                    if (isset($param['mandatory_text']) && is_array($param['mandatory_text'])) {
                        foreach ($param['mandatory_text'] as $mandatoryText) {
                            if (strpos($message['message'], $mandatoryText) === false) {
                                $this->logError($rowLogData."Skipping message: mandatory texts missing");
                                continue 2;
                            }
                        }
                    }

                    // Проверка запрещенных текстов
                    if (isset($param['prohibited_text']) && is_array($param['prohibited_text'])) {
                        foreach ($param['prohibited_text'] as $prohibitedText) {
                            if (strpos($message['message'], $prohibitedText) !== false) {
                                $this->logError($rowLogData."Skipping message: prohibited texts found");
                                continue 2;
                            }
                        }
                    }

                    // Проверка группы в канале
                    if (!empty($threadIds)) {
                        if (empty($message['reply_to'])){
                            $this->logError($rowLogData."reply_to not defined for threadIds - skipping");
                            continue;
                        };

                        if (!empty($message['reply_to']['reply_to_top_id'])){
                            $this->logError($rowLogData."Inside threadIds reply - skipping");
                            continue;
                        };

                        if (!in_array($message['reply_to']['reply_to_msg_id'], $threadIds)){
                            $this->logError($rowLogData." => ".$message['reply_to']['reply_to_msg_id']." not in available ".json_encode($threadIds)." reply - skipping");
                            continue;
                        };
                    }

                    // Проверяем наличие grouped_id
                    $groupedId = $message['grouped_id'] ?? 'NoGroup_'.$i++;

                    $messagesToSend[$groupedId]['id'] = $message['id'] ?? null;
                    $messagesToSend[$groupedId]['from_id'] = $userInfo['id'] ?? null;
                    $messagesToSend[$groupedId]['username'] = $userInfo['username'] ?? null;
                    $messagesToSend[$groupedId]['channel'] = $channel;

                    if (!empty($message['message'])) $messagesToSend[$groupedId]['message'] = $message['message'];

                    if (!empty($message['entities'])) $messagesToSend[$groupedId]['entities'] = $message['entities'];

                    // Если сообщение содержит медиа (фото, видео и т.д.)
                    if (isset($message['media'])) {
                        $mediaType = $message['media']['_'];

                        // Только фото и видео (игнорируем другие типы медиа)
                        if (in_array($mediaType, ['messageMediaPhoto', 'messageMediaDocument'])) {
                            $fileId = $message['media']['document']['id'] ?? $message['media']['photo']['id'] ?? null;
                            $accessHash = $message['media']['document']['access_hash'] ?? $message['media']['photo']['access_hash'] ?? null;

                            if ($fileId && $accessHash) {
                                // Добавляем новый файл к существующему сообщению
                                if (isset($messagesToSend[$groupedId]['files']))
                                    $fileIdName = count($messagesToSend[$groupedId]['files']);
                                else $fileIdName = 0;
                                $fileIdName++;

                                $messagesToSend[$groupedId]['files']['file_id_' . $fileIdName] = [
                                    'id' => $fileId,
                                    'access_hash' => $accessHash,
                                    'media_type' => $mediaType
                                ];;
                            }
                        } else if (in_array($mediaType, ['messageMediaGeo'])) {
                            // Создаем новый элемент для grouped_id
                            $messagesToSend[$groupedId]['long']         = $message['media']['geo']['long'];
                            $messagesToSend[$groupedId]['lat']          = $message['media']['geo']['lat'];
                            $messagesToSend[$groupedId]['access_hash']  = $message['media']['geo']['access_hash'];
                        }
                    };

                    if (empty($messagesToSend[$groupedId]['message']) && empty($messagesToSend[$groupedId]['files']) && empty($messagesToSend[$groupedId]['long'])) {
                        $this->logMessage($rowLogData."Empty message group $groupedId - deleting");
                        unset($messagesToSend[$groupedId]);
                    } else $this->logMessage($rowLogData."Message group $groupedId processed");
                }

                $offsetId += $limit;

                if (!empty($param['last_entry'])) {
                    return $messagesToSend;
                };

                //return $messagesToSend;

            } catch (\danog\MadelineProto\RPCErrorException $e) {
                echo "Error getting messages: " . $e->getMessage() . "\n";
                break;
            }

        } while (1 == 0);

        $messagesToSend = array_reverse($messagesToSend, true);

        // print_r($messagesToSend);
        //exit;

        return $messagesToSend; // Возвращаем сообщения в виде массива
    }

    /**
     * Отправить массив сообщений боту
     */
    public function sendMessageToChat($chatId, $message, $threadId = null, $accessHash = null)
    {
        $this->initSession();

        // Проверяем, если сообщение содержит текст и файлы
        $hasText = !empty($message['message']);
        $hasFiles = isset($message['files']) && !empty($message['files']);

        // Если нет файлов и текста — возвращаем
        if (!$hasFiles && !$hasText && empty($message['long']) && empty($message['lat'])) {
            $this->logMessage("Message does not contain text or files for sending.");
            return;
        };

        if (is_string($chatId) && strpos($chatId, '-') === false) {
            // Если это строка и не содержит "-", то это, скорее всего, имя пользователя или ботнейм
            if (strpos($chatId, '@') !== 0) {
                $peer = '@' . $chatId;
            } else {
                $peer = $chatId;  // Оставляем без изменений
            }
        } else {
            // Если это числовой ID (для групп и каналов, например, начинается с -100)
            $peer = $chatId;
        }

        $sendData = ['peer' => $peer];

        if (!empty($threadId)) {// Преобразуем channel_id в положительное число
            $sendData['reply_to_msg_id'] = $threadId;
        };

        // [
        //                'disable_web_page_preview' => true,
        //            ]

        $sendData['message'] = $message['message'] ?? ''; // Добавляем подпись, если есть
        //if (!empty($sendData['message'])) $sendData['disable_web_page_preview'] = true;
        if (!empty($message['entities'])) $sendData['entities'] = $message['entities'];

        // Если сообщение содержит только текст
        if (!empty($message['long']) && !empty($message['lat']) && !empty($message['access_hash'])) {
            // Определяем тип медиа в зависимости от MIME-типа
            $media = [
                '_' => 'inputMediaGeoPoint',
                'geo_point' => [
                    '_' => 'inputGeoPoint',
                    'lat' => $message['lat'], // Широта
                    'long' => $message['long'], // Долгота
                    'access_hash' => $message['access_hash'] // access_hash из вашего массива
                ],
            ];

            try {
                $sendData['media'] = $media;

                $this->MadelineProto->messages->sendMedia($sendData);
                $this->logMessage("Geo position sent to bot {$peer}");
            } catch (\danog\MadelineProto\RPCErrorException $e) {
                $this->logError("Error sending geo position: " . $e->getMessage());
            }
        } elseif ($hasText && !$hasFiles) {
            // Отправляем текстовое сообщение
            try {
                $this->MadelineProto->messages->sendMessage($sendData);
                $this->logMessage("Text message sent to bot {$peer}");
            } catch (\danog\MadelineProto\RPCErrorException $e) {
                $this->logError("Error sending text message: " . $e->getMessage());
            }
        // Если сообщение содержит 1 файл
        } else if (count($message['files']) === 1) {
            $file = reset($message['files']); // Получаем первый (и единственный) элемент

            // Убедимся, что файл содержит необходимые данные
            if (!isset($file['id']) || !isset($file['access_hash'])) {
                $this->logError("File does not contain 'id' or 'access_hash'. Skipping.");
                return;
            }

            $mediaType = $file['media_type'];
            // ['messageMediaPhoto', 'messageMediaDocument']

            // Определяем тип медиа в зависимости от MIME-типа
            if ($mediaType == 'messageMediaPhoto') {
                // Если это изображение
                $media = [
                    '_' => 'inputMediaPhoto',
                    'id' => [
                        '_' => 'inputPhoto',
                        'id' => $file['id'],
                        'access_hash' => $file['access_hash'],
                    ],
                ];
            } elseif ($mediaType == 'messageMediaDocument') {
                // Если это видео
                $media = [
                    '_' => 'inputMediaDocument', // Для видео используем inputMediaDocument
                    'id' => [
                        '_' => 'inputDocument', // Для видео используем inputDocument
                        'id' => $file['id'],
                        'access_hash' => $file['access_hash'],
                    ],
                ];
            };

            // Отправляем одиночное медиа сообщение
            try {
                $sendData['media'] = $media;

                $this->MadelineProto->messages->sendMedia($sendData);
                $this->logMessage("Media message sent to bot {$peer}");
            } catch (\danog\MadelineProto\RPCErrorException $e) {
                $this->logError("Error sending media message: " . $e->getMessage());
            }
        } else {
            $mediaGroup = [];
            foreach ($message['files'] as $file) {
                // Убедимся, что файл содержит необходимые данные
                if (!isset($file['id']) || !isset($file['access_hash'])) {
                    $this->logMessage("File does not contain 'id' or 'access_hash'. Skipping.");
                    continue;
                }

                $mediaType = $file['media_type'];
                // ['messageMediaPhoto', 'messageMediaDocument']

                // Определяем тип медиа в зависимости от MIME-типа
                if ($mediaType == 'messageMediaPhoto') {
                    // Если это изображение
                    $media = [
                        '_' => 'inputMediaPhoto',
                        'id' => [
                            '_' => 'inputPhoto',
                            'id' => $file['id'],
                            'access_hash' => $file['access_hash'],
                        ],
                    ];
                } elseif ($mediaType == 'messageMediaDocument') {
                    // Если это видео
                    $media = [
                        '_' => 'inputMediaDocument', // Для видео используем inputMediaDocument
                        'id' => [
                            '_' => 'inputDocument', // Для видео используем inputDocument
                            'id' => $file['id'],
                            'access_hash' => $file['access_hash'],
                        ],
                    ];
                };

                // Формируем данные для каждого файла
                $mediaGroup[] = [
                    '_' => 'inputSingleMedia',
                    'media' => $media,
                    'random_id' => mt_rand(), // Уникальный random_id для каждого файла
                    'message' => $message['message'] ?? '', // Используем 'message' для подписи
                ];
            }

            if (empty($mediaGroup)) {
                $this->logMessage("No valid files for sending.");
                return;
            }

            $sendDataMultimedia = $sendData;
            $sendDataMultimedia['multi_media'] = $mediaGroup;

            // Попробуем отправить медиагруппу с использованием 'sendMultiMedia'
            try {
                $this->MadelineProto->messages->sendMultiMedia($sendDataMultimedia);
                $this->logMessage("Media group sent to bot {$peer} with text.");
            } catch (\danog\MadelineProto\RPCErrorException $e) {
                $this->logError("Error sending media group: " . $e->getMessage());
            };

            // Попробуем отправить медиагруппу с использованием 'sendMultiMedia'
            try {
                $this->MadelineProto->messages->sendMessage($sendData);
                $this->logMessage("Text message from media group sent to bot {$peer} with text.");
            } catch (\danog\MadelineProto\RPCErrorException $e) {
                $this->logError("Error sending text message from media group: " . $e->getMessage());
            };
        }

        usleep(300000); // 0.3 секунды
        $this->logMessage("0,3 sec");
    }

    /**
     * Логирует сообщение в зависимости от того, запущено ли приложение в консоли.
     */
    protected function logMessage($message)
    {
        if (app()->runningInConsole()) {
            echo $message . "\n";
        } else {
            Log::info($message);
        }
    }

    /**
     * Логирует ошибки в зависимости от того, запущено ли приложение в консоли.
     */
    protected function logError($message)
    {
        if (app()->runningInConsole()) {
            echo "Error: " . $message . "\n";
        } else {
            Log::error($message);
        }
    }

    public function getUserById($fromId): ?array
    {
        $this->initSession();

        try {
            $info = $this->MadelineProto->getFullInfo($fromId);
            return $info['User'] ?? null;
        } catch (\Throwable $e) {
            $this->logError("getUserById: error getting data by ID $fromId: " . $e->getMessage());
            return null;
        }
    }

}
