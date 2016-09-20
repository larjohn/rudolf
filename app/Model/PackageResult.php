<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 07/04/2016
 * Time: 11:12:43
 */

namespace App\Model;



class PackageResult extends SparqlModel
{


    public $origin_url;

    public $author;

    public $countryCode = "EU";

    public $model = [];
    public $name;

    public function __construct($name)
    {
        parent::__construct();

        $model = (new BabbageModelResult($name))->model;
        //dd($model);

        foreach ($model->dimensions as $dimension) {
            $newDimension = [];
            if($dimension->ref=="fiscalYear"||$dimension->ref=="fiscalPeriod"){
                $newDimension["dimensionType"] = "datetime";



            }elseif($dimension->ref=="organization"||$dimension->ref=="budgetaryUnit"){
                $newDimension["dimensionType"] = "location";

            }
            else{
                $newDimension["dimensionType"] = "classification";

            }
            $this->model["dimensions"][$dimension->ref] = $newDimension;
        }
        foreach ($model->measures as $measure) {
            $newMeasure = [];
            $newMeasure["currency"] = $measure->currency;
            $newMeasure["title"] = $measure->label;
            $this->model["measures"][$measure->ref] = $newMeasure;
        }
        $this->name = $name;


        
        
        

    }


}