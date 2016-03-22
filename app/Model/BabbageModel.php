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
    /**
     * @var string
     */
    private $dataset;

    /**
     * @var string
     */
    private $dsd;

    /**
     * @param string $dataset
     */
    public function setDataset($dataset)
    {
        $this->dataset = $dataset;
    }

    /**
     * @param string $dsd
     */
    public function setDsd($dsd)
    {
        $this->dsd = $dsd;
    }

    /**
     * @return string
     */
    public function getDataset()
    {
        return $this->dataset;
    }

    /**
     * @return string
     */
    public function getDsd()
    {
        return $this->dsd;
    }


}