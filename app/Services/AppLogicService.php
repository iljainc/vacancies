<?php

namespace App\Services;

use App\Models\Location;
use App\Models\Master;
use App\Models\Order;
use App\Models\TelegramUser;
use Illuminate\Support\Facades\Log;

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

    /**
     * Генерация PDF резюме из структурированных данных
     */
    private function generate_resume_pdf($arguments)
    {
        try {
            // Получаем пользователя Telegram
            $user = auth()->user();
            if (!$user) {
                return ['error' => 'User not authenticated'];
            }

            $telegramUser = TelegramUser::where('uid', $user->id)->first();
            if (!$telegramUser) {
                return ['error' => 'Telegram user not found'];
            }

            // Генерируем HTML для PDF
            $html = $this->buildResumeHTML($arguments);

            // Генерируем PDF
            $pdfPath = $this->generatePDF($html, $arguments['name'] ?? 'resume');

            if (!$pdfPath) {
                return ['error' => 'Failed to generate PDF'];
            }

            // Отправляем PDF в Telegram (синхронно)
            $sendResult = TelegramService::sendDocument($telegramUser->tid, $pdfPath);

            // Удаляем временный файл после отправки (если была ошибка - не удаляем для дебага)
            if ($sendResult && empty($sendResult->error)) {
                @unlink($pdfPath);
            } else {
                // При ошибке оставляем файл для дебага
                $errorMsg = $sendResult ? ($sendResult->error ?? 'unknown') : 'no result';
                Log::warning('PDF file not deleted due to send error', ['path' => $pdfPath, 'error' => $errorMsg]);
            }

            return [
                'success' => true,
                'message' => 'PDF resume generated and sent successfully',
            ];

        } catch (\Exception $e) {
            Log::error('generate_resume_pdf error: ' . $e->getMessage());
            return ['error' => 'Error generating PDF: ' . $e->getMessage()];
        }
    }

    /**
     * Формирует HTML для резюме
     */
    private function buildResumeHTML($data): string
    {
        $name = !empty($data['name']) ? htmlspecialchars(strtoupper($data['name'])) : '';
        $email = !empty($data['email']) ? htmlspecialchars($data['email']) : '';
        $phone = !empty($data['phone']) ? htmlspecialchars($data['phone']) : '';
        $description = !empty($data['description']) ? htmlspecialchars($data['description']) : '';

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
            body { font-family: Arial, sans-serif; font-size: 11pt; line-height: 1.4; margin: 40px; }
            .header { text-align: right; margin-bottom: 20px; }
            .header-contact { font-size: 10pt; color: #333; }
            .name { font-weight: bold; font-size: 18pt; text-transform: uppercase; margin-top: 10px; }
            .section { margin-top: 25px; }
            .section-title { font-weight: bold; font-size: 12pt; text-transform: uppercase; margin-bottom: 10px; }
            .company { font-weight: bold; text-transform: uppercase; margin-top: 15px; }
            .position { font-weight: bold; margin-top: 5px; }
            .period { font-style: italic; color: #666; margin-bottom: 5px; }
            ul { margin: 5px 0; padding-left: 20px; }
            li { margin: 3px 0; }
        </style></head><body>';

        // Header
        $html .= '<div class="header">';
        if ($email || $phone) {
            $html .= '<div class="header-contact">';
            if ($email && $phone) {
                $html .= htmlspecialchars($email) . ' | ' . htmlspecialchars($phone);
            } elseif ($email) {
                $html .= htmlspecialchars($email);
            } elseif ($phone) {
                $html .= htmlspecialchars($phone);
            }
            $html .= '</div>';
        }
        if ($name) {
            $html .= '<div class="name">' . $name . '</div>';
        }
        $html .= '</div>';

        // Description
        if ($description) {
            $html .= '<div class="section">';
            $html .= '<div class="section-title">Profile/Description - main strengths (MAX 380 characters)</div>';
            $html .= '<div>' . nl2br($description) . '</div>';
            $html .= '</div>';
        }

        // Experience
        if (!empty($data['experience']) && is_array($data['experience'])) {
            $html .= '<div class="section"><div class="section-title">EXPERIENCE</div>';
            foreach ($data['experience'] as $exp) {
                if (empty($exp['company']) || empty($exp['position'])) continue;
                
                $html .= '<div>';
                if (!empty($exp['period'])) {
                    $html .= '<div class="period">' . htmlspecialchars($exp['period']) . '</div>';
                }
                $html .= '<div class="company">' . htmlspecialchars(strtoupper($exp['company'])) . '</div>';
                $html .= '<div class="position">' . htmlspecialchars($exp['position']) . '</div>';
                
                $html .= '<ul>';
                if (!empty($exp['job_description'])) {
                    $html .= '<li>' . htmlspecialchars($exp['job_description']) . '</li>';
                }
                if (!empty($exp['achievements'])) {
                    $html .= '<li>' . htmlspecialchars($exp['achievements']) . '</li>';
                }
                if (!empty($exp['metrics'])) {
                    $html .= '<li>' . htmlspecialchars($exp['metrics']) . '</li>';
                }
                $html .= '</ul></div>';
            }
            $html .= '</div>';
        }

        // Education
        if (!empty($data['education']) && is_array($data['education'])) {
            $html .= '<div class="section"><div class="section-title">EDUCATION</div>';
            foreach ($data['education'] as $edu) {
                if (empty($edu['institution']) || empty($edu['degree'])) continue;
                
                $html .= '<div>';
                if (!empty($edu['period'])) {
                    $html .= '<div class="period">' . htmlspecialchars($edu['period']) . ', ' . htmlspecialchars($edu['institution']) . '</div>';
                } else {
                    $html .= '<div class="period">' . htmlspecialchars($edu['institution']) . '</div>';
                }
                $html .= '<div>' . htmlspecialchars($edu['degree']) . '</div>';
                $html .= '</div>';
            }
            $html .= '</div>';
        }

        // Skills
        if (!empty($data['skills']) && is_array($data['skills'])) {
            $html .= '<div class="section"><div class="section-title">SKILLS</div>';
            if (!empty($data['skills']['technical'])) {
                $html .= '<div><strong>Tools & Technical:</strong> ' . htmlspecialchars($data['skills']['technical']) . '</div>';
            }
            if (!empty($data['skills']['languages']) && is_array($data['skills']['languages'])) {
                $languages = [];
                foreach ($data['skills']['languages'] as $lang) {
                    if (!empty($lang['language'])) {
                        $level = !empty($lang['level']) ? ' (Level: ' . htmlspecialchars($lang['level']) . ')' : '';
                        $languages[] = htmlspecialchars($lang['language']) . $level;
                    }
                }
                if (!empty($languages)) {
                    $html .= '<div><strong>Languages:</strong> ' . implode(', ', $languages) . '</div>';
                }
            }
            $html .= '</div>';
        }

        // Projects
        if (!empty($data['projects']) && is_array($data['projects'])) {
            $html .= '<div class="section"><div class="section-title">PROJECTS (if applicable)</div><ul>';
            foreach ($data['projects'] as $project) {
                if (!empty($project)) {
                    $html .= '<li>' . htmlspecialchars($project) . '</li>';
                }
            }
            $html .= '</ul></div>';
        }

        $html .= '</body></html>';
        return $html;
    }

    /**
     * Генерирует PDF из HTML
     */
    private function generatePDF($html, $filename): ?string
    {
        try {
            // Пробуем использовать dompdf если установлен
            if (class_exists(\Dompdf\Dompdf::class)) {
                $dompdf = new \Dompdf\Dompdf();
                $dompdf->loadHtml($html);
                $dompdf->setPaper('A4', 'portrait');
                $dompdf->render();
                
                $pdfDir = storage_path('app/temp');
                if (!file_exists($pdfDir)) {
                    mkdir($pdfDir, 0755, true);
                }
                
                $baseName = $this->sanitizeFilename($filename);
                $pdfPath = $pdfDir . '/' . $baseName . '.pdf';
                // Если файл уже существует — добавляем суффикс
                if (file_exists($pdfPath)) {
                    $pdfPath = $pdfDir . '/' . $baseName . '_' . uniqid() . '.pdf';
                }
                file_put_contents($pdfPath, $dompdf->output());
                
                return $pdfPath;
            }
            
            // Альтернатива: используем TCPDF если установлен
            if (class_exists(\TCPDF::class)) {
                $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
                $pdf->SetCreator('Resume Generator');
                $pdf->SetTitle('Resume');
                $pdf->AddPage();
                $pdf->writeHTML($html, true, false, true, false, '');
                
                $pdfDir = storage_path('app/temp');
                if (!file_exists($pdfDir)) {
                    mkdir($pdfDir, 0755, true);
                }
                
                $baseName = $this->sanitizeFilename($filename);
                $pdfPath = $pdfDir . '/' . $baseName . '.pdf';
                if (file_exists($pdfPath)) {
                    $pdfPath = $pdfDir . '/' . $baseName . '_' . uniqid() . '.pdf';
                }
                $pdf->Output($pdfPath, 'F');
                
                return $pdfPath;
            }
            
            // Если нет библиотек - возвращаем ошибку через логи
            Log::error('generatePDF: No PDF library found. Install barryvdh/laravel-dompdf or use TCPDF');
            return null;
            
        } catch (\Exception $e) {
            Log::error('generatePDF error: ' . $e->getMessage());
            return null;
        }
    }

    private function sanitizeFilename($filename): string
    {
        $baseName = trim((string)$filename);
        $baseName = $baseName !== '' ? $baseName : 'resume';
        $baseName = preg_replace('/[^A-Za-zА-Яа-яЁё0-9 ._\-]+/u', '_', $baseName);
        $baseName = preg_replace('/[\s_]+/', ' ', $baseName);
        $baseName = trim($baseName, " .-_");
        if ($baseName === '') {
            $baseName = 'resume';
        }
        $baseName = preg_replace('/\.pdf$/iu', '', $baseName);
        if (mb_strlen($baseName) > 80) {
            $baseName = mb_substr($baseName, 0, 80);
        }
        return $baseName;
    }
}
