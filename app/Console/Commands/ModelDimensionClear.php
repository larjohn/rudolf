<?php

namespace App\Console\Commands;

use Cache;
use Illuminate\Console\Command;

class ModelDimensionClear extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dimension:clear {model}/{dimension}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear model dimension';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        return Cache::forget("{$this->input('model')}/{$this->input('dimension')}");
    }
}
