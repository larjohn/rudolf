<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 16/03/2016
 * Time: 13:33:02
 */

namespace App\Model;


use Asparagus\QueryBuilder;
use Cache;
use EasyRdf_Sparql_Result;
use Log;

class BabbageModelResult extends SparqlModel
{
    /**
     * @var string
     */
    public $name;
    /**
     * @var string
     */
    public $status;

    /**
     * @var BabbageModel
     */
    public $model;


    public function __construct(string $name)
    {
        parent::__construct();

        $this->model = new BabbageModel();
        $this->model->fact_table = "$name";
        if(!isset($name) || $name =="")return;
        $this->identify($name);
        $this->load($name);

        $this->name = $name;
        $this->status = "ok";
    }



    public function identify($name){
        $queryBuilder = new QueryBuilder(config("sparql.prefixes"));
        $queryBuilder
            ->selectDistinct('?dataset', '?dsd',   "(GROUP_CONCAT(distinct ?titles; separator='||') AS ?titles)", "?country_code")
            ->where("?dataset", "a", "qb:DataSet")
            ->where("?dataset","qb:structure", "?dsd" )
            ->bind("CONCAT(REPLACE(str(?dataset), '^.*(#|/)', \"\"), '__', SUBSTR(MD5(STR(?dataset)),1,5)) AS ?name")
            ->where("?dataset","<http://purl.org/dc/terms/title>" ,"?title")
            ->bind("CONCAT(LANG(?title),'=',?title) AS ?titles")
            ->optional($queryBuilder->newSubgraph()
                ->where("?dataset", "<http://data.openbudgets.eu/ontology/dsd/dimension/organization>", "?org")
                ->where("?org", "<http://www.geonames.org/ontology#country>", "?country")
                ->where("?country", "skos:notation", "?country_code")
            )
            ->filter("?name = '$name'")
        ;

        /** @var EasyRdf_Sparql_Result $identifyQueryResult */
        $identifyQueryResult = $this->sparql->query(
            $queryBuilder->getSPARQL()
        );
        //echo $queryBuilder->format();die;
        /** @var EasyRdf_Sparql_Result $result */
//dd($identifyQueryResult);
        if($identifyQueryResult->count()>0){
            $identifyQueryResult = $this->rdfResultsToArray($identifyQueryResult);

            $this->model->setTitles($this->resolveLabels($identifyQueryResult[0]["titles"]));
            $this->model->setDataset($identifyQueryResult[0]["dataset"]);
            $this->model->setTitle($this->preferLabel($this->model->getTitles()));
            $this->model->setDsd($identifyQueryResult[0]["dsd"]);
            $this->model->setCountryCode(isset($identifyQueryResult[0]["country_code"])?$identifyQueryResult[0]["country_code"]:"EU");
        }

    }




