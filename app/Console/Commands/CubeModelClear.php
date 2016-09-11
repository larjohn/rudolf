<?php

namespace App\Console\Commands;

use Cache;
use Illuminate\Console\Command;

class CubeModelClear extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'model:clear {name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clears a cube model';

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
        Cache::forget($this->argument("name"));
        return "Cleared";
    }
}
