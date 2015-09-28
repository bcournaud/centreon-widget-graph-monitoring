<?php
include_once "/etc/centreon/centreon.conf.php";

/**
 * Function for test is a value is NaN
 *
 * @param mixed $element The element to test
 * @return mixed null if NaN else the element
 */
function convertNaN($element)
{
    if (strtoupper($element) == 'NAN') {
        return null;
    }
    return $element;
}

function sendJson($values, $code = 200)
{
    /*switch ($code) {
        case 500:
            header("HTTP/1.1 500 Internal Server Error");
            break;
        case 403:
            header("HTTP/1.1 403 Forbidden");
            break;
        case 404:
            header("HTTP/1.1 404 Object not found");
            break;
        case 400:
            header("HTTP/1.1 400 Bad Request");
            break;
    }*/
    //header('Content-type: application/json');
file_put_contents('/tmp/toto', json_encode($values));
    print json_encode($values);
    exit();
}

/* Test if the session id for authentification */
if (false === isset($_GET['session_id'])) {
    sendJson("Forbidden access", 403);
}

$sid = $_GET['session_id'];
/* Test session id format */
$match = preg_match("/^[\w-]+$/", $sid);
if (false === $match || 0 === $match) {
    sendJson("Forbidden access", 403);
}

require_once $centreon_path . "/www/class/centreonDB.class.php";
require_once $centreon_path . "/www/class/centreonACL.class.php";
require_once dirname(dirname(__FILE__)) . "/class/GraphService.php";
#require_once dirname("GraphService.php");
$pearDB = new CentreonDB();
$pearDBD = new CentreonDB("centstorage");

$sid = CentreonDB::escape($sid);

/* Check if session is initialised */
$res = $pearDB->query("SELECT s.user_id, c.contact_admin FROM session s, contact c WHERE s.user_id = c.contact_id AND s.session_id = '" . $sid . "'");

if (PEAR::isError($res)) {
    sendJson("Internal Server Error", 500);
}    

$row = $res->fetchRow();

if (is_null($row)) {
    sendJson("Forbidden access", 403);
}

$isAdmin = $row['contact_admin'];
$userId = $row['user_id'];

/* Get ACL if user is not admin */
if (!$isAdmin) {
    $acl = new CentreonACL($userId, $isAdmin);
    $aclGroups = $acl->getAccessGroupsString();
}

/* Validate options */
if (false === isset($_GET['start']) ||
    false === is_numeric($_GET['start']) ||
    false === isset($_GET['end']) ||
    false === is_numeric($_GET['end'])) {
    sendJson("Bad Request", 400);
}

$start = $_GET['start'];
$end = $_GET['end'];

$rows = 200;
if (isset($_GET['rows'])) {
    if (false === is_numeric($_GET['rows'])) {
        sendJson("Bad Request", 400);
    }
    $rows = $_GET['rows'];
}
if ($rows < 10) {
    sendJson("The rows must be greater as 10", 400);
}

if (false === isset($_GET['ids'])) {
    sendJson(array());
}

/* Get the list of service ID */
$ids = explode(',', $_GET['ids']);
$result = array();

foreach ($ids as $id) {
    list($hostId, $serviceId) = explode('_', $id);
    if (false === is_numeric($hostId) ||
        false === is_numeric($serviceId)) {
        sendJson("Bad Request", 400);
    }

    /* Check ACL is not admin */
    if (!$isAdmin) {
        $query = "SELECT service_id 
            FROM centreon_acl
            WHERE host_id = " . $hostId . "
                AND service_id = " . $serviceId . "
                AND group_id IN (" . $aclGroups . ")";
        $res = $pearDBD->query($query);
        if (0 == $res->numRows()) {
            sendJson("Access denied", 403);
        }
    }

    $data = array();


    /* Prepare graph */
    try {
        /* Get index data */
        $indexData = GraphService::getIndexId($hostId, $serviceId, $pearDBD);
        $graph = new GraphService($indexData, $sid);
    } catch (Exception $e) {
        sendJson("Graph not found", 404);
    }
    $graph->setRRDOption("start", $start);
    $graph->setRRDOption("end", $end);
    $graph->initCurveList();
    $graph->createLegend();

    $serviceData = $graph->getData($rows);

    /* Replace NaN */
    for ($i = 0; $i < count($serviceData); $i++) {
        if (isset($serviceData[$i]['data'])) {
            $times = array_keys($serviceData[$i]['data']);
            $values = array_map("convertNaN",
                array_values($serviceData[$i]['data'])
            );
        }
        $serviceData[$i]['data'] = $values;
        $serviceData[$i]['label'] = $serviceData[$i]['legend'];
        unset($serviceData[$i]['legend']);
        $serviceData[$i]['type'] = $serviceData[$i]['graph_type'];
        unset($serviceData[$i]['graph_type']);
    }
    $result[] = array(
        'service_id' => $id,
        'data' => $serviceData,
        'times' => $times,
        'size' => $rows
    );
}
sendJson($result);
