<?php
/*
GisClient

Copyright (C) 2008 - 2010  Roberto Starnini - Gis & Web S.r.l. -info@gisweb.it

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 3
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.

*/

class gcMapfile{
	var $db;
	var $projectName='';
	private $projectTitle;
	var $symbolText='';
	var $layerText='';
	var $mapTitle='';
	var $mapAbstract='';
	var $printMap = false;
	var $serviceOnlineresource='';
	var $layersWithAccessConstraints = array();
	var $srsParams = array();
	var $epsgList;
	var $mapInfo=array();
	var $srsCustom=array();
	private $target = 'public';
	private $tinyOWSLayers = array();
	
	private $i18n;
	private $languageId;
	
	function __construct ($languageId = null){
		$this->db = GCApp::getDB();
		$this->languageId = $languageId;
		
		if(isset($_SESSION['save_to_tmp_map']) && $_SESSION['save_to_tmp_map'] === true ) $this->target = 'tmp';
	}
	
	function __destruct (){
	
		unset($this->db);
		unset($this->filter);
		unset($this->mapError);
		
	}
	
	public function setTarget($target) {
		$this->target = $target;
	}

	function writeMap($keytype,$keyvalue){
		
        $sqlParams = array();

		if($keytype=="mapset") {	//GENERO IL MAPFILE PER IL MAPSET
				$filter="mapset.mapset_name=:keyvalue";
				$joinMapset="INNER JOIN ".DB_SCHEMA.".mapset using (project_name) INNER JOIN ".DB_SCHEMA.".mapset_layergroup using (mapset_name,layergroup_id)";
				$fieldsMapset="mapset_name,mapset_extent,mapset_srid,mapset.maxscale as mapset_maxscale,mapset_def,";
				$sqlParams['keyvalue'] = $keyvalue;
                
                $sql = 'select project_name from '.DB_SCHEMA.'.mapset where mapset_name=:mapset';
                $stmt = $this->db->prepare($sql);
                $stmt->execute(array('mapset'=>$keyvalue));
                $projectName = $stmt->fetchColumn(0);
				
		} elseif($keytype=="project") { //GENERO TUTTI I MAPFILE PER IL PROGETTO
				$filter="project.project_name=:keyvalue";
				$joinMapset="INNER JOIN ".DB_SCHEMA.".mapset using (project_name) INNER JOIN ".DB_SCHEMA.".mapset_layergroup using (mapset_name,layergroup_id)";
				$fieldsMapset="mapset_name,mapset_extent,mapset_srid,mapset.maxscale as mapset_maxscale,mapset_def,";				
				$sqlParams['keyvalue'] = $keyvalue;
                $projectName = $keyvalue;
		
		} elseif($keytype=="layergroup") { //GENERO IL MAPFILE PER IL LAYERGROUP NEL SISTEMA DI RIF DEL PROGETTO (PREVIEW)
				$filter="layergroup.layergroup_id=:keyvalue";
				$joinMapset="";
				$fieldsMapset="layergroup_name as mapset_name,layer.data_srid as mapset_srid,layer.data_extent as mapset_extent,";			
				$sqlParams['keyvalue'] = $keyvalue;
	
		
		} elseif($keytype="print"){ //GENERO UN MAPFILE PER LA STAMPA
				$_in = GCApp::prepareInStatement($keyvalue);
				$sqlParams = $_in['parameters'];
				$inQuery = $_in['inQuery'];

			$this->printMap = true;
			$filter = "project_name||'.'||theme_name||'.'||layergroup_name in (".$inQuery.")";
		}
		
		if(!empty($this->languageId)) { // inizializzo l'oggetto i18n per le traduzioni
			$this->i18n = new GCi18n($projectName, $this->languageId);
		}

		$sql="select project_name,".$fieldsMapset."base_url,max_extent_scale,project_srid,xc,yc,theme_name,theme_single,layergroup_name,layergroup_title,layergroup_id,layergroup_description,layergroup_maxscale,layergroup_minscale,layergroup_single,tiletype_id,layer_id,layer_name,layer_title,layertype_id, project_title
		from ".DB_SCHEMA.".layer 
		INNER JOIN ".DB_SCHEMA.".layergroup  using (layergroup_id) 
		INNER JOIN ".DB_SCHEMA.".theme using (theme_id)
		INNER JOIN ".DB_SCHEMA.".project using (project_name) ".$joinMapset."
		where ".$filter." order by layer_order,layergroup_order;";	
		
		print_debug($sql,null,'writemap');

/*		
		$sql="select project_name,base_url,max_extent_scale,project_srid,xc,yc,theme_name,theme_single,layergroup_name,layergroup_title,layergroup_id,layergroup_description,layergroup_maxscale,layergroup_minscale,layergroup_single,tiletype_id,layer_id,layer_name,layer_title,layertype_id, project_title
		from ".DB_SCHEMA.".layer 
		INNER JOIN ".DB_SCHEMA.".layergroup  using (layergroup_id) 
		INNER JOIN ".DB_SCHEMA.".theme using (theme_id)
		INNER JOIN ".DB_SCHEMA.".project using (project_name) 		
		where ".$filter." order by layer_order,layergroup_order;";
*/
		$stmt = $this->db->prepare($sql);
		$stmt->execute($sqlParams);
		$res = $stmt->fetchAll();

		if($stmt->rowCount() == 0) {
			$this->mapError=200;//Mancano i layers
			echo 'NO LAYERS';
			return;
		}		

		$aLayer=$res[0];
		$projectName = $aLayer["project_name"];
		
		$this->projectSrid = $aLayer["project_srid"];
		$this->projectName = $projectName;
		$this->projectTitle = $aLayer['project_title'];
		
		//SCALA MASSIMA DEL PROGETTO
		if($aLayer["max_extent_scale"])
			$this->projectMaxScale = $aLayer["max_extent_scale"];
		elseif (defined('SCALE')) {
            $v = explode(",",SCALE);
			$this->projectMaxScale = $v[0];
		}
		else{
			$this->projectMaxScale = GCAuthor::$defaultScaleList[0];
		}
		$this->projectExtent = $this->_calculateExtentFromCenter($aLayer['xc'], $aLayer['yc']);
		
		

		$mapText=array();
		$mapSrid=array();
		$mapExtent=array();
		$symbolsList=array();
		$oFeature = new gcFeature($this->i18n);

		$this->_setMapProjections();
		$oFeature->srsParams = $this->srsParams;

		//print_debug($res,null,'features');
		
		if($this->printMap) $mapName = time().'_print';
		
		foreach ($res as $aLayer){
		
		//TODO DA SISTEMARE SU DB
			$mapName = $aLayer["mapset_name"];
			$layergroupName = NameReplace($aLayer["layergroup_name"]);
			$mapSrid[$mapName] = $aLayer["mapset_srid"];	
			$mapExtent[$mapName] = $aLayer["mapset_extent"];	
			
			$oFeature->initFeature($aLayer["layer_id"]);
			//if(!$this->printMap) $mapName = $projectName;//$themeName;
			
			$layerText = $oFeature->getLayerText($layergroupName,$aLayer["layergroup_maxscale"],$aLayer["layergroup_minscale"]);
			if($oFeature->isPrivate()) array_push($this->layersWithAccessConstraints, $oFeature->getLayerName());

			if(!empty($this->i18n)) {
				$aLayer = $this->i18n->translateRow($aLayer, 'layergroup', $aLayer['layergroup_id'], array('layergroup_title', 'layergroup_description'));
			}
			
			if($layerText){
				$mapText[$mapName][] = $layerText;
				if(!isset($symbolsList[$mapName]))
					$symbolsList[$mapName] = $oFeature->aSymbols;
				else
					$symbolsList[$mapName] = array_merge($symbolsList[$mapName],$oFeature->aSymbols);
				
				//SE IL LAYER E' DI TIPO TILERASTER AGGIUNGO IL CORRISPONDENTE LAYER TILEINDEX DI TIPO POLYGON
				if($aLayer["layertype_id"] == 10){
					$mapText[$mapName][] = $oFeature->getTileIndexLayer();
				}		
			}
			
			if(defined('TINYOWS_PATH') && $oFeature->isEditable()) {
				array_push($this->tinyOWSLayers, $oFeature->getTinyOWSLayerParams());
			}
		}
		
		//print_debug($mapText,null,'writemap');
		
		foreach($mapText as $mapName=>$mapContent){
			//SE NON HO EXTENT LO PRENDO DAL PROGETTO E SE SRID DIVERSO LO RIPROIETTO
			if(empty($mapExtent[$mapName])){			
				if($mapSrid[$mapName] == $this->projectSrid)
					$mapExtent[$mapName] = implode (" ",$this->projectExtent);
				else
					$mapExtent[$mapName] = $this->_transformExtent($mapSrid[$mapName]);
			}

			$this->layerText = implode("\n",$mapContent);
			$this->mapsetSrid = $mapSrid[$mapName];
			$this->mapsetExtent = $mapExtent[$mapName];
			
			if($symbolsList[$mapName]) $this->layerText .= $this->_getSymbolText($symbolsList[$mapName]);
			$this->_writeFile($mapName);
		}
		return $mapName;
	}
	
