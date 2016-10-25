<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 13/05/2016
 * Time: 03:24:24
 */

namespace App\Model\Globals;


use App\Model\SparqlModel;

class GlobalPackageResult extends SparqlModel
{

    public $model;
    public $countryCode;

    public function __construct()
    {
        parent::__construct();


        $this->load();
    }

    private function load()
    {

        $model = (new BabbageGlobalModelResult())->model;
       // dd($model->dimensions["global__fiscalPeriod__28951"]->getInnerDimensions());

        foreach ($model->dimensions as $dimension) {
            $newDimension = [];
            if ( strpos($dimension->ref, 'fiscalYear') !== false  ||  strpos($dimension->ref, 'fiscalPeriod') !== false  ) {
                $newDimension["dimensionType"] = "datetime";


            } elseif ( strpos($dimension->ref, 'organization') !== false ||  strpos($dimension->ref, 'budgetaryUnit')!== false ) {
                $newDimension["dimensionType"] = "location";

            } else {
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
        $this->name = "global";
        $this->title = "Global dataset";


    }

}