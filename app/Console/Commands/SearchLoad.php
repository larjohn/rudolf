<?php

namespace App\Console\Commands;

use App;
use App\Model\SearchResult;
use Illuminate\Console\Command;

class SearchLoad extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'search:load';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Load Search Cache';

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
        $all  = new SearchResult();

        $count = count($all->packages);
        $pageSize = config("sparql.search.pageSize", 50);
        $lastPage = intval($count/$pageSize);

        for($i=0;$i<$lastPage;$i++){
            try{
                new SearchResult(null,"", $pageSize, $i*$pageSize);

            }
            catch (\Exception $exception){
                App::abort("500", $exception->getTraceAsString());
            }
        }

        return $all;
    }
}
