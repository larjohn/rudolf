<?php

namespace App\Console\Commands;

use App\Model\BabbageModelResult;
use App\Model\VolatileCacheManager;
use Cache;
use Illuminate\Console\Command;
use InvalidArgumentException;

class SoftCacheClear extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'soft:clear {name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clears the cache softly';

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
        $name = $this->argument("name");
        if(Cache::has($name)){
            throw new InvalidArgumentException("Model {$name} is already cached. No need to rebuild the cache.");
        }

        try{
           new  BabbageModelResult($name);

        }
        catch (InvalidArgumentException $ex){
            throw $ex;
        }

        VolatileCacheManager::reset();

        return "Softly cleared the cache.";
    }
}
