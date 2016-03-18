<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 24/02/2016
 * Time: 19:21:25
 */

namespace App\Http\Controllers;


use App\Model\Sparql\SubPattern;
use App\Model\Sparql\TriplePattern;
use Asparagus\QueryBuilder;
use EasyRdf_Literal;
use EasyRdf_Literal_Decimal;
use EasyRdf_Literal_Integer;
use EasyRdf_Sparql_Client;
use EasyRdf_Sparql_Result;

class StartController extends Controller
{


      public function datasets()
    {
        $queryBuilder = new QueryBuilder( config("sparql.prefixes"));
        $queryBuilder->select('?dataset', '?label', '?dsd')
            ->where('?dataset', 'a', 'qb:DataSet')
            ->where('?dataset', 'rdfs:label', '?label')
            ->where('?dataset', 'qb:structure', '?dsd')
            ->limit(10);

        //   echo $queryBuilder->format();
        /** @var EasyRdf_Sparql_Result $result */
        $result = $this->sparql->query(
            $queryBuilder->getSPARQL()
        );

        $results = $this->rdfResultsToArray($result);
        return $results;
    }

    public function getObservations()
    {
        $dataset = request("dataset");

        $queryBuilder = new QueryBuilder(config("sparql.prefixes"));
        $queryBuilder->selectDistinct('?observation','?attribute', '?value' , '?label')

            ->subquery($queryBuilder->newSubquery()->selectDistinct("?observation")->where('?observation', 'a', 'qb:Observation')
                ->where('?observation', 'qb:dataSet', "<$dataset>")
                ->limit(100 ))
            ->where('?attribute', 'a', 'qb:ComponentProperty')
            ->where('?component', 'qb:componentProperty', '?attribute')
            ->where("?dsd", 'qb:component', '?component')

            ->filterNotExists('?component', 'qb:componentAttachment', 'qb:DataSet')
            ->orderBy("?observation", "DESC")
            ->union(
                $queryBuilder->newSubgraph()
                    ->where('?observation', '?attribute', "?value")->optional("?value", 'skos:prefLabel', '?label')
                ,
                $queryBuilder->newSubgraph()
                    ->where("?slice", "a", "qb:Slice")
                    ->where("?slice", "qb:observation", "?observation")
                    ->where("?slice", "?attribute", "?value")->optional("?value", 'skos:prefLabel', '?label')

            )->orderBy("?observation");


         //echo($queryBuilder->format());
        /** @var EasyRdf_Sparql_Result $result */
        $result = $this->sparql->query(
            $queryBuilder->getSPARQL()
        );
        //echo($result->dump());

        $results = $this->rdfResultsToArray($result);

        $mappings = ["attribute"=>["value", "label"]];

        $results = $this->orthogonize($results, "observation", $mappings);


        return $results;
    }


    /**
     * @param QueryBuilder $queryBuilder
     * @param array $bindings
     * @param array $filters
     * @return QueryBuilder
     */
    private function build(array $bindings, array $filters){
        $queryBuilder = new QueryBuilder(config("sparql.prefixes"));

        foreach ($filters as $filter) {
            if($filter instanceof TriplePattern || ($filter instanceof SubPattern && !$filter->isOptional)){
                if($filter->isOptional){
                    $queryBuilder->optional($filter->subject, $filter->predicate, $filter->object);
                }
                else{
                    $queryBuilder->where($filter->subject, $filter->predicate, $filter->object);
                }
            }
            elseif($filter instanceof SubPattern){
                $subGraph = $queryBuilder->newSubgraph();

                foreach($filter->patterns as $pattern){

                    if($pattern->isOptional){
                        $subGraph->optional($pattern->subject, $pattern->predicate, $pattern->object);
                    }
                    else{
                        $subGraph->where($pattern->subject, $pattern->predicate, $pattern->object);
                    }
                }

                $queryBuilder->optional($subGraph);


            }
        }

        $queryBuilder
            ->selectDistinct($bindings)

        ;


        return $queryBuilder;

    }

    private function friendlyName($uri){
        switch($uri){
           /* case 'http://data.openbudgets.eu/ontology/dsd/measure/amount': return 'total_amount';
            case "http://data.openbudgets.eu/ontology/dsd/greek-municipalities/dimension/budgetPhase": return 'phase';*/
            default: return $uri;
        }
    }


