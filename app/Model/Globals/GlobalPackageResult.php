<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 13/05/2016
 * Time: 03:24:24
 */

namespace App\Model\Globals;


class GlobalPackageResult
{

    public $model;
    public $countryCode;
    
    public function __construct()
    {
        $this->load();
    }

    private function load()
    {
        $this->model = [];
        $this->model["dimensions"]= [];
        $model = (new BabbageGlobalModelResult())->model;

        foreach ($model->dimensions as $dimension) {
            $this->model["dimensions"][$dimension->ref] = ["dimensionType"=>"location"];
            
        }

        $this->countryCode="GR";
    }

}