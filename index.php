<?php
/*
DAZ 3D cataloging

Copyright (c) 2018-2020 William Baker, Ether Tear LLC
*/

/*
for file in $(grep -rl "\\\\n" --include=desc.html prods/); do rm $file; done
*/

$errstrlen = 32;
$resultsperpage = 50;
$skip_tags = ['bundle','tex','poses','script','fits'];

$allow_fulltext = false;  //too many items in your inventory? turn off full text searching
ini_set('memory_limit','2G');  //likely won't work on a cloud or leased server

$autotag_bundle = "/This product is a bundle/i";
$autotag_poses_inc = "/poses/i";
$autotag_poses_not = "/and/i";
$editors = [
	 'DAZ_Studio'=>"/(DAZ Studio|Install Manager|Daz Connect)/"
	,'DSON_Poser'=>"/DSON Importer for Poser/"
	,'Poser'=>"/Poser/"
	,'Bryce'=>"/Bryce/"
	,'Carrara'=>"/Carrara/"
	,
];
$installmethods = [
	 'DAZ_Connect'=>"/available through Daz Connect/i"
	,'Install_Manager'=>"/download &amp; install/i"
];
$NONEELEM = "[None]";

$cachetimeallow = 15;
$webtimeallow_img = 5;
$webtimeallow_store = 5;

if (file_exists('config.php')) { include('config.php'); } //allows easily overriding the above defaults (without affecting what is under git control)

session_start();

function SafeHTML($str) {
	return htmlspecialchars($str,ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML401);
}
function SafeFile($filepath) {
	if (!file_exists($filepath)) { return ""; }
	return file_get_contents($filepath);
}

$quickrefresh = false;
$allowautorefresh = false;
$makechange = false;
$recachetag = "";

$imgurl_regex = "/src=['\"\\\\]+(?P<url>.*?\.jpg)['\"\\\\]+/im";

if (!file_exists("prods")) { mkdir("prods"); }

if (isset($_REQUEST['serve']) && isset($_REQUEST['prodid'])) {
	if ($_REQUEST['prodid'] == "none") {
		echo "";
		exit();
	}
	if (!in_array($_REQUEST['serve'],['desc','store'])) {
		echo "invalid serve request";
		exit();
	}
	$serve = $_REQUEST['serve'];
	$prodid = intval($_REQUEST['prodid']);
	$fname = "prods/{$prodid}/{$serve}.html";
	if (file_exists($fname)) {
		$html = SafeFile($fname);
	} else {
		$html = "NO DATA FOR PRODUCT {$prodid}";
	}
	$from = ["/<script.*?>/i","/<\/script.*?>/","/max-height: 25vh;/"];
	$to   = ["<!-- "         ," -->"           ,""                   ];
	$from[] = "/<\/head/"; $to[] = "<style>
		.box-up-sell, .footer-container, .product-shop, .page, .side_prices { display:none; }
		.media { display:none; }
	</style></head";
	$from[] = "/<link/"; $to[] = "<meta";
	$from[] = "/<script/"; $to[] = "<meta";
	$from[] = "/<\/script/"; $to[] = "</meta";
	$from[] = "/href=['\"].*?\.css['\"]/"; $to[] = "";
	$from[] = "/<img .*?daz-logo-main.*?>/"; $to[] = "";
	if ($serve == 'desc') {
		$from[] = $imgurl_regex; $to[] = "src='prods/{$prodid}/preview.jpg'";
	}
	$html = preg_replace($from,$to,$html);

	echo $html;
	exit();
}

if (isset($_REQUEST['servetags'])) {
	$prodid = intval($_REQUEST['servetags']);
	$fname = "prods/{$prodid}/tags.txt";
	$fieldname = "tags".rand(1000000,9999999);
	$tagsafe = SafeHTML( file_exists($fname)?SafeFile($fname):"" );
?><form method='GET' action='<?=basename(__FILE__)?>' >
	<input type='submit' value='Update' />
	<input type='text' id='tagtext' name='<?=$fieldname?>' value='<?=$tagsafe?>' style='width:70%;' />
	<input type='hidden' name='tagsfield' value='<?=$fieldname?>' />
	<input type='hidden' name='prodid' value='<?=$_REQUEST['servetags']?>' />
	<input type='submit' value='Update' />
</form>
<script>document.getElementById('tagtext').focus();</script>
<?php
	if (isset($_REQUEST['tagup']) && $_REQUEST['tagup']) {
?>
<script>setTimeout(function(){
	window.top.tagup(<?=$_REQUEST['servetags']?>,'<?=$tagsafe?>');
},50);</script>
<?php
	} //endif tagup
	exit();
}

$tag_override = [];
if (file_exists("cache_tagsoverride.php")) { include("cache_tagsoverride.php"); }
if (isset($_REQUEST['tagsfield']) && isset($_REQUEST['prodid'])) {
	$_REQUEST['tags'] = $_REQUEST[$_REQUEST['tagsfield']];
	$prodid = intval($_REQUEST['prodid']);
	$fname = "prods/".intval($prodid)."/tags.txt";
	file_put_contents($fname,strtolower($_REQUEST['tags']));
	
	$recachetag = $prodid;
	//$makechange = true;
	$tag_override[$prodid] = [];
	foreach(explode(" ",$_REQUEST['tags']) as $tag) {
		$tag = trim($tag);
		if ($tag == "") { continue; }
		$tag_override[$prodid][$tag] = $tag;
	}
	
	file_put_contents("cache_tagsoverride.php","<?php \$tag_override = ".var_export($tag_override,true).";");
	
	$quickrefresh = ['servetags'=>$prodid,'tagup'=>true];
}

if (isset($_REQUEST['showloader'])) {
	$_SESSION['showloader'] = $_REQUEST['showloader'];
	$quickrefresh = true;
}
$showloader = (isset($_SESSION['showloader'])?$_SESSION['showloader']:0);