    public function getObservations3($alias){
        if($alias == "athens2013"){
            $dsd = "http://data.openbudgets.eu/ontology/dsd/budget-athens-expenditure-2013";
            $dataset = "http://data.openbudgets.eu/resource/dataset/budget-athens-expenditure-2013";
        }
        $model = $this->getDimensions2($alias);
        $fields = explode(',', request('fields'));
        $facts = $this->getObservations2($dataset,$dsd, $fields, $model );
        return $facts;
    }

    private function modelFieldsToPatterns($model, $fields){
       // dd($model);

        $selectedDimensions = [];

        foreach ($fields as $field) {
            $fieldNames = explode(".", $field);
            foreach ($model["model"]["measures"] as $name =>$attribute) {
                if($fieldNames[0] == $name){
                    $selectedDimensions[$attribute["column"]] = [];
                    $currentAttribute = $attribute;
                    $currentSelectedDimension = &$selectedDimensions[$attribute["column"]];
                    for ($i=1;$i<count($fieldNames);$i++){
                        foreach ($currentAttribute["attributes"] as $innerAttributeName=>$innerAttribute) {
                            if($fieldNames[$i]==$fieldNames[$i-1])continue;
                            if($fieldNames[$i]==$innerAttributeName) {
                                $currentSelectedDimension[$innerAttribute["column"]] = [];
                                $currentSelectedDimension = &$currentSelectedDimension[$innerAttribute["column"]];
                                $currentAttribute = $innerAttribute;
                                break;

                            }
                        }
                    }

                }

            }

            foreach ($model["model"]["dimensions"] as $name =>$attribute) {
                //var_dump($fieldNames);
                if($fieldNames[0] == $name){
                    $selectedDimensions[$attribute["column"]] = [];
                    $currentAttribute = $attribute;
                    $currentSelectedDimension = &$selectedDimensions[$attribute["column"]];
                    for ($i=1;$i<count($fieldNames);$i++){
                        foreach ($currentAttribute["attributes"] as $innerAttributeName=>$innerAttribute) {
                            if($fieldNames[$i]==$fieldNames[$i-1])continue;
                            if($fieldNames[$i]==$innerAttributeName) {
                                $currentSelectedDimension[$innerAttribute["column"]] = [];
                                $currentSelectedDimension = &$currentSelectedDimension[$innerAttribute["column"]];
                                $currentAttribute = $innerAttribute;
                                break;

                            }
                        }
                    }

                }

            }

        }
        return ($selectedDimensions);
    }

