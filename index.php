<?php
/**
 * Copyright 2005-2011 MERETHIS
 * Centreon is developped by : Julien Mathis and Romain Le Merlus under
 * GPL Licence 2.0.
 *
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation ; either version 2 of the License.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
 * PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program; if not, see <http://www.gnu.org/licenses>.
 *
 * Linking this program statically or dynamically with other modules is making a
 * combined work based on this program. Thus, the terms and conditions of the GNU
 * General Public License cover the whole combination.
 *
 * As a special exception, the copyright holders of this program give MERETHIS
 * permission to link this program with independent modules to produce an executable,
 * regardless of the license terms of these independent modules, and to copy and
 * distribute the resulting executable under terms of MERETHIS choice, provided that
 * MERETHIS also meet, for each linked independent module, the terms  and conditions
 * of the license of that module. An independent module is a module which is not
 * derived from this program. If you modify this program, you may extend this
 * exception to your version of the program, but you are not obliged to do so. If you
 * do not wish to do so, delete this exception statement from your version.
 *
 * For more information : contact@centreon.com
 *
 */

ini_set("display_errors", "Off");

require_once "../require.php";
require_once $centreon_path . 'www/class/centreon.class.php';
require_once $centreon_path . 'www/class/centreonSession.class.php';
require_once $centreon_path . 'www/class/centreonDB.class.php';
require_once $centreon_path . 'www/class/centreonWidget.class.php';

session_start();

if (!isset($_SESSION['centreon']) || !isset($_REQUEST['widgetId'])) {
	exit;
}
$centreon = $_SESSION['centreon'];
$widgetId = $_REQUEST['widgetId'];