$owned = [];
if (isset($_REQUEST['owned'])) {
	$owned = JSON_Decode(trim($_REQUEST['owned'],'"\''),true);
	$makechange = true;
	$quickrefresh = true;
}
foreach($owned as $prodid) {
	if (file_exists("prods/{$prodid}")) { continue; }
	mkdir("prods/{$prodid}");
	$makechange = true;
}

if (isset($_REQUEST['desc'])) {
	try{
		$descobj = json_decode($_REQUEST['desc'],true);
		if (!is_array($descobj)) { //something went wrong with JSON decode
			error_log("ERROR processing JSON, reverting to exploding string");
			$rawlines = explode('","',trim($_REQUEST['desc'],"{}"));
			$descobj = [];
			foreach($rawlines as $rawline) {
				list($rawprod,$rawdesc) = explode('":"',$rawline);
				$prodid = trim($rawprod,'"');
				$desc = stripslashes(
					str_replace(["\\n","\\r","\\t","\\\""],["\n","\r","\t","\""],
						trim($rawdesc,'"')
					)
				);
				$descobj[$prodid] = $desc;
			}
		}
	} catch (Exception $e) {
		error_log("Errors in dealing with the JSON object");
		$descobj = [];
	}
	foreach($descobj as $prodid_padded=>$desc) {
		$prodid = substr($prodid_padded,1);
		file_put_contents("prods/{$prodid}/desc.html",$desc);
	}
	$makechange = true;
	$quickrefresh = true;
}

if (isset($_REQUEST['nukecache'])) {
	//unlink("cache.php");
	exec('for file in $(grep -rl "\\\\\\\\n" --include=desc.html prods/); do rm $file; done');
	exec('rm prods/*/noimg');
	exec('rm prods/*/nostore');
	file_put_contents("cache.php",""); //so that permissions can survive the nuke
	//$makechange = true;
	//$quickrefresh = true;
}

function SanitizeBigText($txt) {
	
	$txt = preg_replace("/&.*;/","_",$txt);
	$txt = str_replace(["\n","\r","\t"]," ",$txt);
	
	$txt = preg_replace("/<script.*?\/script.*?>/m","",$txt);
	$txt = preg_replace("/\/\*.*?\*\//m","",$txt);
	$txt = preg_replace("/<!--\[.*?\]>/","",$txt);
	$txt = preg_replace("/<!--.*?-->/m","",$txt);
	
	$txt = strip_tags($txt);

	$len = strlen($txt);
	do {
		$olen = $len;
		$txt = str_replace("  "," ",$txt);
		$len = strlen($txt);
	} while ($olen != $len);
	
	return $txt;
}

