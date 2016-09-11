<?php

namespace App\Console;

use App\Console\Commands\CubeModelClear;
use App\Console\Commands\CubeModelLoad;
use App\Console\Commands\ModelDimensionClear;
use App\Console\Commands\SearchLoad;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        CubeModelClear::class,
        CubeModelLoad::class,
        ModelDimensionClear::class,
        SearchLoad::class,
        // Commands\Inspire::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // $schedule->command('inspire')
        //          ->hourly();
    }
}
