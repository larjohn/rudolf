<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 07/05/2016
 * Time: 23:39:08
 */

namespace App\Model\Globals;


use App\Model\Aggregate;

class GlobalAggregate extends Aggregate
{
    private $dataSets;

    /**
     * @return mixed
     */
    public function getDataSets()
    {
        return $this->dataSets;
    }

    /**
     * @param mixed $dataSets
     */
    public function setDataSets($dataSets)
    {
        $this->dataSets = $dataSets;
    }


}