$total = []; $tagindex = []; $wordindex = [];
if (file_exists("cache.php")) { include("cache.php"); }
if (empty($total) || $showloader || $makechange) {
	$maxatonce = 40;
	$remaining = [];
	$imgremain = []; $imgerrors = [];
	$storeremaining = []; $storeerrors = [];
	$methodremaining = [];
	$maxtime = time()+$cachetimeallow;
	$prodcount = count(scandir("prods"))-2;
	foreach(scandir("prods") as $prodid) {
		if (time() > $maxtime) { print "Caching timeout at prod id {$prodid}, total ".count($total)." expecting ".$prodcount; $quickrefresh = false; break; }
		if ($recachetag && $recachetag != $prodid) { continue; }
		if (substr($prodid,0,1) == ".") { continue; }
		$obj = (isset($total[$prodid])?$total[$prodid]:[]);
		
		$autotags = [];
		if (!isset($obj['tags'])) { $obj['tags'] = []; }
		
		$obj['sku'] = $prodid;
		
		if (!isset($obj['gotdesc'])) { $obj['gotdesc'] = false; }
		if (!isset($obj['desc_mtime'])) {
			$fname = "prods/{$prodid}/desc.html";
			$obj['gotdesc'] = (file_exists($fname) && filesize($fname) >= $errstrlen);
			if ($obj['gotdesc']) {
				$desc_html = SafeFile($fname);
				if($allow_fulltext) $obj['desc_srch'] = SanitizeBigText($desc_html);
				$obj['desc_mtime'] = filemtime($fname);
				
				if (preg_match($autotag_bundle,$desc_html)) {
					$autotags[] = 'bundle';
				}
				if (preg_match($autotag_poses_inc,$desc_html) && !preg_match($autotag_poses_not,$desc_html)) {
					$autotags[] = 'poses';
				}
			}
		}
		$obj['procdesc'] = ($obj['gotdesc']);
		if (!$obj['procdesc']) { $remaining[] = $prodid; }
		
		if (!isset($obj['gotimg'])) { $obj['gotimg'] = false; $obj['errimg'] = false; }
		if (!isset($obj['img_mtime'])) {
			$fname = "prods/{$prodid}/preview.jpg";
			$obj['gotimg'] = (file_exists($fname) && filesize($fname) >= $errstrlen);
			$ename = "prods/{$prodid}/noimg";
			$obj['errimg'] = (file_exists($ename));
			if ($obj['errimg']) { $obj['gotimg'] = false; }
			$obj['img'] = ($obj['gotimg']?$fname:"notfound.png");
			if ($obj['gotimg']) {
				$obj['img_mtime'] = filemtime($fname);
			} else if ($obj['errimg']) {
				$obj['img_mtime'] = "ERROR";
			}
		}
		if ($obj['errimg']) { $imgerrors[] = $prodid; }
		$obj['procimg'] = ($obj['gotimg'] || $obj['errimg']);
		if (!$obj['procimg']) { $imgremain[] = $prodid; }
		
		if (!isset($obj['gotstore'])) { $obj['gotstore'] = false; $obj['errstore'] = false; }
		if (!isset($obj['store_mtime'])) {
			$fname = "prods/{$prodid}/store.html";
			$obj['gotstore'] = (file_exists($fname) && filesize($fname) >= $errstrlen);
			$ename = "prods/{$prodid}/nostore";
			$obj['errstore'] = (file_exists($ename));
			if ($obj['errstore']) { $obj['gotstore'] = false; }
			if ($obj['gotstore']) {
				$store_html = SafeFile($fname);
				if($allow_fulltext) $obj['store_srch'] = SanitizeBigText($store_html);
				$obj['store_mtime'] = filemtime($fname);
			} else if ($obj['errstore']) {
				$obj['store_srch'] = "";
				$obj['store_mtime'] = "ERROR";
			} else {
				$obj['store_srch'] = "";
			}
		}
		if ($obj['errstore']) { $storeerrors[] = $prodid; }
		$obj['procstore'] = ($obj['gotstore'] || $obj['errstore']);
		if (!$obj['procstore']) { $storeremaining[] = $prodid; }
		
		if (!isset($obj['tag_mtime']) || $recachetag == $prodid || isset($tag_override[$prodid])) {
			$fname = "prods/{$prodid}/tags.txt";
			$obj['gottags'] = (file_exists($fname) && filesize($fname) > 0);
			if ($obj['gottags']) {
				$tags_raw = trim(SafeFile($fname));
				$obj['tags'] = [];
				foreach(explode(" ",$tags_raw) as $tag) {
					$tag = trim($tag);
					if ($tag == '') { continue; }
					$obj['tags'][$tag] = $tag;
				}
				$obj['tag_mtime'] = filemtime($fname);
			} else {
				$obj['tags'] = [];
				$obj['tag_mtime'] = 'NONE';
			}
			$obj['tagcount'] = count($obj['tags']);
			
			foreach($obj['tags'] as $tag) {
				if (!isset($tagindex[$tag])) { $tagindex[$tag] = []; }
				$tagindex[$tag][$prodid] = $prodid;
			}
		}
		
		if (!isset($obj['title'])) { $obj['title'] = ""; }
		if ($obj['title'] == "" && $obj['gotdesc']) {
			$desc_html = SafeFile("prods/{$prodid}/desc.html");
			$ok = preg_match("/<h2(.*?)>(?P<title>.*?)<\/h2>/i",$desc_html,$match);
			if ($ok) {
				$obj['title'] = $match['title'];
			}
		}
		
		if (!isset($obj['imgurl']) && $obj['gotdesc']) {
			$desc_html = SafeFile("prods/{$prodid}/desc.html");
			$ok = preg_match($imgurl_regex,$desc_html,$match);
			$obj['imgurl'] = ($ok?$match['url']:"");
		}
		
		if ($autotags) {
			foreach($autotags as $atag) {
				if (isset($obj['tags'][$atag])) { continue; } //already known/saved
				$obj['tags'][$atag] = $atag;
				$obj['tagcount'] = count($obj['tags']);
				file_put_contents("prods/{$prodid}/tags.txt"," ".implode(" ",$obj['tags'])." ");
				error_log("INFO: autotags updated {$prodid}: {$atag}");
				if (!isset($tagindex[$atag])) { $tagindex[$atag] = []; }
				$tagindex[$atag][$prodid] = $prodid;
			}
		}

		if (!isset($obj['methods']) && $obj['procdesc']) {
			$desc_html = SafeFile("prods/{$prodid}/desc.html");
			//  Flying Steamer 8596 does not have DAZ Connect, nor do "Bryce" items
			$obj['methods'] = [];
			foreach($installmethods as $key=>$regex) {
				if (preg_match($regex,$desc_html)) {
					$obj['methods'][$key] = $key;
				}
			}
		}
		
		if (!isset($obj['editors']) && $obj['procdesc'] && $obj['procstore']) {
			$obj['editors'] = [];
			$desc_html = SafeFile("prods/{$prodid}/desc.html");
			$store_html = SafeFile("prods/{$prodid}/store.html");
			$desc_fix = preg_replace("/DAZ Studio/","",$desc_html); //most of the desc will contain "Daz Studio" when mentioning Daz Connect
			$store_fix = $store_html;
			$sub = $desc_fix.$store_fix;
			foreach($editors as $key=>$regex) {
				$good = preg_match($regex,$sub);
				if ($key == "Poser") { $good = $good && !preg_match($editors['DSON_Poser'],$sub); }
				if ($good) {
					$obj['editors'][$key] = $key;
				}
			}
		}
		
		if (!isset($obj['pdate']) && $obj['procdesc']) {
			$desc_html = SafeFile("prods/{$prodid}/desc.html");
			$ok = preg_match("/Order:.*?&ndash; (\d+.*?\d\d\d\d)/i",$desc_html,$match);
			if ($ok) {
				$obj['pdate'] = date("Y-m-d",strtotime($match[1]));
			}
		}
		
		if (!isset($obj['wordindex_mtime']) && $obj['procdesc'] && $obj['procstore']) {
			$windex = [];
			$desc_html = SafeFile("prods/{$prodid}/desc.html");
			$store_html = SafeFile("prods/{$prodid}/store.html");
			$allwords = SanitizeBigText($desc_html).' '.SanitizeBigText($store_html);
			$allwords = strtolower($allwords);
			$allwords = preg_replace("/[^a-z0-9 ]/"," ",$allwords);
			$breakwords = explode(" ",$allwords);
			$breakwords[] = "";
			foreach($breakwords as $i=>$word) {
				if (!preg_match("/[a-z]/",$word)) { continue; }
				if (substr($word,0,4) == "http") { continue; }
				if ($word == 'sku') { continue; }
				if ($word == 'to')  { continue; }
				if (!isset($windex[$word])) { $windex[$word] = 0; }
				$windex[$word]++;
				
				//for capturing things like "Genesis 3" as a captured term
				$next = $breakwords[$i+1];
				if (is_numeric($next)) {
					$vword = $word.' '.$next;
					if (!isset($windex[$vword])) { $windex[$vword] = 0; }
					$windex[$vword]++;
				}
			}
			//$obj['wordindex'] = $windex; //no need to save it, since we'll have the main index
			$obj['wordindex_mtime'] = time();
			
			/*/ disable the global index: 
			foreach($windex as $word=>$count) {
				if (!isset($wordindex[$word])) { $wordindex[$word] = []; }
				$wordindex[$word][$prodid] = $count;
			}
			/**/
			
			//greppable file
			file_put_contents("prods/{$prodid}/words.txt"," ".implode(" ",array_keys($windex))." ");
		}
		
		$total[$prodid] = $obj;
	}
	
	$tag_override = [];
	if(file_exists("cache_tagsoverride.php")) {
		//unlink("cache_tagsoverride.php");
		file_put_contents("cache_tagsoverride.php",""); //so that permissions can survive the nuke
	}
	
	file_put_contents("cache.php",'<?php 
		$total = '.var_export($total,true).';
		$tagindex = '.var_export($tagindex,true).';
		$wordindex = '.var_export($wordindex,true).';
	');
	$remaining_max = []; foreach($remaining as $r) { $remaining_max[] = $r; if (count($remaining_max) >= $maxatonce) { break; }}
	$remaining_json = json_encode($remaining_max);
	if ($prodcount != count($total)) { $allowautorefresh = true; }
} //need to build cache, or update it

if (!empty($tag_override)) {
	foreach($tag_override as $prodid=>$otags) {
		foreach($total[$prodid]['tags'] as $tag) {
			unset($tagindex[$tag][$prodid]);
		}
		$total[$prodid]['tags'] = $otags;
		$total[$prodid]['tagcount'] = count($otags);
		foreach($otags as $tag) {
			if (!isset($tagindex[$tag])) { $tagindex[$tag] = []; }
			$tagindex[$tag][$prodid] = $prodid;
		}
	}
}
unset($tagindex[""]);

if (empty($total)) {
	$showloader = true;
	$_SESSION['showloader'] = $showloader;
	$quickrefresh = true;
}


if (isset($_REQUEST['selector'])) {
	echo "<!DOCTYPE html><html><head><title>Select</title></head><body>";
	
	echo "<div id='load_blocker' style='display:block;cursor:wait;position:fixed;top:0px;bottom:0px;left:0px;right:0px;background:#666666;opacity:0.5;' onclick='return false;'></div>";
	
	$base = [];  $countuse = false;
	if ($_REQUEST['selector'] == 'words') { $base = $wordindex; $countuse = true; }
	if ($_REQUEST['selector'] == 'tags') { $base = $tagindex;  $countuse = false; }
	ksort($base);
	
	$wcounts = [];
	foreach($base as $word=>$prods) { $wcounts[$word] = count($prods); }
	arsort($wcounts);
	
	$cols = [];

	foreach([$wcounts,$base] as $src) {
		$lines = [];
		foreach($src as $word=>$ignore) {
			$prods = $base[$word];
	
			$line = [];
			$line[] = "<a ";
			$line[] = "onclick='window.parent.selclick(\"".SafeHTML($word)."\");return false;' >";
			$line[] = SafeHTML($word);
			$line[] = " (".count($prods);
			if ($countuse) { $line[] = ", ".array_sum($prods); }
			$line[] = ")";
			$line[] = "</a>";
			
			$lines[] = implode("",$line);
		}
		$cols[] = "<ul><li>".implode("</li>\n<li>",$lines)."</li></ul>";
	}
	
	echo "<table width='100%'><tr>";
	foreach($cols as $col) {
		echo "<td width='".floor(100/count($cols))."%' valign='top' align='left'>{$col}</td>";
	}
	echo "</tr></table>";
	
	echo "<script>document.getElementById('load_blocker').style.display = 'none';</script>";
	
	echo "</body></html>";
	exit();
}


$searchids = [];

function ReqSes($key,$def) {
	if (isset($_REQUEST['clear']) && $_REQUEST['clear']) { return $def; }
	if (isset($_REQUEST[$key])) { return $_REQUEST[$key]; }
	if (isset($_SESSION[$key])) { return $_SESSION[$key]; }
	return $def;
}
function ExList($str) {
	$list = [];
	foreach(explode(' ',$str) as $opt) {
		if ($opt == '') { continue; }
		$list[$opt] = $opt;
	}
	return $list;
}
function cmp_search($a,$b) {
	//sort highest rate to lowest
	return $b['rate'] - $a['rate'];
}

$search_locations = ['tags','title','desc','store'];

$search = ReqSes('search',"");  if (!$allow_fulltext) $search = "";
$index_words = ExList(ReqSes('index_words',""));
$index_tags  = ExList(ReqSes('index_tags' ,""));

$bylowtags = ($search == "" && empty($index_words) && empty($index_tags));

$searchin = ReqSes('searchin',$search_locations);

$skippers = [];
$skippers['tags'] = ReqSes('skippers_tags',$skip_tags);

$filters = [];
$filters['editors'] = ReqSes('filters_editors',array_merge(array_keys($editors),[$NONEELEM]));
$filters['installers'] = ReqSes('filters_installers',array_merge(array_keys($installmethods),[$NONEELEM]));

function searchproc($words,$indexOrFilename) {
	global $total;
	
	$hits = [];
	$firstindex = true;
	$maxwords = count($words);
	foreach($words as $i=>$word) {
		$score = $maxwords-$i;
		$action = "OR";
		$mod = substr($word,0,1);
		if ($mod == '&') { $action = "AND"; }
		if ($mod == '-') { $action = "SUB"; }
		if ($action != "OR") { $word = substr($word,1); }
		
		if ($firstindex && $action == "AND") { $action = "OR"; }
		if ($firstindex && $action == "SUB") { //first select everything, in order to subtract from it.
			foreach($total as $prodid=>$obj) { $hits[$prodid] = $score; }
		}
		
		$keys = [];
		if (is_array($indexOrFilename)) { //index
			$keys = isset($indexOrFilename[$word])?$indexOrFilename[$word]:[];
		} else { //filename
			$cmd = "grep -iFrc --include={$indexOrFilename} ".escapeshellarg(" $word ")." prods/";
			$searchhandle = popen($cmd,"r");
			$filehits = "";
			while (!feof($searchhandle)) { 
				$filehits .= fgets($searchhandle, 4096); 
			}
			pclose($searchhandle);
			
			foreach(explode("\n",$filehits) as $file) {
				$ok = preg_match("/prods\/(?P<prod>\d+)\/.*:(?P<count>\d+)/",$file,$match);
				if ($ok) {
					if ($match['count'] == 0) { continue; }
					$keys[$match['prod']] = intval($match['count']);
					$hits[$match['prod']] += 10;
					
					if (strpos(strtolower($total[$match['prod']]['title']),strtolower($word)) !== false) {
						$hits[$match['prod']] += 20;
					}
				}
			}
		}
		if ($action == "OR") foreach($keys as $key=>$count) {
			if (!isset($hits[$key])) { $hits[$key] = 0; }
			$hits[$key] += $score;
		}
		if ($action == "SUB") foreach($keys as $key=>$count) { unset($hits[$key]); }
		if ($action == "AND") {
			foreach($hits as $key=>$ignore) {
				if (isset($keys[$key])) { $keys[$key] += $score; continue; }
				unset($hits[$key]);
			}
		}
		
		$firstindex = false;
	}
	return $hits;
}


$newsearch = (isset($_REQUEST['newsearch'])?$_REQUEST['newsearch']:(isset($_SESSION['searchids'])?0:1));
if ($newsearch) {
	
	$wfinder = searchproc($index_words,'words.txt');
	$tfinder = searchproc($index_tags,$tagindex);
	
	$regex = "/{$search}/i";
	$maxtags = count($tagindex);
	foreach($total as $prodid=>$obj) {
		$idobj = ['prodid'=>$prodid,'rate'=>0,'rel'=>''];
		
		foreach($skippers['tags'] as $tag) {
			if (isset($obj['tags'][$tag])) { continue 2; }
		}
		
		$foundone = false;
		foreach($filters['editors'] as $editor) {
			if (isset($obj['editors'][$editor])) { $foundone = true; break; }
		}
		if (empty($obj['editors']) && in_array($NONEELEM,$filters['editors'])) { $foundone = true; }
		if (!$foundone) { continue; }
		
		$foundone = false;
		foreach($filters['installers'] as $installer) {
			if (isset($obj['methods'][$installer])) { $foundone = true; break; }
		}
		if (empty($obj['methods']) && in_array($NONEELEM,$filters['installers'])) { $foundone = true; }
		if (!$foundone) { continue; }
		
		if (isset($tfinder[$prodid])) {
			$idobj['rate'] += 53+$tfinder[$prodid];
			$idobj['rel'] = trim($idobj['rel']." tags");
		}
		
		if (isset($wfinder[$prodid])) {
			$idobj['rate'] += 37+$wfinder[$prodid];
			$idobj['rel'] = trim($idobj['rel']." words");
		}
		
		$rate = 128;
		if($allow_fulltext && $search != "") foreach($searchin as $key) {
			if ($key == '.ignore') { continue; }
			$rate /= 2;
			$key_srch = "{$key}_srch";
			if (!isset($obj[$key]) && !isset($obj[$key_srch])) { continue; }
			
			if (!isset($obj[$key_srch])) { $key_srch = $key; }
			$text = (is_array($obj[$key_srch])?implode(" ",$obj[$key_srch]):$obj[$key_srch]);
			
			$ok = preg_match($regex,$text);
			if (!$ok) { continue; }

			$idobj['rate'] += $rate;
			$idobj['rel'] = trim($idobj['rel']." ".$key);
		}
		
		if ($bylowtags) {
			$tagcount[$prodid] = $obj['tagcount'];
			$idobj['rate'] += $maxtags - $tagcount[$prodid];
			$idobj['rel'] = trim($idobj['rel']." lowtags");
		}
		
		if ($idobj['rate'] == 0) { continue; }
		
		$searchids[$prodid] = $idobj;
	}

	usort($searchids,'cmp_search');
	$_SESSION['search'] = $search;
	$_SESSION['index_words'] = implode(" ",$index_words);
	$_SESSION['index_tags'] = implode(" ",$index_tags);
	$_SESSION['searchin'] = $searchin;
	$_SESSION['skippers_tags'] = $skippers['tags'];
	$_SESSION['filters_editors'] = $filters['editors'];
	$_SESSION['filters_installers'] = $filters['installers'];
	$_SESSION['searchids'] = $searchids;
	$quickrefresh = true;
} else {
	$searchids = $_SESSION['searchids'];
}



if ($quickrefresh) {
	$url = basename(__FILE__);
	if (is_array($quickrefresh)) { $url .= "?".http_build_query($quickrefresh); }
	header("Location: {$url}");
	exit();
}



?>
<html>
<head>
<title>DAZ Catalog</title>
</head>
<body>



<?php
/*
echo "<pre>".substr(var_export($_REQUEST,true),0,200)."</pre>";

echo "<pre>".substr(var_export($_SESSION,true),0,200)."</pre>";
*/
?>






<?php if ($showloader) { ?>

<form method='POST'>
<input type='hidden' name='showloader' value='0' />
<input type='submit' value='Hide Loader' />
</form>



Go to <a href='https://www.daz3d.com/downloader/customer/files' target='_blank'>Daz3D Product Library</a> and perform the following steps via Debugger (F12):

<form method='POST' style='border:1px solid green;'>
<table><tr><td>
<textarea name='instruct' style='width:400px;height:30px;'>JSON.stringify(daz.api.data['Catalog/owned'].owned)</textarea><br/>
Then copy the results, and paste them here &rarr;
</td><td>
<textarea name='owned' style='width:250px;height:50px;'></textarea><br/>
<input type='submit' value='Update' />
</td></tr></table>
</form>

Total Products: <?=count($total)?><br/>
Total Words: <?=count($wordindex)?> (disabled)<br/>
Total Tags: <?=count($tagindex)?><br/>


<?php if ($remaining) {
echo "<br/>\n";
echo "Total Remaining: ".count($remaining)."<br/>\n";
if (count($remaining) > $maxatonce) { echo "Will only process {$maxatonce} at a time due to clipboard limitations, so multiple runs are necessary.<br/>\n"; }
$harvestscript = <<<JSC
var dzc = {};
dzc.remaining = {$remaining_json}
dzc.desc = {};
dzc.cut = false;
dzc.run = function() {
	if (dzc.remaining.length == 0 || dzc.cut) {
		var results = JSON.stringify(dzc.desc);
		console.log(results);
		try {
			let textarea = document.createElement('textarea');
			textarea.setAttribute('type', 'hidden');
			textarea.textContent = results;
			document.body.appendChild(textarea);
			textarea.select();
			document.execCommand('copy');
			// console.log("copied to clipboard");
		} catch (err) {
			console.log("unable to copy to clipboard");
		}
		return false;
	}
	var prodid = dzc.remaining.pop();
	$.ajax({
		dataType: "json"
		,url: "https://www.daz3d.com/downloader/customer/ajaxfiles/prod/"+prodid
		,data: {}
		,success: function(data){
			setTimeout(dzc.run,100);
			if (data[0] != "OK") {	return;	}
			dzc.desc["p"+prodid] = data[1];
		}
		,error: function(jqXHR, textStatus, errorThrown ) {
			setTimeout(dzc.run,100);
		}
	});
	return "Please wait for process to complete, or run dzc.stop() to quit early";
}; //end dzc.run()
dzc.stop = function() {
	dzc.cut = true;
	return "Please wait a moment for process to reach a stopping place."
}; //end dzc.stop()
clear();
dzc.run();
JSC;
?>
<form method='POST' style='border:1px solid green;'>
<table><tr><td>
Go to <a href='https://www.daz3d.com/downloader/customer/files' target='_blank'>Daz3D Product Library</a> and perform the following steps via Debugger (F12):
<textarea name='instruct' style='width:300px;height:100px;'><?=SafeHTML($harvestscript)?></textarea>
<br/>This will take a while to run, use "dzc.stop();" to quit early.
<br/>Then copy the results, and paste them here &rarr;
</td><td>
<textarea name='desc' style='width:300px;height:120px;'></textarea><br/>
<input type='submit' value='Update' />
</td></tr></table>
</form>

<?php } //if ($remaining) ?>



<?php if ($imgremain) {
echo "<br/>\n";
echo "Images Remaining to be cached: ".count($imgremain)."<br/>\n";
$imgdown = 0;
$timeout = time()+$webtimeallow_img;
foreach($imgremain as $prodid) {
	if (time() >= $timeout) { break; }
	if (!isset($total[$prodid])) { continue; }
	if (!isset($total[$prodid]['procdesc'])) { continue; }
	
	$fname = "prods/{$prodid}/desc.html";
	$iname = "prods/{$prodid}/preview.jpg";
	$noimg = "prods/{$prodid}/noimg";
	if (file_exists($iname) && filesize($iname) >= $errstrlen) { continue; }
	if (!file_exists($fname)) { continue; }
	if (file_exists($noimg)) { continue; }

	if (!isset($total[$prodid]['imgurl'])) { touch($noimg); echo "Error finding preview image filename for {$prodid}<br/>\n"; continue; }
	if ($total[$prodid]['imgurl']) { copy($total[$prodid]['imgurl'],$iname); }
	if (!file_exists($iname)) { touch($noimg); echo "Error copying preview image for {$prodid}<br/>\n"; continue; }
	if (filesize($iname) < $errstrlen) { echo "Error, incomplete preview image for {$prodid}<br/>\n"; continue; }
	
	$imgdown++;
}
echo "Downloaded {$imgdown} this cycle<br/>\n";
$allowautorefresh = true;
} //if ($imgremain) ?>

<?php if ($storeremaining) {
echo "<br/>\n";
echo "Store pages remaining to be cached: ".count($storeremaining)."<br/>\n";
$storedown = 0;
$timeout = time()+$webtimeallow_store;
foreach($storeremaining as $prodid) {
	if (time() >= $timeout) { break; }
	
	$fname = "prods/{$prodid}/desc.html";
	$sname = "prods/{$prodid}/store.html";
	$nostr = "prods/{$prodid}/nostore";
	if (file_exists($sname) && filesize($sname) >= $errstrlen) { continue; }
	if (!file_exists($fname)) { continue; }
	if (file_exists($nostr)) { continue; }

	$desc = SafeFile($fname);
	$ok = preg_match("/href=['\"](?P<url>.*?)['\"].*?\>View Product( Store)? Page/im",$desc,$matches);
	if (!$ok) { touch($nostr); echo "Error finding store page for {$prodid}<br/>\n"; continue; }
	
	copy("https://daz3d.com".$matches['url'],$sname);
	if (!file_exists($sname)) { touch($nostr); echo "Error copying store page for {$prodid}<br/>\n"; continue; }
	if (filesize($sname) < $errstrlen) { echo "Error, incomplete store page for {$prodid}<br/>\n"; continue; }
	
	$storedown++;
}
echo "Downloaded {$storedown} this cycle<br/>\n";
$allowautorefresh = true;
} //if ($storeremaining) ?>




<?php if ($allowautorefresh) { ?>
<br/>
<form method='POST' id='autorefreshform'>
Auto-refresh until all items are cached:
<input type='hidden' name='autorefresh' value='1' />
<?php if (isset($_REQUEST['autorefresh']) && $_REQUEST['autorefresh']) { ?>
running.... <script>setTimeout(function() {
	var aform = document.getElementById('autorefreshform');
	aform.submit();
},200);</script> Use your browser's "Stop" action to interrupt this cycle.
<?php } else { //autorefresh ?>
<input type='submit' value='Go' />
<?php } //autorefresh ?>
</form>
<?php } //if allowautorefresh ?>
























<form method='POST'>
<input type='hidden' name='nukecache' value='1' />
<input type='submit' value='Rebuild Cache' style='background:#ffcccc;' />
</form>


<?php //sorta handy way to debug
//	echo "<pre style='background:#eeeeff;'>".SafeHTML(var_export($total[2894],true))."</pre>";
//	echo "<pre style='background:#eeeeff;'>".SafeHTML(var_export($wordindex,true))."</pre>";
//	echo "<pre style='background:#eeeeff;'>".SafeHTML(var_export($tagindex,true))."</pre>";
?>

<?php } //if (showloader) ?>















<?php if (!$showloader) { ?>

<label style='float:right;'>Auto-open next on tag save<input type='checkbox' id='autonext' value='1' /></label>

<form method='POST'>
<input type='hidden' name='showloader' value='1' />
<input type='submit' value='Show Loader' />
<?php //if (!empty($tag_override)) { echo "&larr; to cache Tag updates"; } ?>
</form>


<?php
$page = (isset($_REQUEST['page'])?intval($_REQUEST['page']):1);

$pagehtml = "<center><span style='display:inline-block;white-space:nowrap;border:1px solid black;padding:8px;'>Page: ";
$pquery = $_GET;
for($p = 1; $p <= ceil(count($searchids)/$resultsperpage); $p++) {
	if ($p != $page) {
		$pquery['page'] = $p;
		$pagehtml .= "<a href='index.php?".http_build_query($pquery)."'>";
	} else {
		$pagehtml .= "<span style='border:1px solid black;padding:5px;'>";
	}
	$pagehtml .= "&nbsp;{$p}&nbsp;";
	if ($p != $page) {
		$pagehtml .= "</a>";
	} else {
		$pagehtml .= "</span>";
	}
	$pagehtml .= " ";
}
$pagehtml .= "</span></center>";

?>

<?php if ($bylowtags) { ?>
<center style='border:1px solid green;padding:5px;white-space:nowrap;'>No search query, so organizing by least tagged</center>
<?php } ?>

<style>
	label { white-space:nowrap; border-radius:4px; border:1px solid #999999; padding:4px; margin:4px; display:inline-block; }
	.listpop { border:1px solid blue; border-radius:4px; padding:2px; color:blue; }
</style>
<form method='POST' style='border:3px solid black;padding:10px;margin:10px;'>
<table border='0'><tr><td>
<input type='hidden' name='newsearch' value='1' />
<input type='submit' value='New Search' />
<input type='hidden' id='clearinput' name='clear' value='0' />
<input type='submit' value='Clear' name='clear' onclick="document.getElementById('clearinput').value=1;return true;"/>
<a href='#' onclick='document.getElementById("inst").style.display="block";return false;'>Show Search Instructions</a>
</td></tr>

<tr id='inst' style='display:none;'><td><ul>
<li>Each search is processed separately, effectively unioning the results.  Tag index is worth more "points" than word index.</li>
<li>Index searches will be processed in order<ul>
	<li>performing "OR" (Union) with no modifiers</li>
	<li>performing "AND" (Intersect) with "&amp;" modifier<ul>
		<li>if the first tag has the "&amp;" modifier, it will be treated similar to the "OR" operation</li>
	</ul></li>
	<li>performing "SUB" (subtraction) with "-" modifier.<ul>
		<li>if the first tag has the "-" modifier, it will subtract them from all items</li>
	</ul></li>
</ul></li>
<li>Example (tag index): "test1 &amp;test2 -test3 test4"<ul>
	<li>Would be equivalent to:  ((((test1) AND test2) SUB test3) OR test4)<ol>
		<li>get all items with tag "test1"</li>
		<li>intersect them with all with tag "test2"</li>
		<li>remove all with tag "test3"</li>
		<li>union with all that have "test4" (regardless of whether they have "test2" or "test3" also)</li>
	</ul></li>
</ul></li>
<li>Search results are cached<ul>
	<li>changes to tags will not affect your search results</li>
	<li>uploading new items to your collection will not affect your search results</li>
	<li>clicking "New Search" will re-use the current search parameters, but against any updated data (catalog and tags)</li>
</ul></li>
<li>When no search parameters are provided, items will be sorted by least tagged.<ul>
	<li>other filters will still apply, such as tag skipping, editors, and installers</li>
</ul></li>
</ul></td></tr>

<?php if ($allow_fulltext) { ?>
<tr><td>
<label >Search (regex): /<input type='text' name='search' value='<?=SafeHTML($search)?>' />/i</label>
<br/>Search In:
<input type='hidden' name='searchin[]' value='.ignore' />
<?php foreach($search_locations as $srch) { ?>
<label><?=ucwords($srch)?>:  <input type='checkbox' name='searchin[]' value='<?=$srch?>' <?=(in_array($srch,$searchin)?'checked':'')?> /></label>
<?php } //search in ?>
</td></tr>
<?php } //$allow_fulltext ?>

<tr><td>
<label>Index (words): <input type='text' id='index_words' name='index_words' value='<?=SafeHTML(implode(" ",$index_words))?>' /> <a class='listpop' onclick='listpop("words");return false;'>Selector</a></label>
</td></tr>

<tr><td>
<label>Index (tags): <input type='text' id='index_tags' name='index_tags' value='<?=SafeHTML(implode(" ",$index_tags))?>' /> <a class='listpop' onclick='listpop("tags");return false;'>Selector</a></label>
</td></tr>

<tr><td>
Skip Tags: 
<input type='hidden' name='skippers_tags[]' value='.hidden' />
<?php foreach($skip_tags as $stag) { ?>
<label><?=ucwords($stag)?>: <input type='checkbox' name='skippers_tags[]' value='<?=$stag?>' <?=(in_array($stag,$skippers['tags'])?'checked':'')?>/></label>
<?php } //skip tags ?>
</td></tr>

<tr><td>
Editors:
<input type='hidden' name='filters_editors[]' value='.hidden' />
<?php foreach(array_merge($editors,[$NONEELEM=>'']) as $key=>$regex) { ?>
<label><?=SafeHTML($key)?>: <input type='checkbox' name='filters_editors[]' value='<?=SafeHTML($key)?>' <?=(in_array($key,$filters['editors'])?'checked':'')?>/></label>
<?php } //filter editors ?>
</td></tr>

<tr><td>
Installers:
<input type='hidden' name='filters_installers[]' value='.hidden' />
<?php foreach(array_merge($installmethods,[$NONEELEM=>'']) as $key=>$regex) { ?>
<label><?=SafeHTML($key)?>: <input type='checkbox' name='filters_installers[]' value='<?=SafeHTML($key)?>' <?=(in_array($key,$filters['installers'])?'checked':'')?>/></label>
<?php } //filter editors ?>
</td></tr>

</table>
</form>

<?=$pagehtml?>
<table border='1' cellspacing='0' cellpadding='3' width='100%'><thead>
<tr><th>Img.</th><th>Title</th><th>Tags</th><th>...</th><th>Editor</th><th>Install</th><th>Rel.</th><th>Rate</th></tr>
</thead><tbody>
<?php
$usedids = [];
for($i = $resultsperpage*($page-1); $i < min($resultsperpage*$page,count($searchids)); $i++) {
	$stats = $searchids[$i];
	$prodid = $stats['prodid'];
	$usedids[] = $prodid;
	$obj = $total[$prodid];  ?>
<tr onclick="zpop(<?=$prodid?>);return false;">
<td align='center' valign='middle' width='70' style='padding:0px;'><a href="<?=$prodid?>" onclick='zpop(<?=$prodid?>);return false;'><img src='<?=SafeHTML($obj['img'])?>' width='70' height='91' border='0' /></a></td>
<td width="10%"><?=SafeHTML($obj['title'])?></td>
<td width="10%" id='tags_<?=$prodid?>'><?=SafeHTML(implode(" ",$obj['tags']))?></td>
<td width="70%"></td>
<td width="5%" style='background:<?=(isset($obj['editors']['DAZ_Studio'])?'#eeffee':"#ffcccc")?>;'><?=implode(" ",(isset($obj['editors'])?$obj['editors']:[]))?></td>
<td width="5%" style='background:<?=(isset($obj['methods']['DAZ_Connect'])?'#eeffee':(isset($obj['methods']['Install_Manager'])?'#ffff66':'#ffcccc'))?>;'><?=SafeHTML(implode(" ",isset($obj['methods'])?$obj['methods']:[]))?>
<td width='20'><?=$stats['rel']?></td>
<td width='10'><?=$stats['rate']?></td>
</tr>
<?php } //foreach searchids ?>
</tbody></table>
<?=$pagehtml?>







<script>var idsonpage = <?=json_encode($usedids)?>;</script>

<script>function zpop(prodid){
	mode = "block";
	if (prodid == "none") {
		mode = "none";
	}
	document.getElementById('zpop_prodid').innerHTML = prodid;
	
	//tags will take focus, so load it ASAP
	document.getElementById('zpop_tags').src = "index.php?servetags="+prodid.toString();
	//desc shows the cached image, so it should load fast, so load it next
	setTimeout(function(){
		document.getElementById('zpop_desc').src = "index.php?serve=desc&prodid="+prodid.toString();
	},10);
	//store will have other image previews that aren't cached, so it takes longer to load, so let it load last
	setTimeout(function(){
		document.getElementById('zpop_stre').src = "index.php?serve=store&prodid="+prodid.toString();
	},50);
	
	document.getElementById('zpop_blocker').style.display = mode;
	document.getElementById('zpop_prodid').style.display = mode;
	document.getElementById('zpop_tags').style.display = mode;
	document.getElementById('zpop_desc').style.display = mode;
	document.getElementById('zpop_stre').style.display = mode;
	document.getElementById('zpop_closer').style.display = mode;
	if (prodid != 'none') {
		document.getElementById('zpop_tags').focus();
	}
	document.getElementById('zpop_list').style.display = "none";
	if (mode == "none") {
		var iframes = ['zpop_tags','zpop_desc','zpop_stre','zpop_list'];
		for(var i in iframes) {
			document.getElementById(iframes[i]).src = "about:blank";
		}
	}
} </script>
<script>function selclick(word) {
	debugger;
	document.getElementById("index_"+seltype).value += " "+word+" ";
} var seltype = "";</script>
<script>function listpop(type){
	mode = "block";
	seltype = type;
	
	document.getElementById('zpop_list').src = "index.php?selector="+type;
	
	document.getElementById('zpop_blocker').style.display = mode;
	document.getElementById('zpop_list').style.display = mode;
	document.getElementById('zpop_closer').style.display = mode;
} </script>
<script>function tagup(prodid,tags_str) {
	var tagcell = document.getElementById("tags_"+prodid.toString());
	tagcell.textContent = tags_str;
	setTimeout(function(){tagcell.style.background = "#ffff00";},500);
	setTimeout(function(){tagcell.style.background = "";},1000);
	setTimeout(function(){tagcell.style.background = "#ffff00";},1500);
	setTimeout(function(){tagcell.style.background = "";},2000);
	
	zpop("none");
	if (document.getElementById('autonext').checked && prodid != idsonpage[idsonpage.length-1]) {
		setTimeout(function(){zpop(idsonpage[idsonpage.indexOf(prodid)+1]);},50);
	}
}</script>
<div id='zpop_blocker' style='display:none;position:fixed;top:0px;bottom:0px;left:0px;right:0px;background:#666666;opacity:0.5;' onclick='zpop("none");return false;'></div>
<iframe id='zpop_tags' style='display:none;position:fixed;top:2%;left:2%;width:96%;height:6%;border:3px solid black;background:#ffffff;'></iframe>
<iframe id='zpop_desc' style='display:none;position:fixed;top:10%;left:2%;width:47%;height:88%;border:3px solid black;background:#ffffff;'></iframe>
<iframe id='zpop_stre' style='display:none;position:fixed;top:10%;right:2%;width:47%;height:88%;border:3px solid black;background:#ffffff;'></iframe>
<iframe id='zpop_list' style='display:none;position:fixed;top:2%;right:2%;width:77%;height:96%;border:3px solid black;background:#ffffff;'></iframe>
<div id='zpop_prodid'  style='display:none;position:fixed;top:0px;left:0px;border:1px solid black;background:#eeeeee;padding:5px;'></div>
<a id='zpop_closer'    style='display:none;position:fixed;padding:5px;right:10px;top:10px;border:1px solid black;background:#eeeeee;cursor:pointer;' onclick='zpop("none");return false;'>X</a>


<?php } //if (!$showloader)  ?>





































</body></html>
