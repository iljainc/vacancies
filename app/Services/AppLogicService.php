<?php

namespace App\Services;

use App\Models\Location;
use App\Models\Master;
use App\Models\Order;

class AppLogicService
{
    /**
     * Выполняет указанную функцию.
     */
    public function execute($functionName, $arguments)
    {
        // Проверяем, существует ли метод с таким именем
        if (method_exists($this, $functionName)) {
            return $this->{$functionName}($arguments);
        }

        throw new \Exception("Unknown function: {$functionName}");
    }

    /**
     * Функция создания заказа.
     */
    private function addOrder($arguments, $uid = false)
    {
        // Проверяем наличие обязательных полей
        if (empty($arguments['lot_name']) || empty($arguments['description']) || empty($arguments['bid']) || empty($arguments['locations'])) {
            \Log::error('addOrder: отсутствуют обязательные поля', ['arguments' => $arguments]);
            return ['error' => 'Missing required fields: lot_name, description, bid, locations'];
        }

        // Проверяем что bid это число
        if (!is_numeric($arguments['bid']) || $arguments['bid'] <= 0) {
            \Log::error('addOrder: некорректная ставка', ['bid' => $arguments['bid']]);
            return ['error' => 'Invalid bid amount'];
        }

        $description = $arguments['description'];
        $bid = (float) $arguments['bid'];
        $locations = $arguments['locations'];
        $lotName = $arguments['lot_name'];

        $uid = $uid ?: auth()->id() ?: 1;

        // Создаём заказ со всеми полученными данными
        $order = Order::create([
            'uid' => $uid,
            'text' => $description,
            'lot_name' => $lotName,
            'source' => Order::SOURCE_AI,
            'bid' => $bid,
            'locations' => $locations,
        ]);

        // Присоединяем временные файлы для этого пользователя
        $filesCount = $order->attachTemporaryFiles($uid);

        // Локации определим позже - пока не привязываем

        return [
            'success' => true,
            'order_id' => $order->id,
            'message' => 'Order created successfully',
            'bid' => $bid,
            'locations' => $locations
        ];
    }

    private function closeOrder($arguments, $uid = false)
    {
        // Если uid не передан, берём ID текущего авторизованного пользователя
        $uid = $uid ?: auth()->id();

        // Ищем заказ с нужным ID и тем же uid (если нужно ограничить закрытие чужих заказов)
        $order = Order::where('id', $arguments['order_id'])
            ->where('uid', $uid)
            ->firstOrFail();

        // Закрываем заказ (ставим отметку о дате закрытия)
        $order->update(['closed_at' => now()]);

        return ['status' => 'order closed'];
    }

    private function getActiveOrders($arguments, $uid = false)
    {
        // Если uid не передан, берём ID текущего авторизованного пользователя
        $uid = $uid ?: auth()->id();

        // Получаем все заказы текущего пользователя, у которых поле closed_at = NULL
        $orders = Order::whereNull('closed_at')
            ->where('uid', $uid)
            ->orderBy('id', 'desc')
            ->limit(20)
            ->get(['id', 'text']);

        // Формируем структуру для ответа
        return [
            'active_orders' => $orders->map(function ($order) {
                return [
                    'id'   => $order->id,
                    'text' => $order->text,
                ];
            })->toArray(),
        ];
    }

    private function closeAllOrders($arguments, $uid = false)
    {
        $uid = $uid ?: auth()->id();

        // Закрываем все заказы, у которых closed_at = NULL
        Order::where('uid', $uid)
            ->whereNull('closed_at')
            ->update(['closed_at' => now()]);

        return ['status' => 'all orders closed'];
    }

    private function addMaster($arguments, $uid = false)
    {
        $uid = $uid ?: auth()->id();

        // Проверяем наличие обязательных полей
        if (empty($arguments['description']) || empty($arguments['country'])) {
            return false;
        }

        // Ищем уже имеющегося мастера
        $master = Master::where('uid', $uid)->first();

        // Если мастера нет — создаём нового
        if (!$master) {
            $master = Master::create([
                'uid'       => $uid,
                'closed_at' => null,
                'text'      => $arguments['description'],
            ]);
        } else {
            // Реактивируем существующего мастера
            $master->closed_at = null;
            $master->text_admin_check = Master::TEXT_ADMIN_CHECK_NEW; // На можерацию
            $master->text_en = ''; // Обнуляем перевод
            $master->text      = $arguments['description'];
            $master->save();
        }

        // Location processing moved to SendMsgToAdmin command
        // AI location extraction will be done there

        return [
            'status'    => 'master added or reactivated',
            'master_id' => $master->id,
        ];
    }


    /**
     * «Закрывает» (деактивирует) текущего мастера у пользователя.
     */
    private function closeMaster($arguments, $uid = false)
    {
        $uid = $uid ?: auth()->id();

        // Ищем активного мастера пользователя
        $master = Master::where('uid', $uid)
            ->firstOrFail();

        // Ставим дату закрытия
        $master->update(['closed_at' => now()]);

        return ['status' => 'master closed'];
    }

    private function getMaster($arguments, $uid = false)
    {
        $uid = $uid ?: auth()->id();

        // Ищем "активного" мастера
        $master = Master::where('uid', $uid)
            ->whereNull('closed_at')
            ->first();

        if (!$master) {
            return [
                'status' => 'You are not registered as a master',
            ];
        }

        return [
            'status'    => 'success',
            'text'      => $master->text,
        ];
    }
}
