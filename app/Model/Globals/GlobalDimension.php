<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 28/04/2016
 * Time: 12:45:25
 */

namespace App\Model\Globals;


use App\Model\Dimension;

class GlobalDimension extends Dimension
{
    /**
     * @var Dimension[]
     */
    protected $innerDimensions = [];

    /**
     * @return array
     */
    public function getInnerDimensions()
    {
        return $this->innerDimensions;
    }

    /**
     * @param array $innerDimensions
     */
    public function setInnerDimensions($innerDimensions)
    {
        $this->innerDimensions = $innerDimensions;
    }

    public function addInnerDimension(Dimension $innerDimension){
        $this->innerDimensions[$innerDimension->ref]=$innerDimension;
    }

}