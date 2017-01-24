<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 14/04/2016
 * Time: 02:38:47
 */

namespace App\Model;


use App\Model\Globals\BabbageGlobalModelResult;
use Asparagus\QueryBuilder;
use Cache;
use EasyRdf_Sparql_Result;
use Log;

class SearchResult extends SparqlModel
{

    public function __construct()
    {
        parent::__construct();

        $this->load();


    }

    public $packages = [];

    public function load(){

        if(Cache::has("search")){
            $this->packages = Cache::get("search");
           return;
        }

        $queryBuilder = new QueryBuilder(config("sparql.prefixes"));
        $queryBuilder
            ->select("?datasetName", "?dataset"/*, "(count(distinct ?value) AS ?cardinality)"*/)

            ->where("?dataset", "a", "qb:DataSet")
            ->where("?dataset", "qb:structure", "?dsd")
            ->where("?dataset","<http://purl.org/dc/terms/title>" ,"?title")

            ->bind("CONCAT(REPLACE(str(?dataset), '^.*(#|/)', \"\"), '__', SUBSTR(MD5(STR(?dataset)),1,5)) AS ?datasetName")
            ->groupBy("?datasetName", "?dataset")
            ;

        $dataSetsResult = $this->sparql->query(
            $queryBuilder->getSPARQL()
        );


        /** @var EasyRdf_Sparql_Result $result */

        $dataSetsResult = $this->rdfResultsToArray($dataSetsResult);
        //dd($propertiesSparqlResult);
        $packages = [];

        foreach ($dataSetsResult as $dataSetResult) {
            $dataSetName = $dataSetResult["datasetName"];
            $datasetURI = $dataSetResult["dataset"];
            $model = new BabbageModelResult($dataSetName);
            $packages[$datasetURI] = $model;
            $packages[$datasetURI]->id = $dataSetName ;
            $packages[$datasetURI]->name = $dataSetName;
            $packages[$datasetURI]->package = ["author"=>"Place Holder <place.holder@not.shown>", "title"=>$model->getModel()->getTitle(), "countryCode"=>"EU"];




            //dd($dataSetResult["datasetName"]);
        }





        $this->packages = array_values($packages);
       // dd($this->packages);

        foreach ($this->packages as $packageName=>$package) {
            foreach ($package->model->dimensions as $dimension) {
                $newHierarchy = new Hierarchy();
                $newHierarchy->label = $dimension->label;
                $newHierarchy->ref = $dimension->ref;
                $newHierarchy->levels = [$dimension->ref];
                $dimension->hierarchy = $newHierarchy->ref;
                $package->model->hierarchies[$newHierarchy->ref] = $newHierarchy;
            }

            $countAggregate = new Aggregate();
            $countAggregate->label = "Facts";
            $countAggregate->function = "count";
            $countAggregate->ref = "_count";
            $package->model->aggregates["_count"] = $countAggregate;
            $package->origin_url = "http://openbudgets.eu";
        }


        $globalModel = new BabbageGlobalModelResult();
        $globalModel->id = "global";
        $globalModel->load2();
        $globalModel->package = ["author"=>"Place Holder <place.holder@not.shown>", "title"=>"Global: The mother of all Cubes", "countryCode"=>"EU"];

        $this->packages[] = $globalModel;
        Cache::forever("search", $this->packages);
        // dd($this->model->dimensions);



    }

}