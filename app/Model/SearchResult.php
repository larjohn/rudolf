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

        $queryBuilder = new QueryBuilder(config("sparql.prefixes"));
        $queryBuilder
            ->selectDistinct('?attribute', '?label', '?attachment', "?propertyType", "?shortName", "?dataset", "?name"/*, "(count(distinct ?value) AS ?cardinality)"*/)
            ->where("?dsd", 'qb:component', '?component')
            ->where("?dataset", "a", "qb:DataSet")
            ->where("?dataset","qb:structure", "?dsd" )
            ->where('?component', '?componentProperty', '?attribute')
            ->where('?componentProperty', 'rdfs:subPropertyOf', 'qb:componentProperty')
            ->optional('?attribute', 'rdfs:label', '?label')
            ->bind("REPLACE(str(?attribute), '^.*(#|/)', \"\") AS ?shortName")
            ->optional('?component', 'qb:componentAttachment', '?attachment')
            ->bind("CONCAT(REPLACE(str(?dataset), '^.*(#|/)', \"\"), '__', SUBSTR(MD5(STR(?dataset)),1,5)) AS ?name")
            //   ->filter("?name = '$name'")

            ->optional($queryBuilder->newSubgraph()
                ->where("?attribute", "a", "?propertyType")->filter("?propertyType in (qb:CodedProperty, qb:MeasureProperty, qb:DimensionProperty)"))
            ->filterNotExists('?component', 'qb:componentAttachment', 'qb:DataSet')
            ->groupBy('?attribute', '?label', "?propertyType", "?shortName", "?attachment", "?dataset", "?name");
        ;
        //echo($queryBuilder->format());die;
        /** @var EasyRdf_Sparql_Result $propertiesSparqlResult */
        $propertiesSparqlResult = $this->sparql->query(
            $queryBuilder->getSPARQL()
        );


        /** @var EasyRdf_Sparql_Result $result */

        $propertiesSparqlResult = $this->rdfResultsToArray($propertiesSparqlResult);
        //dd($propertiesSparqlResult);
        $packages = [];
        foreach ($propertiesSparqlResult as $property) {
            if(!isset($property["attribute"])||!isset($property["propertyType"]))continue;

            if(!isset($packages[$property["dataset"]])){
                $packages[$property["dataset"]] = new BabbageModelResult("");
                $packages[$property["dataset"]]->id = preg_replace("/^.*(#|\/)/", "", $property["dataset"])."__" . substr(md5($property["dataset"]),0,5) ;
                $packages[$property["dataset"]]->package = ["author"=>"Place Holder <place.holder@not.shown>", "title"=>$property["name"]];
            }
            $attribute = $property["attribute"];
            $queryBuilder = new QueryBuilder(config("sparql.prefixes"));
            $subQuery = $queryBuilder->newSubquery();
            $subSubQuery = $subQuery->newSubquery();
            $subSubQuery->where('?observation', 'a', 'qb:Observation');
            $subSubQuery->select("?value");
            $subSubQuery->limit(1);

            if(isset($property["attachment"]) &&  $property["attachment"]=="qb:Slice"){
                $subSubQuery
                    ->where("?slice", "qb:observation", "?observation")
                    ->where("?slice",  "<$attribute>", "?value");
            }
            else{
                $subSubQuery->where("?observation", "<$attribute>" ,"?value");
            }

            if($property["propertyType"]=="qb:MeasureProperty"){
                if(Cache::has($property["dataset"]."/".$property["shortName"])){
                    $packages[$property["dataset"]]->model->dimensions[$property["shortName"]] = Cache::get($property["dataset"]."/".$property["shortName"]);

                }
                else{
                    $newMeasure = new Measure();

                    $queryBuilder->selectDistinct("?dataType")
                        ->bind("datatype(?value) AS ?dataType");

                    /** @var EasyRdf_Sparql_Result $subResult */
                    $subResult = $this->sparql->query(
                        $queryBuilder->getSPARQL()
                    );
                    /** @var EasyRdf_Sparql_Result $result */
                    Log::info($subSubQuery->format());

                    $subResults = $this->rdfResultsToArray($subResult);
                    $newMeasure->setUri($attribute);

                    $newMeasure->ref = $property["shortName"];
                    $newMeasure->column = $property["shortName"];// $attribute;
                    $newMeasure->label = $property["label"];
                    $newMeasure->orig_measure = $property["shortName"];;// $attribute;
                    $packages[$property["dataset"]]->model->measures[$property["shortName"]] = $newMeasure;

                    foreach (Aggregate::$functions as $function) {
                        $newAggregate = new Aggregate();
                        $newAggregate->label = $newMeasure->ref . ' ' . $function;
                        $newAggregate->ref = $newMeasure->ref.'.'.$function;
                        $newAggregate->measure = $newMeasure->ref;
                        $newAggregate->function = $function;
                        $packages[$property["dataset"]]->model->aggregates[$newAggregate->ref] = $newAggregate;

                    }


                    Cache::forever($property["dataset"]."/".$property["shortName"], $newMeasure);

                }

            }
            else{

                if(Cache::has($property["dataset"]."/".$property["shortName"])){
                    $packages[$property["dataset"]]->model->dimensions[$property["shortName"]] = Cache::get($property["dataset"]."/".$property["shortName"]);
                }
                else{
                    $subQuery->where("?value", "?extensionProperty", "?extension");
                    $subQuery->subquery($subSubQuery);
                    $subQuery->selectDistinct("?extensionProperty", "?extension");
                    $queryBuilder->selectDistinct("?extensionProperty", "?shortName", "?dataType", "?label")
                        ->subquery($subQuery)
                        ->where("?extensionProperty", "rdfs:label", "?label")
                        ->bind("datatype(?extension) AS ?dataType")
                        ->bind("REPLACE(str(?extensionProperty), '^.*(#|/)', \"\") AS ?shortName");

                    $subResult = $this->sparql->query(
                        $queryBuilder->getSPARQL()
                    );
                    $subResults = $this->rdfResultsToArray($subResult);
                    // var_dump($property);
                    //echo($queryBuilder->format());

                    $newDimension = new Dimension();
                    $newDimension->label =  $property["label"];
                    //$newDimension->cardinality_class = $this->getCardinality($property["cardinality"]);
                    $newDimension->ref= $property["shortName"];
                    $newDimension->orig_dimension= $property["shortName"];
                    $newDimension->setUri($attribute);
                    if(isset($property["attachment"]))
                        $newDimension->setAttachment($property["attachment"]);



                    foreach ($subResults as $subResult) {
                        // dd($subResults);
                        // if(!isset($subResult["dataType"]))continue;

                        $newAttribute = new Attribute();
                        if($subResult["extensionProperty"] == "skos:prefLabel"){
                            $newDimension->label_ref =  $property["shortName"].".".$subResult["shortName"];
                            $newDimension->label_attribute = $subResult["shortName"];

                        }
                        if($subResult["extensionProperty"] == "skos:notation"){
                            $newDimension->key_ref =  $property["shortName"].".".$subResult["shortName"];
                            $newDimension->key_attribute = $subResult["shortName"];

                        }

                        $newAttribute->ref = $property["shortName"].".".$subResult["shortName"];
                        $newAttribute->column = $subResult["extensionProperty"];
                        $newAttribute->datatype = isset($subResult["dataType"])?$this->flatten_data_type($subResult["dataType"]):"string";
                        $newAttribute->setUri($subResult["extensionProperty"]);
                        $newAttribute->label = $subResult["label"];
                        $newAttribute->orig_attribute = /*$property["shortName"].".".*/$subResult["shortName"];

                        $newDimension->attributes[$subResult["shortName"]] = $newAttribute;


                    }
                    if(!isset($newDimension->label_ref) || !isset($newDimension->key_ref)){

                        $selfAttribute = new Attribute();

                        $selfAttribute->ref = $property["shortName"].".".$property["shortName"];
                        $selfAttribute->column = $attribute;
                        $selfAttribute->datatype = isset($property["dataType"])? $this->flatten_data_type($property["dataType"]):"string";
                        $selfAttribute->label = $property["label"];
                        $selfAttribute->orig_attribute =  /*$property["shortName"].".".*/$property["shortName"];
                        $selfAttribute->setUri($attribute);
                        $newDimension->attributes[$property["shortName"]] = $selfAttribute;

                    }
                    if(!isset($newDimension->label_ref)){
                        $newDimension->label_ref = $property["shortName"].".".$property["shortName"];
                        $newDimension->label_attribute = $property["shortName"];
                    }

                    if(!isset($newDimension->key_ref)){
                        $newDimension->key_ref = $property["shortName"].".".$property["shortName"];
                        $newDimension->key_attribute = $property["shortName"];
                    }

                    $packages[$property["dataset"]]->model->dimensions[$property["shortName"]] = $newDimension;
                    Cache::forever($property["dataset"]."/".$property["shortName"], $newDimension);
                }


                //dd($newDimension);

            }

        }

        $this->packages = array_values($packages);
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
        $globalModel->package = ["author"=>"Place Holder <place.holder@not.shown>", "title"=>"global", "countryCode"=>"GR"];

        $this->packages[] = $globalModel;

        // dd($this->model->dimensions);



    }

}