<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Idpromogroup\LaravelOpenaiResponses\Services\OpenAIService;

class TestFileUpload extends Command
{
    protected $signature = 'test';
    
    protected $description = 'Test file upload to OpenAI';

    public function handle()
    {
        $filePath = storage_path('Yana Vashkevich - CV.pdf');
        
        if (!file_exists($filePath)) {
            $this->line("File not found: {$filePath}");
            return 1;
        }
        
        $this->line("Testing file upload");
        $this->line("File path: {$filePath}");
        $this->line("File size: " . filesize($filePath) . " bytes");
        $this->line("");
        $this->line("Uploading to OpenAI...");
        
        try {
            $service = new OpenAIService('test_upload_' . time(), 'Analyze this file and tell me what you see');
            $service->useTemplate(2)
                    ->attachLocalFile($filePath);
            
            $result = $service->execute();
            
            if ($result->success) {
                $this->line("");
                $this->line("SUCCESS!");
                $this->line("");
                $this->line("Response:");
                $this->line($result->getAssistantMessage() ?? 'No message');
                $this->line("");
                $this->line("Full result:");
                $this->line(json_encode($result->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                return 0;
            } else {
                $this->line("");
                $this->line("FAILED!");
                $this->line("Error: " . $result->error);
                return 1;
            }
            
        } catch (\Exception $e) {
            $this->line("");
            $this->line("EXCEPTION!");
            $this->line("Message: " . $e->getMessage());
            $this->line("Trace: " . $e->getTraceAsString());
            return 1;
        }
    }
}