    public function load($name){
        if(Cache::has($name)){

           $this->model =  Cache::get($name);
           return;
        }
        $queryBuilder = new QueryBuilder(config("sparql.prefixes"));

        $queryBuilder
            ->selectDistinct('?attribute', "(GROUP_CONCAT(distinct ?labels; separator='||') AS ?labels)", '?attachment', "(SAMPLE(?_propertyType) AS ?propertyType)", "?shortName", "?dataset")
            ->where("?dsd", 'qb:component', '?component')
            ->where("?dataset", "a", "qb:DataSet")
            ->where("?dataset","qb:structure", "?dsd" )
            ->where('?component', '?componentProperty', '?attribute')
            ->where('?componentProperty', 'rdfs:subPropertyOf', 'qb:componentProperty')
            ->optional('?attribute', 'rdfs:label', '?label')
            ->bind("REPLACE(str(?attribute), '^.*(#|/)', \"\") AS ?shortName")
            ->optional('?component', 'qb:componentAttachment', '?attachment')
            ->bind("CONCAT(REPLACE(str(?dataset), '^.*(#|/)', \"\"), '__', SUBSTR(MD5(STR(?dataset)),1,5)) AS ?name")
            ->filter("?name = '$name'")
            ->bind("CONCAT(LANG(?label),'=',?label) AS ?labels")
            ->optional($queryBuilder->newSubgraph()
                ->where("?attribute", "a", "?_propertyType")->filter("?_propertyType in (qb:CodedProperty, qb:MeasureProperty, qb:DimensionProperty)"))
/*            ->filterNotExists('?component', 'qb:componentAttachment', 'qb:DataSet')*/
            ->groupBy('?attribute',   "?shortName", "?attachment", "?dataset");
        ;

       // echo($queryBuilder->format());die;
        /** @var EasyRdf_Sparql_Result $propertiesSparqlResult */
        $propertiesSparqlResult = $this->sparql->query(
            $queryBuilder->getSPARQL()
        );
        /** @var EasyRdf_Sparql_Result $result */
        Log::info($queryBuilder->format());

        $propertiesSparqlResult = $this->rdfResultsToArray($propertiesSparqlResult);

        foreach ($propertiesSparqlResult as $property) {
            $newMeasure = new Measure();
            if(!isset($property["attribute"])||!isset($property["propertyType"]))continue;
            //var_dump($property);
            $attribute = $property["attribute"];
            $queryBuilder = new QueryBuilder(config("sparql.prefixes"));
            $subQuery = $queryBuilder->newSubquery();
            $subSubQuery = $subQuery->newSubquery();
            $subSubQuery->selectDistinct("?value");
            $subSubQuery->limit(20);

            if(isset($property["attachment"]) &&  $property["attachment"]=="qb:Slice"){
                $subSubQuery
                    ->where('?observation', 'a', 'qb:Observation')
                    ->where("?slice", "qb:observation", "?observation")
                    ->where("?observation", "qb:dataSet", "<{$property['dataset']}>")
                    ->where("?slice",  "<$attribute>", "?value");
            }
            elseif(isset($property["attachment"]) &&  $property["attachment"]=="qb:DataSet"){
                $subSubQuery
                    ->where("<{$property['dataset']}>", "<$attribute>", "?value");
                
            }
            else{
                $subSubQuery
                    ->where("?observation", "qb:dataSet", "<{$property['dataset']}>")
                    ->where("?observation", "<$attribute>" ,"?value")->where('?observation', 'a', 'qb:Observation')
                ;
            }

            if($property["propertyType"]=="qb:MeasureProperty"){


                $newMeasure->setUri($attribute);

                $newMeasure->ref = $property["shortName"];
                $newMeasure->column = $property["shortName"];// $attribute;
                $resolvedLabels = $this->resolveLabels($property["labels"]);

                if(isset($resolvedLabels[""]) && $resolvedLabels[""]==="")$newMeasure->setLabels([""=>$property['shortName']]);
                else   $newMeasure->setLabels($resolvedLabels);
                $newMeasure->label = $this->preferLabel($newMeasure->getLabels());
                $newMeasure->orig_measure = $property["shortName"];// $attribute;
                $this->model->measures[$property["shortName"]] = $newMeasure;

                foreach (Aggregate::$functions as $function) {
                    $newAggregate = new Aggregate();
                    $newAggregate->label = $newMeasure->label ;
                    $newAggregate->ref = $newMeasure->ref.'.'.$function;
                    $newAggregate->measure = $newMeasure->ref;
                    $newAggregate->function = $function;
                    $this->model->aggregates[$newAggregate->ref] = $newAggregate;

                }

            }
            else{
                $subQuery->where("?value", "?extensionProperty", "?extension");
                $subQuery->subquery($subSubQuery);
                $subQuery->selectDistinct("?extensionProperty", "?extension", "?attLang", "?dataType");
                $subQuery->bind("datatype (?extension) AS ?dataType")->bind("LANG(?extension) AS ?attLang");
                $queryBuilder->selectDistinct("?extensionProperty", "?shortName", "?dataType", "?label", "(GROUP_CONCAT(distinct ?attLang  ; separator = \"|\") AS ?attLang)")
                    ->subquery($subQuery)
                    ->where("?extensionProperty", "rdfs:label", "?label")
                    ->bind("REPLACE(str(?extensionProperty), '^.*(#|/)', \"\") AS ?shortName");
               // echo($queryBuilder->format());

                $subResult = $this->sparql->query(
                    $queryBuilder->getSPARQL()
                );
                Log::info($queryBuilder->format());

                $subResults = $this->rdfResultsToArray($subResult);
               // var_dump($property);

                $newDimension = new Dimension();
                $newDimension->setLabels($this->resolveLabels($property["labels"]));

                $newDimension->label =  $this->preferLabel($newDimension->getLabels());
                $newDimension->cardinality_class = "";// $this->getCardinality($property["cardinality"]);
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
                    $newAttribute->setLanguages( explode("|", $subResult["attLang"]));

                    $newDimension->attributes[$subResult["shortName"]] = $newAttribute;


                }
                if(!isset($newDimension->label_ref) || !isset($newDimension->key_ref)){

                    $selfAttribute = new Attribute();
                    $selfAttribute->setVirtual(true);
                    $selfAttribute->ref = $property["shortName"].".".$property["shortName"];
                    $selfAttribute->column = $attribute;
                    $selfAttribute->datatype = isset($property["dataType"])? $this->flatten_data_type($property["dataType"]):"string";
                    $selfAttribute->label = $newDimension->label;
                    $selfAttribute->orig_attribute =  /*$property["shortName"].".".*/$property["shortName"];
                    $selfAttribute->setUri($attribute);
                    $newDimension->attributes[$property["shortName"]] = $selfAttribute;

                }

                if(!isset($newDimension->key_ref)){
                    $newDimension->key_ref = $property["shortName"].".".$property["shortName"];
                    $newDimension->key_attribute = $property["shortName"];
                }

                if(!isset($newDimension->label_ref)){
                    $newDimension->label_ref = $newDimension->key_ref ;
                    $newDimension->label_attribute =   $newDimension->key_attribute ;
                }


                $this->model->dimensions[$property["shortName"]] = $newDimension;
                //bdd($newDimension);

            }

        }
        foreach ($this->model->dimensions as $dimension) {
            $newHierarchy = new Hierarchy();
            $newHierarchy->label = $dimension->label;
            $newHierarchy->ref = $dimension->ref;
            $newHierarchy->levels = [$dimension->ref];
            $dimension->hierarchy = $newHierarchy->ref;
            $this->model->hierarchies[$newHierarchy->ref] = $newHierarchy;
        }


