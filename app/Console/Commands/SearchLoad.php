<?php

namespace App\Console\Commands;

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
        return new SearchResult();
    }
}
