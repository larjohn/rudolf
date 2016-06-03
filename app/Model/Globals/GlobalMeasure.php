<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 28/04/2016
 * Time: 12:45:25
 */

namespace App\Model\Globals;


use App\Model\Measure;

class GlobalMeasure extends Measure
{
    /**
     * @var Measure[]
     */
    protected $innerMeasures = [];

    protected $originalMeasure;

    /**
     * @return Measure[]
     */
    public function getInnerMeasures()
    {
        return $this->innerMeasures;
    }

    /**
     * @param array $innerMeasures
     */
    public function setInnerDimensions($innerMeasures)
    {
        $this->innerMeasures = $innerMeasures;
    }

    public function addInnerMeasure(Measure $innerMeasure){
        $this->innerMeasures[$innerMeasure->ref]=$innerMeasure;
    }
    public function addInnerMeasures(array $innerMeasures){
        $this->innerMeasures = array_merge($this->innerMeasures,$innerMeasures);
    }

    /**
     * @return mixed
     */
    public function getOriginalMeasure()
    {
        return $this->originalMeasure;
    }

    /**
     * @param mixed $originalMeasure
     */
    public function setOriginalMeasure($originalMeasure)
    {
        $this->originalMeasure = $originalMeasure;
    }

    protected $specialUri= "";

    /**
     * @return string
     */
    public function getSpecialUri()
    {
        return $this->specialUri;
    }

    /**
     * @param string $specialUri
     */
    public function setSpecialUri($specialUri)
    {
        $this->specialUri = $specialUri;
    }

}