	function _writeFile(&$mapFile){
		$projectName = $this->projectName;
		$fontList=(defined('FONT_LIST'))?FONT_LIST:'fonts';	
		$projLib=(defined('PROJ_LIB'))?"CONFIG 'PROJ_LIB' '".PROJ_LIB."'":'';
		$outputFormat = $this->_getOutputFormat($mapFile);
		//$metadata_inc = file_get_contents (ROOT_PATH."config/mapfile.metadata.inc");
		$metadata_inc = '';
		//$legend_inc = file_get_contents (ROOT_PATH."config/mapfile.legend.inc");
        $legend_inc = $this->_getLegendSettings();
		//$legend_inc = '';
		
		$imgPath = "IMAGEPATH \"".IMAGE_PATH."\"";
		$imgUrl = "IMAGEURL \"".IMAGE_URL."\"";
		$imgResolution = "RESOLUTION ".MAP_DPI;
		$size = TILE_SIZE . " " . TILE_SIZE;

		$wms_mime_type = "\t\"wms_feature_info_mime_type\"	\"text/html\"";
		$ows_title = "\t\"ows_title\"\t\"". $mapFile ."\"";
		$ows_wfs_encoding = $this->_getEncoding();
		$ows_abstract = ""; //TODO: ripristinare aggiungendo descrizione a progetto
		$wfs_namespace_prefix = "\t\"wfs_namespace_prefix\"\t\"feature\"";//valore di default in OL
		$ows_srs = "\t\"wms_srs\"\t\"". $this->epsgList ."\"";
		$ows_accessConstraints = '';
		if(!empty($this->layersWithAccessConstraints)) {
			$ows_accessConstraints = "\t\"ows_accessconstraints\"\t\"Layers ".implode(', ', $this->layersWithAccessConstraints)." need authentication\"";
		}
        
        $owsUrl = defined('GISCLIENT_OWS_URL') ? GISCLIENT_OWS_URL . 'project='.$this->projectName.'&map='.$mapFile : null;
        $wms_onlineresource = '';
        $wfs_onlineresource = '';
        if(!empty($owsUrl)) {
            $wms_onlineresource = "\t".'"wms_onlineresource" "'.$owsUrl.'"';
            $wfs_onlineresource = "\t".'"wfs_onlineresource" "'.$owsUrl.'"';
        }
		
		$layerText = $this->layerText;
		$mapProjection = "\t\"init=epsg:".$this->mapsetSrid."\"";
		if(!empty($this->srsParams[$this->mapsetSrid])) $mapProjection .= "\n\t\"+towgs84=".$this->srsParams[$this->mapsetSrid]."\"";
		$mapsetExtent = "EXTENT ". $this->mapsetExtent;

        if(defined('MAPFILE_MAX_SIZE')) $maxSize = MAPFILE_MAX_SIZE;
        else $maxSize = '4096';
		
		$fileContent=
"MAP
NAME \"$mapFile\"
SIZE $size	
MAXSIZE $maxSize
$imgResolution
FONTSET ../../fonts/$fontList.list
$projLib
WEB
	METADATA
        # for mapserver 6.0
        \"ows_enable_request\" \"*\"
	$ows_title
	$ows_abstract
	$ows_wfs_encoding
    $wms_onlineresource
    $wfs_onlineresource
	$wms_mime_type
	$wfs_namespace_prefix
	$ows_srs
	$ows_accessConstraints
$metadata_inc
	END
	$imgPath
	$imgUrl	
END	
PROJECTION
$mapProjection
END
$mapsetExtent
$layerText
$legend_inc
$outputFormat
END #MAP";

		if($this->printMap) {
			$mapFile=ROOT_PATH."map/tmp/".$mapFile.".map";
		} else {
			$mapfileDir = 'map/';
			if($this->target == 'tmp') {
				$mapFile = 'tmp.'.$mapFile;
			}
			if(!is_dir(ROOT_PATH.$mapfileDir)) mkdir(ROOT_PATH.$mapfileDir);
			if(!is_dir(ROOT_PATH.$mapfileDir.$projectName)) mkdir(ROOT_PATH.$mapfileDir.$projectName);
			if(!empty($this->i18n)) {
				$languageId = $this->i18n->getLanguageId();
				$mapFile.= "_".$languageId;
			}
			$mapFilePath = ROOT_PATH.$mapfileDir.$projectName."/".$mapFile.".map";
		}
		$f = fopen ($mapFilePath,"w");
		$ret=fwrite($f, $fileContent);
		fclose($f);
		
		if(!$this->printMap && empty($this->i18n) && !empty($this->tinyOWSLayers)) {
			foreach($this->tinyOWSLayers as $layer) {
				$towsOnlineResource = TINYOWS_ONLINE_RESOURCE.$projectName.'/'.$layer['feature'].'/?';
				$fileContent = '<tinyows online_resource="'.$towsOnlineResource.'" schema_dir="'.TINYOWS_SCHEMA_DIR.'" check_schema="0" check_valid_geom="1" meter_precision="7" expose_pk="1" log_level="7"><pg host="'.DB_HOST.'" user="'.DB_USER.'" password="'.DB_PWD.'" dbname="'.$layer['database'].'" port="'.DB_PORT.'"/><metadata name="TinyOWS Server" title="TinyOWS Server" /><contact name="Admin" site="http://gisclient.net" email="admin@gisclient.net" />';
				$fileContent .= '<layer retrievable="1" writable="1" ns_prefix="feature" ns_uri="http://www.tinyows.org/" schema="'.$layer['schema'].'" name="'.$layer['name'].'" title="'.$layer['title'].'" />';
				$fileContent .= '</tinyows>';
				file_put_contents(ROOT_PATH.$mapfileDir.$projectName.'/'.$layer['feature'].'.xml', $fileContent);
			}
		}
		
		//test sintassi mapfile
		ms_ResetErrorList();	
		$this->map = @ms_newMapObj($mapFilePath);
		$error = ms_GetErrorObj();
		if($error->code != MS_NOERR){
			$this->mapError=150;
			while(is_object($error) && $error->code != MS_NOERR) {
				$errorMsg = "MAPFILE ERROR $mapFile<br>".sprintf("Error in %s: %s<br>", $error->routine, $error->message);
				GCError::register($errorMsg);
				$error = $error->next();
			}
			return;
		}	
		
	}
	
	
	function _getPrintFormat(){
	
		$formatText ="
OUTPUTFORMAT
	NAME \"aggpng24\"
	DRIVER \"AGG/PNG\"
	MIMETYPE \"image/png\"
	IMAGEMODE RGB
	EXTENSION \"png\"	
	FORMATOPTION \"INTERLACE=OFF\"
	TRANSPARENT OFF
END";
		return $formatText;

	}
    