try {
	global $pearDB;

	$db = new CentreonDB();
	$db2 = new CentreonDB("centstorage");
	$pearDB = $db;

	if ($centreon->user->admin == 0) {
		$access = new CentreonACL($centreon->user->get_id());
		$grouplist = $access->getAccessGroups();
		$grouplistStr = $access->getAccessGroupsString();
	}

	$widgetObj = new CentreonWidget($centreon, $db);
	$preferences = $widgetObj->getWidgetPreferences($widgetId);
	$autoRefresh = 0;
	if (isset($preferences['refresh_interval'])) {
		$autoRefresh = $preferences['refresh_interval'];
	}

    /*
     * Prepare URL
     */
    if (isset($preferences['service']) && $preferences['service']) {
    	$tab = split("-", $preferences['service']);

    	$host_name = "";
    	$service_description = "";

    	$res = $db2->query("SELECT host_name, service_description
    		FROM index_data
    		WHERE host_id = ".$db->escape($tab[0])."
    		AND service_id = ".$db->escape($tab[1])."
    		LIMIT 1");
    	if ($res->numRows()) {
    		$row = $res->fetchRow();
    		$host_name = $row["host_name"];
    		$service_description = $row["service_description"]; 
    	}
    }
    
    /*
     * Check ACL
     */
    $acl = 1;
    if (isset($tab[0]) && isset($tab[1]) && $centreon->user->admin == 0) {
    	$query = "SELECT host_id FROM centreon_acl WHERE host_id = ".$db->escape($tab[0])." AND service_id = ".$db->escape($tab[1])." AND group_id IN (".$grouplistStr.")";
    	$res = $db2->query($query);
    	if (!$res->numRows()) {
    		$acl = 0;
    	}
    }
} catch (Exception $e) {
	echo $e->getMessage() . "<br/>";
	exit;
}
?>
<html>
<style type="text/css">
body{ margin:0; padding:0 0 0 0; }
div#actionBar { position:absolute; top:0; left:0; width:100%; height:25px; background-color: #FFFFFF; }
@media screen { body>div#actionBar { position: fixed; } }
* html body { overflow:hidden ; text-align:center;}

</style>
<head>
	<link href="resources/c3.css" rel="stylesheet" type="text/css">
	<link href="resources/test.css" rel="stylesheet" type="text/css">

	<!-- Load d3.js and c3.js -->
	<script src="resources/d3.v3.min.js" charset="utf-8"></script>
	<script src="resources/c3.min.js"></script>    
	<script src="resources/math.min.js"></script>

	<link href="../../Themes/Centreon-2/style.css" rel="stylesheet" type="text/css"/>
	<link href="../../Themes/Centreon-2/jquery-ui/jquery-ui.css" rel="stylesheet" type="text/css"/>
	<link href="../../Themes/Centreon-2/jquery-ui/jquery-ui-centreon.css" rel="stylesheet" type="text/css"/>
	<script type="text/javascript" src="../../include/common/javascript/jquery/jquery.js"></script>
	<script type="text/javascript" src="../../include/common/javascript/jquery/jquery-ui.js"></script>
	<script type="text/javascript" src="../../include/common/javascript/widgetUtils.js"></script>

</head>
<body>

	<?php 
	if ($acl == 1) {
		if (isset($preferences['service']) && $preferences['service']) {
			print "<h3 style='text-align:center'>" . $host_name . " - " . $service_description . "</h3>";
			print "<div id='chart4'></div>";
		} else {
			print "<center><div class='update' style='text-align:center;width:350px;'>"._("Please select a resource first")."</div></center>";
		} 
	} else {
		print "<center><div class='update' style='text-align:center;width:350px;'>"._("You are not allowed to reach this graph")."</div></center>";
	}
	?>
</body>

<script type="text/javascript">
var widgetId = <?php echo $widgetId; ?>;
var autoRefresh = <?php echo $autoRefresh;?>;
var timeout;

jQuery(function() {
	var image = document.getElementById("chart4");
	if (image) {
		image.onload = function() {
			var h = this.height;
			parent.iResize(window.name, h);
			jQuery(window).resize(function() {
				reload();
			});
		}
		reload();
	}
});

function reload() {
	var graphPeriod = <?php echo $preferences['graph_period']; ?>;
	var startTime = Math.round(new Date().getTime() /1000 - graphPeriod);

// Display a point every 5 minutes, with a maximum of 200 points on the graph
	var nbPoints = graphPeriod / 60 / 5;
	console.log(nbPoints);
	nbPoints = Math.min(nbPoints, 200);

	var graphPart = document.getElementById("chart4");
	if (graphPart) {
		parent.iResize(window.name, 350);
	};
	$.ajax({
		type: "GET",
		dataType: "json",
		url: "src/get_metrics.php" ,
		data: { start: startTime,
			end: Math.round(new Date().getTime() / 1000),
			rows: nbPoints,
			ids: '<?php echo str_replace('-', '_', $preferences['service']); ?>',
			session_id: '<?php echo session_id();?>'
		},
		success : function(dataObj) {

			console.log(dataObj);

			var data = dataObj[0];

			var timeFormatter = '%H:%M';
			var times = data.times;
			var rangeTime = times[times.length - 1] - times[0];

			if (rangeTime > 1000 * 24) {
				timeFormatter = '%d/%m';
			}

	// Time serie
	var timeseries = new Array();
	timeseries[0] = 'time';
	for (var x = 0; x < data.times.length; x++) {
		timeseries[x + 1] = data.times[x] * 1000;
	}

	var hostName = '<?php echo $host_name; ?>';
	var serviceDescription = '<?php echo $service_description; ?>';

	// Compute graph Y unit

	var maxValue = 0;
	for (var x = 0; x < data.data.length; x++) {
		for (var y = 0; y < data.data[x].data.length; y++) {
			maxValue = Math.max(maxValue, Math.abs(data.data[x].data[y]));
		}
	}

	var coefficient = 1;

	while (maxValue > 1000) {
		maxValue = maxValue / 100;
		coefficient++;
	}
	coefficient = Math.min(coefficient, 4);

	// Correspondance table
	var uc = new Array();
	uc[1] = "b";
	uc[2] = "KB";
	uc[3] = "MB";
	uc[4] = "GB";

	// Generate graph
	var chart = c3.generate({
		bindto: '#chart4',
		title: hostName,
		data: {
			x: 'time',
			columns : [
			],
		},
		grid: {
			x: {
				show: true
			},
			y: {
				show: true
			}
		},
		axis: {
			x: {
				type : 'timeseries',
				tick: {
					format: d3.time.format(timeFormatter)
				}
			},
			y: {
				label: {
					text: serviceDescription,
					position: 'outer-middle'
				},
				tick: {
	  				format: d3.format("s")
	  			}
			},
		}
	});

	// End graph generation

	// TODO: Use math.min.js to convert values to an adapted unit
	for (var x = 0; x < data.data.length; x++) {
		var tmp = new Array();
		tmp[0] = data.data[x].label;
		for (var y = 0; y < data.data[x].data.length; y++) {
			tmp[y + 1] = data.data[x].data[y] / coefficient;
		}

		var type = data.data[x].type;
		if (type = 'area') {
			type = 'line';
		}

		if (data.data[x].label.startsWith("traffic")) {
			type = 'area-step';
		}

		var colorObj = {};
		colorObj[tmp[0]] = data.data[x].color;

	  // Load data
	  chart.load({
	  	columns: [
	  	tmp,
	  	timeseries
	  	],
	  	type: type,
	  	colors: colorObj,
	  });
	  // End load data
	}
	// function below reloads current page
	// location.reload();
},
error: function(jqXHR, textStatus, errorThrown) {
	console.log(jqXHR);
	console.log(textStatus);
	console.log(errorThrown);
}
});

if (autoRefresh) {
	if (timeout) {
		clearTimeout(timeout);
	}
	timeout = setTimeout(reload, (autoRefresh * 1000));
}
}
</script>
</html>
