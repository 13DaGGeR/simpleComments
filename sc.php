<?php
/*Simple Comments by 13dagger
V 0.1
REQUIREMENTS:
-php 7(did not test on previous, should work)
the aim: 
-replace payd or advertise-driven comment system such as disqus
-simple to install/use
-SEO friendly - abylity to include comments block from php
-short and simple source code
-works without js(who cares?) in include mode
-supports ReCAPTCHA

=============================HOW TO INSTALL:=========================================
1.put sc.php in some acessible folder on your website, f.e. /simpleComments/sc.php
2.either give write permissions to the folder or change SC_DB_FILE constant so dir of database is writable for webserver
3.edit you template:
	3.1.simple include-way(seo friendly):
		<section>
			<?define('SC_INCLUDE_WAY',1);include_once $_SERVER['DOCUMENT_ROOT'].'/simpleComments/sc.php';?>
		</section>
	3.2.JS-way - asynchronous load
		<iframe src="/simpleComments/sc.php?SC_JS=1" frameborder="0" scrolling="no" style="width:100%"
			onload="this.style.height=this.contentWindow.document.body.scrollHeight+'px';" />
	3.3.PRO-way - remove runSC() call, split it contents so newComment,getComments and SC_tplComments 
		will call accordingly to your MVC structure
*/

define('SC_DB_FILE',__DIR__.'/comments.sqlite');#path to your dbfile(need to be writable directory, but not accessable from web)
define('SC_RECAPTCHA_OPEN_KEY','');
define('SC_RECAPTCHA_SECRET_KEY','');

if(defined('SC_INCLUDE_WAY')||isset($_GET['SC_JS']))
	runSC();

# only functions and classes (no execution) further!

function runSC(){#main function (to hide variables if you include this file)
	$isJs=(bool)@$_GET['SC_JS'];
	if($isJs){
		$pu=parse_url($_SERVER['HTTP_REFERER']);
		$uri=$pu['path'].($pu['query']?"?$pu[query]":'');
	}else{
		$uri=@$_SERVER['REQUEST_URI'];
	}
	define('SC_FORM_URI',$uri);
	$SC=new SC_Comment;

	if(!empty($_POST['SC_text'])){
		$ok=true;
		if(SC_RECAPTCHA_OPEN_KEY){
			$resp=@$_POST['g-recaptcha-response'];
			$ip=$_SERVER['REMOTE_ADDR'];
			$ok=checkReCAPTCHA($resp,SC_RECAPTCHA_SECRET_KEY,$ip);
		}
		$text=$_POST['SC_text'];
		$author=$_POST['SC_author']?:'Anonym';
		$uri=$_POST['SC_uri'];
		if($ok) $SC->newComment($uri,$author,$text);
	}
	
	SC_tplComments($SC->getComments($uri),$SC->getLastComments(6),$isJs,SC_RECAPTCHA_OPEN_KEY);
}

class SC_Comment{#comments and db
	private $db;
	function __construct(){
		try{$this->db=new PDO('sqlite:'.SC_DB_FILE);}
		catch(PDOException $e){die("<pre>can't open/create DB file ".SC_DB_FILE.": \n$e");}
		$this->checkDB();
	}
	private function checkDB(){#create tables for new DB
		if(!$this->q('SELECT COUNT(*) FROM sqlite_master WHERE type="table";')->fetch()[0]){
			echo 'creating tables';
			$this->q('CREATE TABLE comment ('.
				'id INTEGER PRIMARY KEY NOT NULL,'.
				'pageUrl TEXT NOT NULL,'.
				'author TEXT NOT NULL,'.
				'text TEXT NOT NULL,'.
				'timestamp INT NOT NULL,'.
				'hidden INT NOT NULL,'.
				'ip TEXT NOT NULL'.
			')');
			$this->q('CREATE TABLE bannedIP ('.
				'id INTEGER PRIMARY KEY NOT NULL,'.
				'ip TEXT NOT NULL'.
			')');
		}
	}
	function getComments($pageUrl){
		$url=$this->db->quote($pageUrl);
		$res=[];
		$q=$this->q("SELECT * FROM comment WHERE pageUrl=$url AND hidden=0 ORDER BY timestamp,id");
		while($d=$q->fetch(PDO::FETCH_OBJ)){
			$res[]=$d;
		}
		return $res;
	}
	function newComment($pageUrl,$author,$text){#save a comment into db
		$ip=$this->db->quote($_SERVER['REMOTE_ADDR']);
		if(!$this->q("SELECT * FROM bannedIP WHERE ip=$ip")->fetch())#if ip is not banned
			$this->q('INSERT INTO comment (pageUrl,author,text,ip,timestamp,hidden) VALUES ('.
				$this->db->quote($pageUrl).','.
				$this->db->quote(strip_tags($author)).','.
				$this->db->quote(strip_tags($text)).','.
				$ip.','.
				time().',0)');
	}
	function hideComment($id,$unhide=0){
		$id=(int)$id;
		$hide=(int)!$unhide;
		$this->q("UPDATE comment SET hidden=$hide WHERE id=$id");
	}
	function getLastComments($num){
		$num=max(3,intval($num));
		$res=[];
		$q=$this->q("SELECT * FROM comment WHERE hidden=0 ORDER BY timestamp DESC,id DESC LIMIT $num");
		while($d=$q->fetch(PDO::FETCH_OBJ)){
			$res[]=$d;
		}
		return $res;
	}
	