    public function getObservations2($dataset, $dsd, $fields, $model)
    {

        $selectedPatterns = $this->modelFieldsToPatterns($model,$fields);
        $datasetStructureDefinitionUri = $dsd;
        $pageSize = intval(request("pagesize", 100));
        $page = intval(request("page", 0));
        $offset = $pageSize * $page ;
        $dimensions = $this->getDimensions($datasetStructureDefinitionUri);

        $attributes = [];
        $bindings = [];
        $patterns = [];

        $selectedDimensions= [];
        
        foreach ($dimensions as $dimension) {
            if(!isset($selectedPatterns[$dimension["attribute"]])) continue;
            $selectedDimensions[] = $dimension;
            $bindingName = "binding_" . md5($dimension["attribute"]);
            $valueAttributeLabel = "uri";
            if($dimension["propertyType"]=="qb:MeasureProperty"){
                $valueAttributeLabel = "value";
            }
            $attributes[$dimension["attribute"]][$valueAttributeLabel] = $bindingName;
            $bindings[$dimension["attribute"]] = "?$bindingName";
        }



        $sliceSubGraph = new SubPattern([
            new TriplePattern("?slice", "a", "qb:Slice"),
            new TriplePattern("?slice", "qb:observation", "?observation"),

        ], true);



/*        $queryBuilder->newSubgraph()
            ->where("?slice", "a", "qb:Slice")
            ->where("?slice", "qb:observation", "?observation");*/

        $needsSliceSubGraph = false;

        foreach ($selectedDimensions as $dimension) {
            $attribute = $dimension["attribute"];

            if(isset($dimension["attachment"]) && $dimension["attachment"]=="qb:Slice"){
                $needsSliceSubGraph = true;
                $sliceSubGraph->add(new TriplePattern("?slice", "<$attribute>", $bindings[$attribute] , true));
            }
            else{
                $patterns [] = new TriplePattern("?observation", "<$attribute>", $bindings[$attribute], true);
            }

           if($dimension["propertyType"]=="qb:CodedProperty"){
               $dimensionPatterns = &$selectedPatterns[$attribute];
               foreach ($dimensionPatterns as $patternName=>$dimensionPattern){
                   $attributes[$attribute][$patternName] = $attributes[$attribute]["uri"]."_".md5($patternName) ;
                   $bindings[] = $bindings[$attribute]."_".md5($patternName) ;
                  // dd( $attributes[$attribute]["patternName"]);
                   if(isset($dimension["attachment"]) && $dimension["attachment"]=="qb:Slice"){
                       $sliceSubGraph->add(new TriplePattern($bindings[$attribute],$patternName,$bindings[$attribute]."_".md5($patternName), true));
                   }
                   else{
                       $patterns [] = new TriplePattern($bindings[$attribute],$patternName,$bindings[$attribute]."_".md5($patternName), true);


                   }


               }


            }
        }

       // echo($queryBuilder->format());
        $bindings[] = "?observation";

        if($needsSliceSubGraph){
            $patterns[] = $sliceSubGraph;

        }

        $patterns[] = new TriplePattern('?observation', 'a', 'qb:Observation');
        $patterns[] = new TriplePattern('?observation', 'qb:dataSet', "<$dataset>");


        $queryBuilderC = $this->build(["(count(?observation) as ?observation)"], $patterns );
        /** @var EasyRdf_Sparql_Result $countResult */
        $countResult = $this->sparql->query(
            $queryBuilderC->getSPARQL()
        );
        $count = $countResult[0]->observation->getValue();
        $queryBuilder = $this->build($bindings, $patterns );



        $queryBuilder

            ->limit($pageSize )
            ->offset($offset)
            ->orderBy("?observation");


      //dd($queryBuilder->getSPARQL());
       // die;
        /** @var EasyRdf_Sparql_Result $result */
        $result = $this->sparql->query(
            $queryBuilder->getSPARQL()
        );
        //echo($result->dump());

        //dd($attributes);

        $results = $this->rdfResultsToArray3($result,$attributes, $model, $selectedPatterns);


        return [
            "data"=>$results,
            "total_fact_count" => $count,
            "page_size" => $pageSize,
            "cell" => [],
            "page" => $page,
            "fields" => $fields,
            "status" => "ok",
            "order" => []
        ];
    }


    public function getObservationDimensions()
    {
        $datasetStructureDefinitionUri = request("dsd");

        $results = $this->getDimensions($datasetStructureDefinitionUri);
        return $results;
    }

    public function getObservationDimensions2($dataset)
    {

        $results = $this->getDimensions2($dataset);
        return $results;
    }

    private function getDimensions($dsd){
        $queryBuilder = new QueryBuilder(config("sparql.prefixes"));
        $queryBuilder->selectDistinct('?attribute', '?label', '?attachment', "?propertyType")
            ->where("<$dsd>", 'qb:component', '?component')

            ->where('?component', 'qb:componentProperty', '?attribute')
            ->optional('?attribute', 'rdfs:label', '?label')
            ->optional('?component', 'qb:componentAttachment', '?attachment')
            ->optional($queryBuilder->newSubgraph()->where("?attribute", "a", "?propertyType")->filter("?propertyType in (qb:CodedProperty, qb:MeasureProperty)"))
            ->filterNotExists('?component', 'qb:componentAttachment', 'qb:DataSet');

        //dd($queryBuilder->getSPARQL());
        $result = $this->sparql->query(
            $queryBuilder->getSPARQL()
        );
        /** @var EasyRdf_Sparql_Result $result */

        $results = $this->rdfResultsToArray($result);
        return $results;
    }

