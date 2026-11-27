<?php

namespace App\Jobs;

use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendTelegramJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $telegramId;
    protected $text;

    public $tries = 3;
    public $timeout = 10;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(int $telegramId, string $text)
    {
        $this->onQueue('send_telegram');
        $this->telegramId = $telegramId;
        $this->text = $text;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $telegramService = new TelegramService();
        $telegramService->sendMessage($this->telegramId, $this->text, 'markdown');
    }

    public function failed(Throwable $exception): void
    {
        Log::error('SendTelegramJob failed', [
            'exception_type' => get_class($exception),
            'reason' => $exception->getMessage(),
        ]);
    }
}
