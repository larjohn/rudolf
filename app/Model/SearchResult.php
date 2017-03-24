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
    private $query = "";
    private $size = 10000;

    public function __construct($id="", $query="", $size=10000)
    {
        parent::__construct();

        $this->query =trim($query, '"');
        //dd(empty($this->query));
        $this->size = $size;
        $this->id = trim($id, '"');;

        $this->load();


    }

    public $packages = [];

    public function load(){

        if(!empty($this->id) && Cache::has("search/{$this->id}")){
            $this->packages = Cache::get("search/{$this->id}");
            //return;
        }
        if(empty($this->id) && Cache::has("search/{$this->query}/{$this->size}")){
            $this->packages = Cache::get("search/{$this->query}/{$this->size}");
            return;
        }

        $queryBuilder = new QueryBuilder(config("sparql.prefixes"), config("sparql.excusedPrefixes"));
        $queryBuilder
            ->select("?datasetName", "?dataset"/*, "(count(distinct ?value) AS ?cardinality)"*/)

            ->where("?dataset", "a", "qb:DataSet")
            ->where("?dataset", "qb:structure", "?dsd")
            ->where("?dataset","<http://purl.org/dc/terms/title>" ,"?title")

            ->bind("CONCAT(REPLACE(str(?dataset), '^.*(#|/)', \"\"), '__', SUBSTR(MD5(STR(?dataset)),1,5)) AS ?datasetName")
            ->limit($this->size)
            ->groupBy("?datasetName", "?dataset")
            ;
        if(!empty($this->id)){
            $queryBuilder->filter("?datasetName = '{$this->id}'");
        }

        if(empty($this->id) && !empty($this->query)) {
            if(strlen($this->query)>3) $object = '"\''.$this->query.'*\'"';
            else $object = '"\''.$this->query.'\'"';


            $queryBuilder
                ->union([
                    $queryBuilder->newSubgraph()
                        ->where("?dataset", "a", "qb:DataSet")

                    ->where("?dataset","<http://purl.org/dc/terms/title>" ,"?title")
                    ->where("?title", "bif:contains", $object),
                    $queryBuilder->newSubgraph()
                        ->where("?dataset", "a", "qb:DataSet")

                    ->where("?dataset","<http://www.w3.org/2000/01/rdf-schema#label>" ,"?label")
                    ->where("?label", "bif:contains", $object),

                    ]
            );

        }



        $dataSetsResult = $this->sparql->query(
            $queryBuilder->getSPARQL()
        );



        /** @var EasyRdf_Sparql_Result $result */

        $dataSetsResult = $this->rdfResultsToArray($dataSetsResult);
     // echo      $queryBuilder->format();die;

        $packages = [];

        foreach ($dataSetsResult as $dataSetResult) {
            $dataSetName = $dataSetResult["datasetName"];
            $datasetURI = $dataSetResult["dataset"];
            $model = new BabbageModelResult($dataSetName);
            $packages[$datasetURI] = $model;
            $packages[$datasetURI]->id = $dataSetName ;
            $packages[$datasetURI]->name = $dataSetName;
            $packages[$datasetURI]->package = ["author"=>config("sparql.defaultAuthor"), "title"=>$model->getModel()->getTitle(), "countryCode"=>$model->getModel()->getCountryCode()];

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
        $globalModel->package = ["author"=>config("sparql.defaultAuthor"), "title"=>"Global dataset: All datasets combined", "countryCode"=>"EU"];
        if((empty($this->id) && empty($this->query)) || $this->id = "global" || str_contains($this->query, ["global", "Global"]))
            $this->packages[] = $globalModel;

        if(!empty($this->id)) Cache::forever("search/{$this->id}", $this->packages);
            else    Cache::forever("search/{$this->query}/{$this->size}", $this->packages);
        // dd($this->model->dimensions);



    }

}