    private function getDimensions2($dataset){
        if($dataset == "athens2013"){
            $dsd = "http://data.openbudgets.eu/ontology/dsd/budget-athens-expenditure-2013";
        }
        $queryBuilder = new QueryBuilder(config("sparql.prefixes"));
        $queryBuilder
            ->selectDistinct('?attribute', '?label', '(max(?attachment) as ?attachment)', "?propertyType", "?shortName", "(count(distinct ?value) AS ?cardinality)")
            ->where("<$dsd>", 'qb:component', '?component')

            ->where('?component', 'qb:componentProperty', '?attribute')
            ->optional('?attribute', 'rdfs:label', '?label')
            ->bind("REPLACE(str(?attribute), '^.*(#|/)', \"\") AS ?shortName")
            ->optional('?component', 'qb:componentAttachment', '?attachment')
            ->optional($queryBuilder->newSubgraph()
                ->where("?slice", "qb:observation", "?observation")
                ->where("?slice",  "?attribute", "?value"))
            ->optional("?observation", "?attribute" ,"?value")

            ->optional($queryBuilder->newSubgraph()
                ->where("?attribute", "a", "?propertyType")->filter("?propertyType in (qb:CodedProperty, qb:MeasureProperty)"))
            ->filterNotExists('?component', 'qb:componentAttachment', 'qb:DataSet')
            ->groupBy('?attribute', '?label', "?propertyType", "?shortName");
            ;

//        dd($queryBuilder->getSPARQL());
        $propertiesSparqlResult = $this->sparql->query(
            $queryBuilder->getSPARQL()
        );

        /** @var EasyRdf_Sparql_Result $result */

        $propertiesSparqlResult = $this->rdfResultsToArray($propertiesSparqlResult);
        //dd($propertiesSparqlResult);
        $allDimensions = [];
        $allMeasures = [];
        foreach ($propertiesSparqlResult as $property) {
            $attribute = $property["attribute"];
            $queryBuilder = new QueryBuilder(config("sparql.prefixes"));
            $queryBuilder->where('?observation', 'a', 'qb:Observation');

            if(isset($property["attachment"]) &&  $property["attachment"]=="qb:Slice"){
                $queryBuilder
                    ->where("?slice", "qb:observation", "?observation")
                    ->where("?slice",  "<$attribute>", "?value");
            }
            else{
                $queryBuilder->where("?observation", "<$attribute>" ,"?value");

            }
            if($property["propertyType"]=="qb:MeasureProperty"){

                $queryBuilder->selectDistinct("?dataType")
                    ->bind("datatype(?value) AS ?dataType");

                $subresult = $this->sparql->query(
                    $queryBuilder->getSPARQL()
                );
                /** @var EasyRdf_Sparql_Result $result */

                $subresults = $this->rdfResultsToArray($subresult);

                $allMeasures[$property["shortName"]] = [
                    "ref" => $property["shortName"],
                    "column" => $attribute,
                    "type" => isset($subresults[0]["dataType"])?$this->flatten_data_type($subresults[0]["dataType"]):"",
                    "label"=>$property["label"],
                    "description"=>""
                ];
            }
            else{



                $queryBuilder->selectDistinct("?extensionProperty", "?shortName", "?dataType", "?label")
                    ->where('?observation', 'a', 'qb:Observation')
                    ->where("?value", "?extensionProperty", "?extension")
                    ->where("?extensionProperty", "rdfs:label", "?label")
                    ->bind("datatype(?extension) AS ?dataType")
                    ->bind("REPLACE(str(?extensionProperty), '^.*(#|/)', \"\") AS ?shortName");

                $subresult = $this->sparql->query(
                    $queryBuilder->getSPARQL()
                );
               //dd($queryBuilder->getSPARQL());
                /** @var EasyRdf_Sparql_Result $result */

                $subresults = $this->rdfResultsToArray($subresult);
                $allDimensions[$property["shortName"]] = [
                    "ref"=>$property["shortName"],
                    "attributes"=> []
                ];
                $allDimensions[$property["shortName"]]["column"] = $attribute;
                $allDimensions[$property["shortName"]]["facet"] = false;
                $allDimensions[$property["shortName"]]["description"] = "";
                $allDimensions[$property["shortName"]]["label"] = $property["label"];
                $allDimensions[$property["shortName"]]["description"] = "";
                $allDimensions[$property["shortName"]]["cardinality"] = $property["cardinality"];
                $allDimensions[$property["shortName"]]["cardinality_class"] = "someClass";


                foreach ($subresults as $subresult) {
                    //dd($subresult);
                    if(!isset($subresult["dataType"]))continue;
                    if($subresult["extensionProperty"] == "skos:prefLabel"){
                        $allDimensions[$property["shortName"]]["label_ref"] =  $property["shortName"].".".$subresult["shortName"];
                        $allDimensions[$property["shortName"]]["label_attribute"] =  $subresult["shortName"];

                    }
                    if($subresult["extensionProperty"] == "skos:notation"){
                        $allDimensions[$property["shortName"]]["key_ref"] =  $property["shortName"].".".$subresult["shortName"];
                        $allDimensions[$property["shortName"]]["key_attribute"] =  $subresult["shortName"];

                    }
                    //dd($subresult);
                    $allDimensions[$property["shortName"]]["attributes"][$subresult["shortName"]] = [
                        "ref" => $property["shortName"].".".$subresult["shortName"],
                        "column" => $subresult["extensionProperty"],
                        "type" => isset($subresult["dataType"])?$this->flatten_data_type($subresult["dataType"]):"",
                        "label"=>$subresult["label"],
                        "description"=>""
                    ];
                }
                if(!isset($allDimensions[$property["shortName"]]["label_ref"]) || !isset($allDimensions[$property["shortName"]]["key_ref"])){
                    $allDimensions[$property["shortName"]]["attributes"][$property["shortName"]] = [
                        "ref" => $property["shortName"].".".$property["shortName"],
                        "column" => $attribute,
                        "type" => isset($property["dataType"])? $this->flatten_data_type($property["dataType"]):"",
                        "label"=>$property["label"],
                        "description"=>""
                    ];

                }
                if(!isset($allDimensions[$property["shortName"]]["label_ref"])){
                    $allDimensions[$property["shortName"]]["label_ref"] =  $property["shortName"].".".$property["shortName"];
                    $allDimensions[$property["shortName"]]["label_attribute"] =  $property["shortName"];

                }
                if(!isset($allDimensions[$property["shortName"]]["key_ref"])){
                    $allDimensions[$property["shortName"]]["key_ref"] =  $property["shortName"].".".$property["shortName"];
                    $allDimensions[$property["shortName"]]["key_attribute"] =  $property["shortName"];

                }
            }



        }


        return["name"=>$dsd, "status"=>"ok","model" =>["dimensions"=>$allDimensions, "measures"=>$allMeasures, "aggregates"=>[]]];
    }


  


