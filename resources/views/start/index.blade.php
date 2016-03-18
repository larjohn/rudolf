@extends('layout.template')
@section('title')
Start
@endsection
@section('body')
    <h3>Datasets</h3>
    <ul id="datasets"></ul>
    <h3>Data preview (observations)</h3>
    <table id="preview" class="table table-striped table-bordered">
        <thead>
            <tr></tr>
        </thead>
        <tbody>

        </tbody>
    </table>
    <h3>Office allocation</h3>
    <div id="pie"></div>
    <script src="{{asset("bower/jquery/dist/jquery.js")}}"></script>
    <script src="{{asset("bower/arrive/src/arrive.js")}}"></script>
    <script src="{{asset("bower/numeral/numeral.js")}}"></script>

    <script src="{{asset("bower/jsviews/jsviews.js")}}"></script>
    <script id="datasetsTemplate" type="text/x-jsrender">

        <li class="dataset" data-link="data-uri{:dataset} data-dsd{:dsd}" >

            <a href="#">{^{:label}}</a>


        </li>
   </script>

    <script id="dimensionsTemplate" type="text/x-jsrender">
            <th class="dimension" data-link="data-uri{:attribute} " >
                <span>{^{:~propertyFallback(#data,"label","attribute")}}</span>
            </th>

   </script>

    <script id="valuesTemplate" type="text/x-jsrender">

        <tr>

            {^{for ~attributes ~item=#data}}

                    <td class="attribute" data-link="data-uri{:!~item[#data.attribute]?'':   ~item[#data.attribute].value} " >

                        {^{if #data.propertyType=="qb:MeasureProperty" }}
                            <span style="white-space: nowrap; display:block" class="text-right">
                                {^{if #data.attribute=="http://data.openbudgets.eu/ontology/dsd/measure/amount"}}

                                    {^{toCurrency: !~item[#data.attribute]?"":~propertyFallback(~item[#data.attribute], "label", "uri")}}

                                @{{/if}}
                             </span>




                        @{{else #data.propertyType=="qb:CodedProperty" }}

                            <a data-link="href{:~item[#data.attribute].uri}">{^{:!~item[#data.attribute]?"":   ~propertyFallback(~item[#data.attribute], "label", "uri")}}</a>


                        @{{/if}}
                  </td>
            @{{/for}}


        </tr>

   </script>
    <script>

        numeral.language('el', {
            delimiters: {
                thousands: ' ',
                decimal: ','
            },
            abbreviations: {
                thousand: 'k',
                million: 'm',
                billion: 'b',
                trillion: 't'
            },
            ordinal : function (number) {
                return number === 1 ? 'er' : 'ème';
            },
            currency: {
                symbol: '€'
            }
        });

        // switch between languages
        numeral.language('el');


        var attributes = {};
        var data = {};
        $.views.converters({
            toCurrency: function(value) {
               return numeral(value).format('($ 0.00 a)')
            }
        });
        $.views.helpers({
            propertyFallback: function (entity, property, fallback) {
                if(typeof (entity[property])!='undefined' || entity[property]==''){
                    return entity[property];
                }
                else{
                    return entity[fallback];
                }
            }
        });
        $(document).ready(function(){
            //$.views.settings.delimiters("[[", "]]");
            $.getJSON("{{route("start.api.datasets")}}", function (json) {
                
                var template = $.templates("#datasetsTemplate");

                template.link("#datasets", json);
            });

            nowAndThen($("#datasets"), ".dataset",function () {
                $(this).click(function () {
                    getDatasetPreview($(this).attr("data-dsd"),$(this).attr("data-uri") );
                });

            });

            
            function getDatasetPreview(dsd, dataset) {
                $.getJSON("{{route("start.api.dataset.observations.dimensions")}}",{dsd:dsd}, function (json) {

                    var template = $.templates("#dimensionsTemplate");

                    template.link("#preview thead tr", json);

                    attributes = json;
                    $.getJSON("{{route("start.api.dataset.facts")}}",{dataset:dataset, dsd:dsd}, function (json) {

                        var template = $.templates("#valuesTemplate");

                        template.link("#preview tbody ", json["data"], {attributes:attributes});




                    });


                });
            }
            
            
        });

        function nowAndThen(parent, elementSelector, func){
            parent.find(elementSelector).each(func);

            parent.arrive(elementSelector, func);
        }

    </script>
    <script src="//d3js.org/d3.v3.min.js"></script>
    <style>

        .bar {
            fill: steelblue;
        }

        .bar:hover {
            fill: brown;
        }

        .axis {
            font: 10px sans-serif;
        }

        .axis path,
        .axis line {
            fill: none;
            stroke: #000;
            shape-rendering: crispEdges;
        }

        .x.axis path {
            display: none;
        }

    </style>
    <script>



        var margin = {top: 20, right: 20, bottom: 300, left: 140},
                width = 960 - margin.left - margin.right,
                height = 500 - margin.top - margin.bottom;

        var x = d3.scale.ordinal()
                .rangeRoundBands([0, width], .1);

        var y = d3.scale.linear()
                .range([height, 0]);

        var xAxis = d3.svg.axis()
                .scale(x)
                .orient("bottom");

        var yAxis = d3.svg.axis()
                .scale(y)
                .orient("left")
                .ticks(10);

        var svg = d3.select("body").append("svg")
                .attr("width", width + margin.left + margin.right)
                .attr("height", height + margin.top + margin.bottom)
                .append("g")
                .attr("transform", "translate(" + margin.left + "," + margin.top + ")");
        $.getJSON("{{route("start.api.dataset.oneonone")}}", {aggregate:"sum", slicer:"http://data.openbudgets.eu/ontology/dsd/municipality-of-athens/dimension/administrativeClassification", dataset:"http://data.openbudgets.eu/resource/dataset/budget-athens-expenditure-2013", amount:"http://data.openbudgets.eu/ontology/dsd/measure/amount"}, function (data) {

            x.domain(data.map(function(d) { return d.label; }));
            y.domain([0, d3.max(data, function(d) { return d.amount; })]);

            svg.append("g")
                    .attr("class", "x axis")
                    .attr("transform", "translate(0," + height + ")")
                    .call(xAxis)
                    .selectAll("text")
                    .attr("y", 0)
                    .attr("x", 9)
                    .attr("dy", ".35em")
                    .attr("transform", "rotate(90)")
                    .style("text-anchor", "start");

            ;

            svg.append("g")
                    .attr("class", "y axis")
                    .call(yAxis)
                    .append("text")
                    .attr("transform", "rotate(-90)")
                    .attr("y", 6)
                    .attr("dy", ".71em")
                    .style("text-anchor", "end")
                    .text("Amount");

            svg.selectAll(".bar")
                    .data(data)
                    .enter().append("rect")
                    .attr("class", "bar")
                    .attr("x", function(d) { return x(d.label); })
                    .attr("width", x.rangeBand())
                    .attr("y", function(d) { return y(d.amount); })
                    .attr("height", function(d) { return height - y(d.amount); });

         /*   svg.selectAll("text.bar")
                    .data(data)
                    .enter().append("text")
                    .attr("class", "bar")
                    .attr("text-anchor", "middle")
                    .attr("x", function(d) { return x(d.label) + x.rangeBand()/2; })
                    .attr("y", function(d) { return y(d.amount) - 5; })

                    .text(function(d) { return d.amount; })*/


        });

        function type(d) {
            d.amount = +d.amount;
            return d;
        }


    </script>

@endsection