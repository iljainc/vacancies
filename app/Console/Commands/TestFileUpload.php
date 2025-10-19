<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Idpromogroup\LaravelOpenaiResponses\Services\LorService;

class TestFileUpload extends Command
{
    protected $signature = 'test';
    
    protected $description = 'Test file upload to OpenAI';

    public function handle()
    {
        $files = [
            'HananVerdievResume (4).pdf',
            'photo_2022-08-21_15-23-42.jpg',
            'Резюме.docx'
        ];
        
        $tid = 6542135909; // Жестко заданный TID пользователя
        
        $this->line("Testing file upload for user TID: {$tid}");
        $this->line("");
        
        foreach ($files as $file) {
            $filePath = storage_path("app/public/{$file}");
            
            if (!file_exists($filePath)) {
                $this->line("File not found: {$filePath}");
                continue;
            }
            
            $this->line("Processing file: {$file}");
            $this->line("File path: {$filePath}");
            $this->line("File size: " . filesize($filePath) . " bytes");
            $this->line("");
            $this->line("Uploading to OpenAI...");
            
            try {
                $service = new LorService("telegram_{$tid}", 'Analyze this file and tell me what you see');
                $service->setConversation($tid)
                        ->useTemplate(2)
                        ->attachLocalFile($filePath);
                
                $result = $service->execute();
                
                if ($result->success) {
                    $this->line("");
                    $this->line("SUCCESS for {$file}!");
                    $this->line("");
                    $this->line("AI Response:");
                    $answer = $result->getAssistantMessage();
                    $this->line($answer ? substr($answer, 0, 500) . (strlen($answer) > 500 ? '...' : '') : 'No message');
                    $this->line("");
                } else {
                    $this->line("");
                    $this->line("FAILED for {$file}!");
                    $this->line("Error: " . $result->error);
                }
                
            } catch (\Exception $e) {
                $this->line("");
                $this->line("EXCEPTION for {$file}!");
                $this->line("Message: " . $e->getMessage());
            }
            
            $this->line("---");
        }
        
        return 0;
    }
}

