<?php
include_once("settings.php");

$arr = Array();

	$groupby = "";
	$from = 0;
	
	if(isset($_GET['from']) && isset($_GET['to'])) {
		$from = $_GET['from'];
		$period = (($_GET['to'] - $_GET['from']) * 0.016666666666666666 * 0.016666666666666666 * 0.041666666666666664);
	}
	
	if(isset($_GET['period'])) {
		$period = $_GET['period'];
		
		if($period === 'latest'){
			$period = 10000;
		}
	} else if(!isset($period)){
		$period = 1;
	}
	
	if($period >= 7) {
		$d = "%Y-%m-%d %H";
		
		if($period >= 365) {
			$d = "%Y-%m";
		} else if($period >= 60) {
			$d = "%Y %u";
		} else if($period >= 30) {
			$d = "%Y-%m-%d";
		}
		$groupby = "GROUP BY sensor_id, DATE_FORMAT(date, '" . $d . "') ";
	}
	
	if(isset($_GET['from']) && isset($_GET['to'])) {
		$where = "FROM_UNIXTIME(" . $_GET['from'] . ") <= date && FROM_UNIXTIME(" . $_GET['to'] . ") > date ";
	} else {
		$where = "DATE_SUB(CURDATE(),INTERVAL " . $period ." DAY) <= date ";
	}
		
	$order = "date ASC";
	
    // Set back the period properly in order to not mess up the jsoncache
    if (isset($_GET['period']) && $period == 10000) {
        $period = 0;
    }
    
	//Try to read from cache
	if(isset($_GET['usecache']) && $_GET['usecache'] == "true") 
	{
		$outStr = readCache($from, $period);
	}
	
	if(!isset($outStr) || !$outStr) 
	{
		if(isset($_GET['period']) && $_GET['period'] == 'latest') { // Latest readings
            $query = "SELECT UNIX_TIMESTAMP(i.date) AS date, i.sensor_id, r.temp, s.name, s.color FROM readings r"
                        . " RIGHT JOIN ("
                        . " SELECT MAX(date) AS date, sensor_id FROM readings GROUP BY sensor_id ORDER BY date DESC"
                        . " ) AS i ON i.date = r.date AND i.sensor_id = r.sensor_id"
                        . " RIGHT JOIN sensors s ON s.sensor_id = r.sensor_id"
                        . " ORDER BY sensor_id, date ASC";
		}
		else { // List of readings
                $query = "SELECT UNIX_TIMESTAMP(i.date) AS date, i.sensor_id, r.temp, s.name, s.color FROM readings r"
                        . " RIGHT JOIN ("
                        . " SELECT date, sensor_id FROM readings WHERE $where $groupby ORDER BY date DESC"
                        . " ) AS i ON i.date = r.date AND i.sensor_id = r.sensor_id"
                        . " RIGHT JOIN sensors s ON s.sensor_id = r.sensor_id"
                        . " ORDER BY sensor_id, date ASC";
		}

		if (isset($_GET['debug'])) {
			echo $query . "<br /><br />";
		}
					 
		$result = mysql_query($query) or die(mysql_error().' '.sqlerr(__FILE__, __LINE__));
		
        $lastId = -1;
        $collection = array();
        $lastName;
        $lastColor;
        $containData = false;
        
		while($row = mysql_fetch_array($result)) 
		{
            if (intval($row['sensor_id']) != $lastId) {                
                if (isset($arr)) {
                    if ($lastId >= 0 && $containData) {
                        array_push($collection, array($lastId, $lastName, $lastColor, $arr));
                    }
                    
                    unset($arr);
                    $arr = array();
                    $containData = false;
                }
                
                $lastId = intval($row['sensor_id']);
            }
            
            if (intval($row['date']) > 0) {
                $containData = true;
                array_push($arr, array((intval($row['date']) * 1000), floatval($row['temp'])));
            }
            
            $lastName = $row['name'];
            $lastColor = $row['color'];
		}
		
        array_push($collection, array($lastId, $lastName, $lastColor, $arr));
        
		$outStr = json_encode($collection);
        
		if(isset($_GET['usecache']) && $_GET['usecache'] == "true") 
		{
			writeCache($from, $period, $outStr);
		}
		
		mysql_free_result($result);
		mysql_close($connection);
	}

echo $outStr;

function getCacheFileName($from, $period) {
	return 'cacheData/' . $from . '_' . $period;
}

function getCacheFileNameSufix($period) {
	if($period > 300) {
		return date("ym");
	} else if($period >= 60) {
		return date("yW");
	} else if($period >= 30) {
		return date("ymd");
	}
}

function readCache($from, $period) {
	$fileName = getCacheFileName($from, $period) . '_' . getCacheFileNameSufix($period);
	
	if (file_exists("$fileName")) {
		return file_get_contents($fileName);
	}
	
	return false;
}

function writeCache($from, $period, $data) {
	$fileNamePrefix = getCacheFileName($from, $period);
	$fileName = getCacheFileName($from, $period) . '_' . getCacheFileNameSufix($period);
	
	if (!file_exists($fileName) && $period > 7) {
		delfiles($fileNamePrefix . '*');
		file_put_contents("$fileName", $data);
	}
}

function delfiles($str) 
{ 
    foreach (glob($str) as $fn) { 
        unlink($fn); 
    } 
} 
?>