    function _isDriverSupported($driverName) {
        $mapserverSupport = ms_GetVersion();
        
        list($driver, $format) = explode('/', $driverName);
        
        // check on support
        if (preg_match_all ("/SUPPORTS=([A-Z_]+)/", $mapserverSupport, $supports)) {
            if (!in_array($driver, $supports[1]))
                return false;
        }
        
        // check on output
        if (preg_match_all ("/OUTPUT=([A-Z]+)/", $mapserverSupport, $outputs)) {
            if (!in_array($format, $outputs[1]))
                return false;
        }
        
        return true;
    }
	
	function _getOutputFormat($mapName){
            $formatText = '';
            $sql="select distinct e_outputformat.* from ".DB_SCHEMA.".e_outputformat;";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(); 
           // print_debug($sql);
            $numResults = $stmt->rowCount();
            if($numResults > 0) {
                while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
                    // ignore outputformat  with unsupported driver
                    if (!$this->_isDriverSupported($row["outputformat_driver"]))
                        continue;
                    $formatText .= "OUTPUTFORMAT	
	NAME \"".$row["outputformat_name"]."\"
	DRIVER \"".$row["outputformat_driver"]."\"
	MIMETYPE \"".$row["outputformat_mimetype"]."\"
	IMAGEMODE ".$row["outputformat_imagemode"] ."
	EXTENSION \"".$row["outputformat_extension"]."\"
	TRANSPARENT ON
	FORMATOPTION \"INTERLACE=OFF\"";
                    if($row["outputformat_option"]) $formatText.= "\n".$row["outputformat_option"];
                    $formatText .= "\nEND\n";	
                }
            } else {
                $formatText = file_get_contents (ROOT_PATH."config/mapfile.outputformats.inc");
            }
            return $formatText;
        }
    
	function _getEncoding(){
		$ows_wfs_encoding ='';
	    $sql = "select charset_encodings_name 
            from ".DB_SCHEMA.".e_charset_encodings INNER JOIN ".DB_SCHEMA.".project on e_charset_encodings.charset_encodings_id=project.charset_encodings_id 
            where project_name=:projectName";

        $stmt = $this->db->prepare($sql);
		$stmt->execute(array(':projectName' => $this->projectName));
		$res=$stmt->fetch(PDO::FETCH_ASSOC);
		if(!empty($res)) $ows_wfs_encoding = "\t\"wfs_encoding\"\t\"".$res['charset_encodings_name']."\"\n".
											"\t\t\"wms_encoding\"\t\"".$res['charset_encodings_name']."\"\n";
		return $ows_wfs_encoding;
	}
	
	
    function _getLegendSettings(){
        // default font
        $legendFont = 'arial';
        
        // get project font if assigned
        $sql="SELECT imagelabel_font FROM ".DB_SCHEMA.".project WHERE project_name = ?;";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array($this->projectName));

        $numResults = $stmt->rowCount();
        if($numResults > 0) {
            $row=$stmt->fetch(PDO::FETCH_ASSOC);
            if (trim($row['imagelabel_font']) != '')
                $legendFont = $row['imagelabel_font'];
        }
        
        // mapfile snippet
        $formatText = "LEGEND\n" .
                      "    STATUS ON\n" .
                      "    KEYSIZE 16 10\n" .
                      "    TRANSPARENT ON\n" .
                      "    LABEL\n" .
                      "       TYPE TRUETYPE\n" .
                      "       FONT '{$legendFont}'\n" .
                      "       SIZE 8\n" .
                      "       COLOR 1 1 1\n" .
                      "    END\n" .
                      "END\n";
		
		return $formatText;
	}
	
	function _getSymbolText($aSymbols){
                $_in = GCApp::prepareInStatement($aSymbols);
                $sqlParams = $_in['parameters'];
                $inQuery = $_in['inQuery'];

                $sql="select symbol_name,symbol_def from ".DB_SCHEMA.".symbol 
                    where symbol_name in (".$inQuery.");";
					
                $stmt = $this->db->prepare($sql);
                $stmt->execute($sqlParams);
                $res = $stmt->fetchAll();

		$smbText=array();	
		for($i=0;$i<count($res);$i++){
			$smbText[]="SYMBOL";
			$smbText[]="NAME \"".$res[$i]["symbol_name"]."\"";
			$smbText[]=$res[$i]["symbol_def"];
			$smbText[]="END";
		}
		$txt = "\n###### SYMBOLS #######\n";
		$txt.= implode("\n",$smbText);
		return $txt;
	}

	function _calculateExtentFromCenter($x, $y) {
		$sql = "SELECT proj4text FROM spatial_ref_sys WHERE srid=:projectSRID ;";
        $stmt = $this->db->prepare($sql);
		$stmt->execute(array(':projectSRID' => $this->projectSrid));
		$res=$stmt->fetch(PDO::FETCH_ASSOC);
		$proj4text = "+units=m";
		if(!empty($res)) $proj4text = $res["proj4text"];

		if(strpos($proj4text,"+units=m")!==false)
			$factor = GCAuthor::$aInchesPerUnit[5];
		elseif(strpos($proj4text,"+units=ft")!==false)
			$factor = GCAuthor::$aInchesPerUnit[2];
		elseif(strpos($proj4text,"+units=us-ft")!==false)
			$factor = GCAuthor::$aInchesPerUnit[2];	
		else
			$factor = GCAuthor::$aInchesPerUnit[7]; //fattore di conversione dpi->dd

		$maxResolution = $this->projectMaxScale/( MAP_DPI * $factor );
		return array(
			0 => $x - $maxResolution * TILE_SIZE,
			1 => $y - $maxResolution * TILE_SIZE,
			2 => $x + $maxResolution * TILE_SIZE,
			3 => $y + $maxResolution * TILE_SIZE
		);
		
	}
	
	function _transformExtent($toSrid){

		$sql = "SELECT X(ST_Transform(ST_SetSRID(ST_POINT(".$this->projectExtent[0].",".$this->projectExtent[1]."),".$this->projectSrid."),".$toSrid.")) as x0, Y(ST_Transform(ST_SetSRID(ST_POINT(".$this->projectExtent[0].",".$this->projectExtent[1]."),".$this->projectSrid."),".$toSrid.")) as y0, X(ST_Transform(ST_SetSRID(ST_POINT(".$this->projectExtent[2].",".$this->projectExtent[3]."),".$this->projectSrid."),".$toSrid.")) as x1, Y(ST_Transform(ST_SetSRID(ST_POINT(".$this->projectExtent[2].",".$this->projectExtent[3]."),".$this->projectSrid."),".$toSrid.")) as y1;";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$res=$stmt->fetch(PDO::FETCH_ASSOC);
		if(!empty($res)) return implode(" ",$res);
	
	}
	
	function _setMapProjections(){
		//COSTRUISCO UNA LISTA DI PARAMETRI PER OGNI SRID CONTENUTO NEL PROGETTO PER EVITARE DI CALCOLARLI PER OGNI LAYER 
		$sql="SELECT DISTINCT srid, projparam FROM ".DB_SCHEMA.".layer 
			INNER JOIN ".DB_SCHEMA.".catalog USING(catalog_id) 
			INNER JOIN ".DB_SCHEMA.".project_srs using(project_name)
            WHERE project_name = ?;";
		$stmt = $this->db->prepare($sql);
		$stmt->execute(array($this->projectName));
		while($row =  $stmt->fetch(PDO::FETCH_ASSOC)){
			$this->srsParams[$row["srid"]] = $row["projparam"];
		}

		//ELENCO DEI SISTEMI DI RIFERIMENTO NEI QUALI SI ESPONE IL SERVIZIO:
		$epsgList = array();
		$sql="SELECT id FROM ".DB_SCHEMA.".seldb_mapset_srid WHERE project_name = ?;";
		$stmt = $this->db->prepare($sql);
		$stmt->execute(array($this->projectName));
		while($row =  $stmt->fetch(PDO::FETCH_ASSOC)){
			$epsgList[] = "EPSG:".$row["id"];
		}
		$this->epsgList = implode(" ",$epsgList);
	}
	

}
?>
