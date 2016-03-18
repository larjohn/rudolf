<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 16/03/2016
 * Time: 13:31:53
 */

namespace App\Model;


class BabbageModel
{
    /**
     * @var Aggregate[]
     */
    public $aggregates;

    /**
     * @var Dimension[]
     */
    public $dimensions;

    /**
     * @var Measure[]
     */
    public $measures;

    /**
     * @var Hierarchy[]
     */
    public $hierarchies;

    /**
     * @var string
     */
    public $fact_table;
    
    
    

}