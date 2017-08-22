<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 16/03/2016
 * Time: 13:33:02
 */

namespace App\Model;


use App;
use Asparagus\QueryBuilder;
use Cache;
use EasyRdf_Format;
use EasyRdf_Graph;
use EasyRdf_Literal;
use EasyRdf_Resource;
use EasyRdf_Sparql_Result;
use Exception;
use InvalidArgumentException;
use League\ISO3166\ISO3166;
use Log;
use Sabre\Uri;
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
            ->selectDistinct('?dataset', '?dsd', "?distributionURL",  "(GROUP_CONCAT(distinct ?titles; separator='||') AS ?titles)",  "?org", "?contactPoint", "?formattedContactName", "?contactEmail")
            ->where("?dataset", "a", "qb:DataSet")
            ->where("?dataset","qb:structure", "?dsd" )
            ->bind("CONCAT(REPLACE(str(?dataset), '^.*(#|/)', \"\"), '__', SUBSTR(MD5(STR(?dataset)),1,5)) AS ?name")
            ->where("?dataset","<http://purl.org/dc/terms/title>" ,"?title")
            ->bind("CONCAT(LANG(?title),'=',?title) AS ?titles")
            ->optional($queryBuilder->newSubgraph()
                ->where("?dataset", "<http://data.openbudgets.eu/ontology/dsd/dimension/organization>", "?org")

            )
            ->optional($queryBuilder->newSubgraph()
                ->where("?dataset", "<http://www.w3.org/ns/dcat#distribution>", "?dist")
                ->where("?dist", "<http://www.w3.org/ns/dcat#downloadURL>", "?distributionURL")
            )
            ->optional($queryBuilder->newSubgraph()
                ->where("?dataset", "<http://www.w3.org/ns/dcat#contactPoint>", "?contactPoint")
                ->where("?contactPoint", "<http://www.w3.org/2006/vcard/ns#fn>", "?formattedContactName")
                ->where("?contactPoint", "<http://www.w3.org/2006/vcard/ns#hasEmail>", "?contactEmail")
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
            $this->model->setDistributionURL(isset($identifyQueryResult[0]["distributionURL"])?$identifyQueryResult[0]["distributionURL"]:"http://apps.openbudgets.eu/dumps");
            if(isset($identifyQueryResult[0]["org"])){
                $place = $this->recognizePlace($identifyQueryResult[0]["org"], $name);
                $this->model->setCountryCode($place["country"]);
                $this->model->setCityCode($place["city"]);
            }
            else{
                $this->model->setCountryCode(config("sparql.defaultCountryCode"));
                $this->model->setCityCode($name);
            }
            if(isset($identifyQueryResult[0]["contactPoint"])){
                $this->model->setContactEmail($identifyQueryResult[0]["contactEmail"]);
                $this->model->setContactName($identifyQueryResult[0]["formattedContactName"]);
            }



        }
        else{
            throw new InvalidArgumentException("There is no model named {$name} in the triple store.");
        }

    }


    private function recognizePlace($organizationUrl, $datasetName){

        if(!$organizationUrl) return config("sparql.defaultCountryCode");

        /** @var EasyRdf_Resource $article */
        /** @var EasyRdf_Graph $graph */
        if(Cache::has("places/{$organizationUrl}" )){
          return  Cache::get("places/{$organizationUrl}" );
        }

        $clgeo = '/http:\/\/data\.openbudgets\.eu\/resource\/codelist\/cl-geo\/(.{2})/';

        preg_match_all($clgeo, $organizationUrl, $matches, PREG_SET_ORDER, 0);


        if(isset($matches[0]) && isset($matches[0][1])){
            $countryCode = $matches[0][1];
            $cityName = $datasetName;

        }
        else{
            EasyRdf_Format::register(
                'n3',
                'Notation3',
                'http://www.w3.org/2000/10/swap/grammar/n3#',
                array(
                    'text/rdf+n3' => 1,
                ),
                array('n3')
            );
            //new App\Model\Parsers\EasyRdf_Parser_NThree();

            EasyRdf_Format::registerParser('n3', "App\\Model\\NThree");
            $availablePlaces = [$organizationUrl];
            $country = null;
            $countryName = null;
            $cityName = null;
            $visited = [];
            while(count($availablePlaces)>0){
                $placeUrl = $availablePlaces[0];
                $uri = Uri\parse($placeUrl);
                // $uri["path"] = implode("/", array_map(function($part){return urlencode($part);},explode("/",$uri["path"]))) ;
                $url =Uri\build($uri);
//dd($url);
                if(isset($visited[$placeUrl])){
                    unset($availablePlaces[0]);
                    $availablePlaces = array_values($availablePlaces);
                    continue;
                }
                try{
                    $graph = new EasyRdf_Graph(($url));
                    $graph->load();

                }catch (Exception $exception){
                    print $url;
                    print $exception->getTraceAsString();
                    continue;
                }



                /** @var EasyRdf_Resource $place */
                $place = $graph->resource($placeUrl);

                if(empty($cityName)){
                    $cityName = $place->label("en");

                    if($cityName!=null)
                        $cityName =$cityName->getValue();

                }
                if($place->hasProperty('http://dbpedia.org/ontology/country')){

                    $country = $place->get('<http://dbpedia.org/ontology/country>');

                    break;
                }

                unset($availablePlaces[0]);
                $visited [$placeUrl] = $placeUrl;
                $availablePlaces = array_values($availablePlaces);
                $possibleParentRegionPredicates = [
                    "http://www.w3.org/2002/07/owl#sameAs" => "/http:\/\/wikidata\.dbpedia\.org\/resource\/\S*/",
                    "http://dbpedia.org/ontology/wikiPageRedirects" => "/.*/"
                    //  "http://dbpedia.org/ontology/isPartOf" => "/.*/",
                    //  "http://dbpedia.org/ontology/region" => "/.*/",
                    //   "http://el.dbpedia.org/ontology/administrativeDistrict" => "/.*/",
                    //   "http://el.dbpedia.org/property/περιφερειακήΕνότητα" => "/.*/",
                ];
//dd($place);
                foreach ($possibleParentRegionPredicates as $possibleParentRegionPredicate=>$regex){
                    //  dd($place);
                    if($place->hasProperty($possibleParentRegionPredicate)){
                        /** @var EasyRdf_Resource $res */
                        $resources = $place->all("<$possibleParentRegionPredicate>");
                        // dd($resources);
                        foreach ($resources as $res) {
                            preg_match_all($regex, $res->getUri(), $matches, PREG_SET_ORDER, 0);
// Print the entire match result
                            if(!empty($matches)){
                                $availablePlaces[] = $matches[0][0];

                            }
                        }
                        //dd($availablePlaces);


                    }
                }


            }
            /** @var EasyRdf_Resource $country */
            if($country!=null){

                $re = '/(^(http(s)?(:\/\/))?(www\.)?[a-zA-Z0-9-_\.]+(\.[a-zA-Z0-9]{2,})\/)(resource)(\/\S*)/';
                $subst = '\\1data\\8.ntriples';

                $countryUrl = preg_replace($re, $subst, $country->getUri());


                $graph = new EasyRdf_Graph($countryUrl);

                $graph->load();

                $country = $graph->resource($country->getUri());
                /** @var EasyRdf_Literal $countryName */
                $countryName = $country->label("en");
                $countryName =$countryName->getValue();
            }
            $countryCode = $this->countryNameToISO3166($countryName);
        }


        $result = ["country"=>$countryCode, "city"=>strtolower($cityName)];
        Cache::forever("places/{$organizationUrl}", $result );
        return $result;

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

//echo($queryBuilder->format());die;
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
            $subSubQuery->select("?value");
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
                $resolvedLabels = $this->resolveLabels($property["labels"]);
                if(isset($resolvedLabels[""]) && $resolvedLabels[""]==="")$newDimension->setLabels([""=>$property['shortName']]);
                else   $newDimension->setLabels($resolvedLabels);

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

    }

    protected function countryNameToISO3166($country_name) {
        $items = array_filter((new ISO3166)->all(), function($item) use ($country_name){return $country_name == $item["name"];});
        $item = reset($items);
        return $item["alpha2"];

    }
}