    private function rdfResultsToArray2(EasyRdf_Sparql_Result $result, array $attributes)
    {
        $results = [];
        foreach ($result as $row) {
            $added = [];
            foreach ($attributes as $attribute=>$fields) {
                $attribute = $this->friendlyName($attribute);
                $added [$attribute]= [];

                foreach ($fields as $field=>$binding) {

                    if(!isset($row->$binding))continue;
                    $value = $row->$binding;

                    $val = $value;

                    if ($value instanceof EasyRdf_Literal ) {
                        /** @var EasyRdf_Literal $value */
                        $val  = $value->getValue();
                        if($value instanceof EasyRdf_Literal_Decimal)
                            $val = floatval($val);
                        elseif($value instanceof EasyRdf_Literal_Integer)
                            $val = intval($val);


                    } else {
                        /** @var \EasyRdf_Resource $value */
                        $val = $value->dumpValue('text');
                    }

                    if($field == "value"){
                        $added[$attribute] = $val;
                    }else{
                        $added[$attribute][$field] = $val;
                    }

                }
            }


            $results[] = $added;
        }
        return $results;
    }


    private function getAttributeRef(array $model, array $path){
        foreach ($model["model"]["dimensions"] as $dimensionName => $dimension){
            if($path[0]==$dimension["column"]){
                if(count($path)>1){
                    foreach ($dimension["attributes"] as $attribute){
                        if($attribute["column"] == $path[1]){
                            return $attribute["ref"];
                        }
                    }
                }
                else{
                    return $dimensionName.".".$dimensionName;
                }
            }
        }

        foreach ($model["model"]["measures"] as $measureName => $measure){
            if($measure["column"] == $path[0]){
                return $measure["ref"];
            }
        }

    }


