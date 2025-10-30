# Выкладка логики приложения

## Назначение
Telegram-бот для адаптации резюме под вакансии с использованием OpenAI.

## Основные компоненты

### 1. Входная точка
- **Webhook**: `/telegram/webhook` и `/telegram/assistant_webhook`
- **Контроллер**: `TelegramAssistantController::webhook()`
- Проверка IP (172.29.0.1) или secret_token
- Обрабатывает только приватные чаты
- Игнорирует: inline_query, polls, chat_member и т.д.

### 2. Обработка входящих сообщений

#### Типы сообщений:
- **Текст**: `/start`, `/help`, обычные сообщения
- **Callback queries**: кнопки inline-клавиатуры
- **Медиа**: фото, видео, документы

#### Состояния пользователя (TelegramUser state):
- `null` - обычное состояние
- `MASTER_ACCEPTS_ORDER` - мастер принимает заказ

### 3. AI-обработка

#### Текстовые сообщения:
- Передаются в `LorService` с conversation ID = telegram_user.tid
- Используется template 2
- Ответ ассистента отправляется пользователю

#### Файлы:
- Скачиваются через `TelegramService::downloadFile()`
- Передаются в `LorService` с запросом "Analyze this file"
- Временные файлы удаляются после обработки

### 4. Сервисы

#### TelegramService
- Отправка сообщений (текст, медиа, группы)
- Разбивка длинных сообщений на части (лимит 4096)
- Скачивание файлов из Telegram
- Отправка typing action
- Логирование всех отправленных сообщений
- Определение блокировки бота пользователем

#### TelegramDanogService
- Работа через MadelineProto API
- Получение сообщений из каналов/групп
- Отправка медиа и сообщений
- Получение информации о пользователях

#### AppLogicService
- Функции для AI: `addOrder`, `closeOrder`, `getActiveOrders`, `addMaster`, `closeMaster`
- Создание/закрытие заказов и мастеров

#### LangService
- Определение языка текста (`findLanguageCode`)
- Перевод текста (`translate`) через LorService
- Кеш переводов в таблице `translation_cache`

### 5. Пользователи

#### Авторизация:
- Создание/поиск TelegramUser по tid
- Автоматическое создание User если нет
- Обновление username, name при каждом сообщении
- Установка активности (active/blocked)

#### Состояние активности:
- `ACTIVITY_STATUS_ACTIVE` - пользователь активен
- `ACTIVITY_STATUS_BLOCKED` - пользователь заблокировал бота

### 6. UI/UX

#### Приветственное сообщение:
```
Привет! Пришли сюда текст вакансии и своё резюме — 
я сделаю адаптированную версию с нужными формулировками.
```

#### Typing action:
- При входящем тексте запускается `TypingActionJob`
- Периодически отправляется typing action (каждые 7 сек, макс 60 сек)
- Управление через memcached ключ `typing_active_{tid}`

### 7. Логирование

#### TelegramLog:
- Все входящие сообщения (direction: received)
- Все исходящие сообщения (direction: sent)
- Ошибки и ответы API
- message_id для редактирования/удаления

#### LogEntry:
- Ошибки через DatabaseLogger
- Канал 'db' для записи в БД

#### RequestLog:
- HTTP запросы через RequestLogger middleware

### 8. Административные функции

#### Админские callback:
- `admin_acceptOrder/{id}`, `admin_rejectOrder/{id}` - модерация заказов
- `admin_acceptMaster/{id}`, `admin_rejectMaster/{id}` - модерация мастеров
- `admin_acceptExport/{id}`, `admin_rejectExport/{id}` - модерация экспорта
- Проверка прав через `config('app.admin_tids')`
- Удаление кнопок после обработки

### 9. База данных

#### Основные таблицы:
- `users` - пользователи системы
- `telegram_users` - связь Telegram аккаунтов с пользователями
- `telegram_logs` - все сообщения Telegram
- `orders` - заказы (вакансии)
- `masters` - мастера (исполнители)
- `translation_cache` - кеш переводов
- `log_entries` - логи ошибок
- `request_logs` - HTTP логи

### 10. Поток обработки сообщения

1. Webhook получает обновление от Telegram
2. Проверка авторизации (IP/token)
3. Фильтрация (только private chat)
4. Логирование в TelegramLog (received)
5. Получение/создание TelegramUser и User
6. Определение типа: callback_query или message
7. Для текста:
   - Запуск TypingActionJob
   - Отправка в LorService с conversation
   - Отправка ответа пользователю
8. Для файлов:
   - Скачивание файла
   - Отправка в LorService с файлом
   - Удаление временного файла
9. Логирование всех отправок (sent)