        $datasetDimensionsQueryBuilder = new QueryBuilder(config("sparql.prefixes"));
        $datasetDimensionsQueryBuilder->selectDistinct('?attribute', '?value',  "?shortName")
            ->where("?dsd", 'qb:component', '?component')
            ->where("?dataset", "a", "qb:DataSet")
            ->where("?dataset","qb:structure", "?dsd" )
            ->where('?component', '?componentProperty', '?attribute')
            ->where('?componentProperty', 'rdfs:subPropertyOf', 'qb:componentProperty')
            ->bind("REPLACE(str(?attribute), '^.*(#|/)', \"\") AS ?shortName")
            ->where('?component', 'qb:componentAttachment', 'qb:DataSet')
            ->bind("CONCAT(REPLACE(str(?dataset), '^.*(#|/)', \"\"), '__', SUBSTR(MD5(STR(?dataset)),1,5)) AS ?name")
            ->where("?dataset", "?attribute", "?value")
            ->filter("?name = '$name'")
            ->optional($queryBuilder->newSubgraph()
                ->where("?attribute", "a", "?propertyType")->filter("?propertyType in (qb:CodedProperty, qb:MeasureProperty, qb:DimensionProperty)"));
        //->groupBy('?attribute', '?value', "?shortName");
        $dimensionsResult = $this->sparql->query(
            $datasetDimensionsQueryBuilder->getSPARQL()
        );
        $datasetDimensionsResults = $this->rdfResultsToArray($dimensionsResult);
//dd($datasetDimensionsResults);
        foreach ($datasetDimensionsResults as $datasetDimensionsResult) {
            $property = $datasetDimensionsResult["shortName"];
            $this->model->$property = $datasetDimensionsResult["value"];
            if($datasetDimensionsResult["shortName"]=="currency"){
                $currency = $this->convertCurrency($datasetDimensionsResult["value"]);
                foreach ($this->model->measures as $measure) {
                    $measure->currency = $currency;
                }
            }
        }

      //  dd($this->model);




        // dd($this->model->dimensions);
        $countAggregate = new Aggregate();
        $countAggregate->label = "Facts";
        $countAggregate->function = "count";
        $countAggregate->ref = "_count";
        $this->model->aggregates["_count"] = $countAggregate;
        Cache::forget($name);
        Cache::forever($name, $this->model);

    }

    /**
     * @return BabbageModel
     */
    public function getModel()
    {

        return $this->model;
    }

    private function getCardinality($cardinality)
    {
        if($cardinality>1000)
            return 'high';
        elseif($cardinality>50)
            return "medium";
        elseif ($cardinality>7)
            return "low";
        else
            return "tiny";


    }

    public function resolveLabels($concat){

        $re = '/(\w{1,6})?=([[:punct:]\w\s]*)(\|\||$)/uU';
        $matches = [];
        preg_match_all($re, $concat, $matches);
        $dictionary = array_combine($matches[1], $matches[2]);
        return $dictionary;

    }

    public function preferLabel($labels){
        $preferredLanguages = config("sparql.languagesOrder", ["en"]);
        if(count($labels)<1) return "";
        foreach ($preferredLanguages as $preferredLanguage) {
            if(isset($labels[$preferredLanguage])) return $labels[$preferredLanguage];
        }
        return reset($labels);

    }

    protected function convertCurrency($value)
    {
        $value = str_replace("http://data.openbudgets.eu/resource/codelist/currency/", "", $value );
        return str_replace("http://data.openbudgets.eu/codelist/currency/", "", $value );

        switch ($value){
            case "http://data.openbudgets.eu/resource/codelist/currency/EUR":
                return "EUR";
        }
    }

}