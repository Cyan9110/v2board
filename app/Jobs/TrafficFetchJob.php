<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;

class TrafficFetchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $data;
    protected $server;
    protected $protocol;

    public $tries = 3;
    public $timeout = 10;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(array $data, array $server, $protocol)
    {
        $this->onQueue('traffic_fetch');
        $this->data =$data;
        $this->server = $server;
        $this->protocol = $protocol;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $rate = $this->server['rate'];
        foreach ($this->data as $userId => $trafficData) {
            $upload = (int)round($trafficData[0] * $rate * 5);
            $download = (int)round($trafficData[1] * $rate * 1.25);
            Redis::hincrby('v2board_upload_traffic', $userId, $upload);
            Redis::hincrby('v2board_download_traffic', $userId, $download);
        }
    }
}
