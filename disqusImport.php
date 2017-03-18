<?php
/*
Import comment from disqus
DB must be created (implement comments first)
*/

#db file, same as SC_DB_FILE constant
$dbFile=__DIR__.'/comments.sqlite';
#API Key from https://disqus.com/api/applications/[YOUR_APP_ID]/
$apiKey='';
#disqus short id of your site
$shortId='';

try{$db=new PDO("sqlite:$dbFile");}
catch(PDOException $e){die("<pre>can't open/create DB file $dbFile: \n$e");}

$thread2url=[];

$next='';
do{
	$cursor=$next?"&cursor=$next":'';
	$url="http://disqus.com/api/3.0/forums/listPosts.json?api_key=$apiKey&forum=$shortId$cursor";
	usleep(3600000);#"Basic API accounts are restricted to 1,000 requests per hour"
	$p=file_get_contents($url);
	$res=json_decode($p);
	$next=$res->cursor->next;
	foreach($res->response as $post){
		echo "post $post->id\n";
		$thr=$post->thread;
		if(empty($thread2url[$thr])){
			echo "requesting Url for thread $thr\n";
			$url2="http://disqus.com/api/3.0/threads/details.json?api_key=$apiKey&thread=$thr";
			$p2=file_get_contents($url2);
			usleep(3600000);#"Basic API accounts are restricted to 1,000 requests per hour"
			$res2=json_decode($p2);
			$ln=parse_url($res2->response->link);
			$thread2url[$thr]=$ln['path'].(!empty($ln['query'])?"?$ln[query]":'');
		}

		$com=new stdClass;
		$com->author=$post->author->name;
		$com->timestamp=strtotime($post->createdAt);
		$com->text=$post->raw_message;
		$com->pageUrl=$thread2url[$thr];
		$vs=[];
		foreach($com as $k=>$v){
			$vs[]=$db->quote($v);
		}
		$q=$db->query('INSERT INTO comment ('.implode(',',array_keys((array)$com)).',hidden,ip) VALUES ('.implode(',',$vs).',0,"disqus parser")');
		if($er=@$db->errorInfo()[2] or $er=@$q->errorInfo()[2]){
			die("PDO: $er");
		}
	}
	echo "$next\n";
}while($res->cursor->hasNext);


