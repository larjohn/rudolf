<?php

namespace App\Console\Commands;

use App\Model\BabbageModelResult;
use App\Model\Globals\BabbageGlobalModelResult;
use Illuminate\Console\Command;

class CubeModelLoad extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'model:load {name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Loads a cube model into cache';

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
        if($this->argument("name")!="global")
            return new BabbageModelResult($this->argument("name"));
        else
            return new BabbageGlobalModelResult();
    }
}