    private function rdfResultsToArray3(EasyRdf_Sparql_Result $result, array $attributes, array $model, array $selectedFields)
    {


        $results = [];
        foreach ($result as $row) {
            $added = [];

            foreach ($selectedFields as  $selectedFieldName=>$selectedField) {
                if(count($selectedField)<1) {
                    if (isset($attributes[$selectedFieldName]["value"])) {
                        $selectedBinding = $attributes[$selectedFieldName]["value"];
                    } else {
                        $selectedBinding = $attributes[$selectedFieldName]["uri"];

                    }
                    $value = $row->$selectedBinding;

                    if ($value instanceof EasyRdf_Literal ) {
                        /** @var EasyRdf_Literal $value */
                        $val  = $value->getValue();
                        if($value instanceof EasyRdf_Literal_Decimal)
                            $val = floatval($val);
                        elseif($value instanceof EasyRdf_Literal_Integer)
                            $val = intval($val);


                    } else {
                        /** @var \EasyRdf_Resource $value */
                        $val = $value->dumpValue('text');
                    }

                    $finalName = $this->getAttributeRef($model, [$selectedFieldName]);
                    $added[$finalName] = $val;

                }

                else{
                    foreach ($selectedField as $subPropertyName=>$subProperty){
                        $selectedBinding = $attributes[$selectedFieldName][$subPropertyName];

                        $value = $row->$selectedBinding;

                        if ($value instanceof EasyRdf_Literal ) {
                            /** @var EasyRdf_Literal $value */
                            $val  = $value->getValue();
                            if($value instanceof EasyRdf_Literal_Decimal)
                                $val = floatval($val);
                            elseif($value instanceof EasyRdf_Literal_Integer)
                                $val = intval($val);


                        } else {
                            /** @var \EasyRdf_Resource $value */
                            $val = $value->dumpValue('text');
                        }

                        $finalName = $this->getAttributeRef($model, [$selectedFieldName, $subPropertyName]);
                        $added[$finalName] = $val;

                    }
                }








            }






            $results[] = $added;
        }
        return $results;
    }






    private function orthogonize(array $results, string $keyAttribute, array $propertyMappings){
        $newResults = [];

        foreach ($results as $result) {
            if(!isset( $newResults[$result[$keyAttribute]])){
                $newResults[$result[$keyAttribute]] = [$keyAttribute=>$result[$keyAttribute]];
            }

            foreach ($propertyMappings as $propertyMapping=>$mappings) {
                if(isset($result[$propertyMapping])){
                    $newResults[$result[$keyAttribute]][$result[$propertyMapping]] = [];
                    foreach($mappings as $mapping){
                        if(isset($result[$mapping]))
                            $newResults[$result[$keyAttribute]][$result[$propertyMapping]][$mapping] = $result[$mapping];
                    }
                }
            }

        }

        return array_values($newResults);

    }


    public function oneOnOneSlicer(){
        $dataset = request("dataset");
        $aggregate = request("aggregate");
        $amount = request("amount");
        $slicer = request("slicer");


        $queryBuilder = new QueryBuilder(config("sparql.prefixes"));
        $queryBuilder->selectDistinct("?value", "?label", "($aggregate(?amount) as ?amount)" )
            ->where('?observation', 'a', 'qb:Observation')
            ->where('?observation', 'qb:dataSet', "<$dataset>")


            ->orderBy("?observation", "DESC")
            ->union(
                $queryBuilder->newSubgraph()
                    ->where('?observation', "<$slicer>", "?value")->optional("?value", 'skos:prefLabel', '?label')
                ,
                $queryBuilder->newSubgraph()
                    ->where("?slice", "a", "qb:Slice")
                    ->where("?slice", "qb:observation", "?observation")
                    ->where("?slice", "<$slicer>", "?value")->optional("?value", 'skos:prefLabel', '?label')

            )
            ->where("?observation", "<$amount>", "?amount")
            ->groupBy(["?value", "?label"])
            ->orderBy("?amount")
        ;
        //echo($queryBuilder->format());


         /** @var EasyRdf_Sparql_Result $result */
        $result = $this->sparql->query(
            $queryBuilder->getSPARQL()
        );
        //echo($result->dump());

        $results = $this->rdfResultsToArray($result);



        return $results;
    }

    public function index()
    {
        return view("start.index");
    }
}