	function q($qs){#pdo query with error output
		$q=$this->db->query($qs);
		if($er=@$this->db->errorInfo()[2] or $er=@$q->errorInfo()[2]){
			die("PDO: $qs: $er");
		}
		return $q;
	}
}
function checkReCAPTCHA($response,$secret,$ip){
	$c=curl_init('https://www.google.com/recaptcha/api/siteverify');
	$opts=[
		CURLOPT_POST=>1,
		CURLOPT_POSTFIELDS=>[
			'secret'=>$secret,
			'response'=>$response,
			'remoteip'=>$ip,
		],
		CURLOPT_RETURNTRANSFER=>1,
		CURLOPT_FOLLOWLOCATION=>1,
	];
	curl_setopt_array($c,$opts);
	$res=json_decode(curl_exec($c));
	return $res->success;
}
function SC_tplComments($comments,$last,$isJs,$RCOK){#template, css?>
	<style>
		.SC_holder,.SC_comment{width:100%;margin:auto;color:#444;}
		.SC_comment,.SC_form{position:relative;}
		.SC_author{color:#500;font-weight:bold;}
		.SC_time{color:#777;font-size:.8em;}
		.SC_text{margin-left:1em;margin-bottom:1em;}
		.SC_form_input_holder{width:300px;margin:1px;border:1px solid #999;}
		.SC_form_input_holder > *{width:100%;padding:0;margin:0;border:0;}
		.SC_form_button_holder{width:300px;margin:0;margin-top:-1px;}
		.SC_form_button_holder > input{width:100%;padding:0;margin:0;}
		.SC_lastComment{display:inline-block;margin-right:2em;text-decoration:none;padding:.5em;}
		.SC_lastComment:hover{background-color:#ddd;}
		.SC_buttons{position:absolute;right:0;top:0;}
	</style>
	<?if($RCOK){?><script src='https://www.google.com/recaptcha/api.js' async></script><?}?>
	<hr />
	<section class="SC_holder SC_form">
		<h3>Leave a comment:</h3>
		<form method="POST" action="">
			<div class="SC_form_input_holder"><textarea name="SC_text" placeholder="Write your comment here"></textarea></div>
			<div class="SC_form_input_holder"><input type="text" name="SC_author" placeholder="Name yourself(optional)" /></div>
			<?if($RCOK){?><div class="g-recaptcha" data-sitekey="<?=$RCOK?>"></div><?}?>
			<div class="SC_form_button_holder"><input type="submit" /></div>
			<input type="hidden" name="SC_uri" value="<?=SC_FORM_URI?>">
		</form>
	</section>
	<section class="SC_holder">
		<?foreach($comments as $c){?>
			<div class="SC_comment">
				<div>
					<span class="SC_author"><?=$c->author?></span>
					<span class="SC_time"><?=date('Y-m-d H:i:s',$c->timestamp)?></span>
				</div>
				<div class="SC_text"><?=$c->text?></div>
			</div>
		<?}?>
	</section>
	<?if(count($last)){?>
		<hr />
		<section class="SC_holder">
			<h3>Last comments:</h3>
			<?foreach($last as $c){
				$short=preg_replace('@^(.{0,50}\S+).*$@siu','$1',$c->text);
				if($short!=$c->text)$short.='...';?>
				<a href="<?=$c->pageUrl?>" class="SC_lastComment" rel="nofollow">
					<div>
						<span class="SC_author"><?=$c->author?></span>
						<span class="SC_time"><?=date('Y-m-d H:i:s',$c->timestamp)?></span>
					</div>
					<div class="SC_text"><?=$short?></div>
				</a>
			<?}?>
		</section>
	<?}?>
<?}
